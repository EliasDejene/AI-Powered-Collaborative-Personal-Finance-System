<?php
// ============================================================
// ai-budget.php — AI Budget Recommendation (Sequence Diagram 3.3)
//
// Flow (matches the report's design):
//   1. Fetch user's last 3 months of spending from DB
//   2. Send spending summary to Claude AI
//   3. Claude returns category-by-category budget advice
//   4. Save recommended budgets to the budgets table
//   5. Return structured JSON to the frontend
//
// Endpoints:
//   POST /ai-budget.php?action=generate   — generate new AI budget
//   POST /ai-budget.php?action=apply      — save generated budget to DB
//   GET  /ai-budget.php?action=current    — get this month's budget
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();
$action = $_GET['action'] ?? 'generate';
$db     = getDB();

// ── GET: current month's budget ───────────────────────────────
if ($action === 'current') {
    $month = (int) date('m');
    $year  = (int) date('Y');
    $stmt  = $db->prepare(
        'SELECT category, recommended_amount, actual_spent, ai_generated
         FROM budgets WHERE user_id = ? AND month = ? AND year = ?'
    );
    $stmt->execute([$userId, $month, $year]);
    respond(['budgets' => $stmt->fetchAll(), 'month' => $month, 'year' => $year]);
}

// ── POST: generate AI budget ──────────────────────────────────
if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Collect spending for the last 3 months from DB
    $stmt = $db->prepare(
        "SELECT category,
                SUM(amount)                        AS total_spent,
                COUNT(*)                           AS tx_count,
                MONTH(transaction_date)            AS month,
                YEAR(transaction_date)             AS year
         FROM transactions
         WHERE user_id = ?
           AND type = 'expense'
           AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
         GROUP BY category, month, year
         ORDER BY year DESC, month DESC"
    );
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll();

    // 2. Also get total income for context
    $incomeStmt = $db->prepare(
        "SELECT SUM(amount) AS total_income
         FROM transactions
         WHERE user_id = ? AND type = 'income'
           AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)"
    );
    $incomeStmt->execute([$userId]);
    $income = (float) ($incomeStmt->fetchColumn() ?: 0);

    // 3. Format the spending summary for Claude
    $summaryLines = [];
    foreach ($history as $row) {
        $summaryLines[] = sprintf(
            "  - %s/%s | %s | %.2f ETB spent (%d transactions)",
            $row['month'], $row['year'], $row['category'],
            $row['total_spent'], $row['tx_count']
        );
    }
    $summary = $summaryLines
        ? implode("\n", $summaryLines)
        : "  No expense history found. User is new.";

    // 4. Build prompts for Claude
    $systemPrompt = <<<PROMPT
You are a personal finance AI for an Ethiopian budgeting app.
Your job is to analyze a user's spending history and return a JSON budget plan.

IMPORTANT RULES:
- Currency is ETB (Ethiopian Birr).
- Return ONLY a valid JSON array — no extra text, no markdown fences.
- Each item in the array must have these exact keys:
    category, recommended_amount, previous_avg, status, tip
- status must be one of: "Optimized", "Action Required", "Stable", "New Insight"
- Keep tips short and practical (1–2 sentences max).
- recommended_amount must be a number (no currency symbols).
PROMPT;

    $userMessage = <<<MSG
Monthly income: {$income} ETB

Spending history (last 3 months):
{$summary}

Generate a budget recommendation for next month.
Return a JSON array only. Example format:
[
  {
    "category": "Food & Dining",
    "recommended_amount": 6000,
    "previous_avg": 8500,
    "status": "Optimized",
    "tip": "You spent 30% less on dining last month. Keep it up!"
  }
]
MSG;

    try {
        $aiResponse = callAI($systemPrompt, $userMessage);

        // Strip markdown fences if Claude accidentally adds them
        $aiResponse = preg_replace('/```json|```/', '', $aiResponse);
        $budgets    = json_decode(trim($aiResponse), true);

        if (!is_array($budgets)) {
            throw new RuntimeException('Claude returned invalid JSON: ' . $aiResponse);
        }

        // Log this AI call
        $db->prepare(
            'INSERT INTO ai_logs (user_id, action, input_text, ai_response) VALUES (?, ?, ?, ?)'
        )->execute([$userId, 'budget_recommend', $summary, $aiResponse]);

        respond([
            'budgets'        => $budgets,
            'monthly_income' => $income,
            'generated_at'   => date('Y-m-d H:i:s'),
            'source'         => 'gemini-ai',
        ]);

    } catch (RuntimeException $e) {
        // Fallback: simple percentage-based budget
        $fallbackBudgets = [
            ['category' => 'Housing',        'recommended_amount' => round($income * 0.30), 'previous_avg' => 0, 'status' => 'Stable',          'tip' => 'Standard 30% housing allocation.'],
            ['category' => 'Food & Dining',  'recommended_amount' => round($income * 0.15), 'previous_avg' => 0, 'status' => 'Stable',          'tip' => 'Aim to keep food costs at 15% of income.'],
            ['category' => 'Transportation', 'recommended_amount' => round($income * 0.10), 'previous_avg' => 0, 'status' => 'Stable',          'tip' => 'Transportation typically takes 10% of income.'],
            ['category' => 'Utilities',      'recommended_amount' => round($income * 0.08), 'previous_avg' => 0, 'status' => 'Stable',          'tip' => 'Keep utilities to 8% of income.'],
            ['category' => 'Entertainment',  'recommended_amount' => round($income * 0.05), 'previous_avg' => 0, 'status' => 'Action Required', 'tip' => 'Limit fun spending to 5% of income.'],
            ['category' => 'Savings',        'recommended_amount' => round($income * 0.20), 'previous_avg' => 0, 'status' => 'New Insight',     'tip' => 'Save at least 20% of income each month.'],
        ];

        respond([
            'budgets'        => $fallbackBudgets,
            'monthly_income' => $income,
            'generated_at'   => date('Y-m-d H:i:s'),
            'source'         => 'fallback-percentage',
            'note'           => 'AI unavailable. Used standard % allocations.',
        ]);
    }
}

// ── POST: apply budget (save to DB) ──────────────────────────
if ($action === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = getBody();
    $budgets = $body['budgets'] ?? [];
    $month   = (int) date('m');
    $year    = (int) date('Y');

    if (empty($budgets)) {
        respond(['error' => 'No budget data provided.'], 400);
    }

    $stmt = $db->prepare(
        'INSERT INTO budgets (user_id, category, recommended_amount, month, year, ai_generated)
         VALUES (?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE recommended_amount = VALUES(recommended_amount), ai_generated = 1'
    );

    foreach ($budgets as $b) {
        $stmt->execute([
            $userId,
            $b['category']           ?? 'Other',
            (float) ($b['recommended_amount'] ?? 0),
            $month,
            $year,
        ]);
    }

    respond(['message' => 'AI budget applied and saved.', 'month' => $month, 'year' => $year]);
}

respond(['error' => 'Use ?action=generate (POST) | apply (POST) | current (GET)'], 400);
