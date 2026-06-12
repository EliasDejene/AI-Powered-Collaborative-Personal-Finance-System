<?php
// ============================================================
// config.php — Database connection & shared helpers
// AI: Google Gemini API (Free tier — gemini-1.5-flash)
// Get your free key at: https://aistudio.google.com/app/apikey
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'finance_db');
define('DB_USER', 'root');        // ← change to your MySQL username
define('DB_PASS', '');            // ← change to your MySQL password
define('GEMINI_API_KEY', 'AIzaSyDNoC6ApbyHE9Asf3V4zfnY5708N2Lzfkg'); // ← paste your free Gemini key

// Gemini model (free tier):
//   gemini-1.5-flash → 15 req/min, 1,500/day (recommended)
//   gemini-1.5-pro   → 2 req/min,  50/day
define('GEMINI_MODEL', 'gemini-1.5-flash');

// ── Database connection (PDO) ────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ── CORS & JSON headers (call at top of every API file) ─────
function setJsonHeaders(): void {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
}

// ── Send JSON response and exit ──────────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// ── Get authenticated user_id from session ───────────────────
function requireAuth(): int {
    session_start();
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Unauthorized. Please login.'], 401);
    }
    return (int) $_SESSION['user_id'];
}

// ── Read JSON body from request ──────────────────────────────
function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Call Google Gemini API (FREE) ────────────────────────────
// $systemPrompt : tells Gemini its role and rules
// $userMessage  : the actual question / data to process
// Returns the text reply, or throws RuntimeException on failure
//
// Free quota (gemini-1.5-flash): 15 req/min, 1,500 req/day
function callAI(string $systemPrompt, string $userMessage): string {
    $model  = GEMINI_MODEL;
    $apiKey = GEMINI_API_KEY;
    $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $payload = json_encode([
        'system_instruction' => [
            'parts' => [['text' => $systemPrompt]]
        ],
        'contents' => [
            [
                'role'  => 'user',
                'parts' => [['text' => $userMessage]]
            ]
        ],
        'generationConfig' => [
            'temperature'     => 0.2,
            'maxOutputTokens' => 512,
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $err = json_decode($response, true);
        $msg = $err['error']['message'] ?? $response;
        throw new RuntimeException("Gemini API error ($httpCode): $msg");
    }

    $data = json_decode($response, true);
    return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
}
