<?php
// ============================================================
// ai-categorize.php — AI Expense Categorization (UC-01)
// Implements: Sequence Diagram 3.2 from the project report
//
// Flow (matches the report's use case):
//   1. Frontend sends transaction description
//   2. This file calls Claude AI with a structured prompt
//   3. Claude returns the most appropriate category
//   4. We return it to the frontend as a JSON suggestion
//
// Endpoint:
//   POST /ai-categorize.php
//   Body: { "description": "Lunch at Kaldi's" }
//   Returns: { "category": "Food & Dining", "confidence": "high" }
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'POST method required.'], 405);
}

$body        = getBody();
$description = trim($body['description'] ?? '');

if (!$description) {
    respond(['error' => 'Transaction description is required.'], 400);
}

// ── Allowed categories (must match what the HTML dropdowns show) ──
$categories = [
    'Food & Dining',
    'Transportation',
    'Utilities',
    'Entertainment',
    'Health',
    'Housing',
    'Education',
    'Savings',
    'Income',
    'Other',
];
$categoryList = implode(', ', $categories);

// ── System prompt (tell Claude to ONLY return a category) ────
$systemPrompt = <<<PROMPT
You are a financial transaction categorizer for an Ethiopian personal finance app.
Your only job is to read a transaction description and return the single best
category from this list: $categoryList

Rules:
- Reply with ONLY the category name, exactly as written in the list.
- Do not add explanation, punctuation, or extra text.
- Default to "Other" if truly ambiguous.
PROMPT;

// ── User message ─────────────────────────────────────────────
$userMessage = "Categorize this transaction: \"$description\"";

try {
    $aiCategory = trim(callAI($systemPrompt, $userMessage));

    // Validate Claude returned a known category
    if (!in_array($aiCategory, $categories)) {
        $aiCategory = 'Other';
    }

    // Log the AI call for audit trail (Activity Diagram 3.4)
    $db = getDB();
    $db->prepare(
        'INSERT INTO ai_logs (user_id, action, input_text, ai_response) VALUES (?, ?, ?, ?)'
    )->execute([$userId, 'categorize', $description, $aiCategory]);

    respond([
        'category'   => $aiCategory,
        'confidence' => 'high',
        'source'     => 'gemini-ai',
    ]);

} catch (RuntimeException $e) {
    // Fallback: simple keyword matching if AI is unavailable
    $text     = strtolower($description);
    $fallback = 'Other';

    if (preg_match('/food|lunch|dinner|breakfast|cafe|restaurant|kaldi|burger|pizza/', $text))
        $fallback = 'Food & Dining';
    elseif (preg_match('/taxi|uber|fuel|petrol|bus|ride|transport/', $text))
        $fallback = 'Transportation';
    elseif (preg_match('/netflix|cinema|game|movie|entertainment|subscription/', $text))
        $fallback = 'Entertainment';
    elseif (preg_match('/electricity|water|internet|utility|ethio telecom/', $text))
        $fallback = 'Utilities';
    elseif (preg_match('/hospital|doctor|pharmacy|health|medicine/', $text))
        $fallback = 'Health';
    elseif (preg_match('/rent|housing|apartment|house/', $text))
        $fallback = 'Housing';
    elseif (preg_match('/salary|income|wage|bonus|payment received/', $text))
        $fallback = 'Income';

    respond([
        'category'   => $fallback,
        'confidence' => 'low',
        'source'     => 'keyword-fallback',
        'note'       => 'AI unavailable. Used keyword matching.',
    ]);
}
