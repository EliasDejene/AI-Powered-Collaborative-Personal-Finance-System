<?php
// ============================================================
// transactions.php — Transaction CRUD
// Endpoints:
//   GET    /transactions.php          — list all for current user
//   POST   /transactions.php          — create new transaction
//   PUT    /transactions.php?id=N     — update transaction N
//   DELETE /transactions.php?id=N     — delete transaction N
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// ── GET — List transactions ──────────────────────────────────
if ($method === 'GET') {
    $where  = 'WHERE user_id = ?';
    $params = [$userId];

    // Optional filters: ?type=expense&category=Food&month=4&year=2026
    if (!empty($_GET['type'])) {
        $where    .= ' AND type = ?';
        $params[]  = $_GET['type'];
    }
    if (!empty($_GET['category'])) {
        $where    .= ' AND category = ?';
        $params[]  = $_GET['category'];
    }
    if (!empty($_GET['month']) && !empty($_GET['year'])) {
        $where    .= ' AND MONTH(transaction_date) = ? AND YEAR(transaction_date) = ?';
        $params[]  = (int) $_GET['month'];
        $params[]  = (int) $_GET['year'];
    }

    $stmt = $db->prepare("SELECT * FROM transactions $where ORDER BY transaction_date DESC LIMIT 200");
    $stmt->execute($params);
    respond(['transactions' => $stmt->fetchAll()]);
}

// ── POST — Create transaction ────────────────────────────────
if ($method === 'POST') {
    $body        = getBody();
    $description = trim($body['description']      ?? '');
    $amount      = (float) ($body['amount']        ?? 0);
    $type        = $body['type']                   ?? 'expense';   // income | expense
    $category    = trim($body['category']          ?? 'Other');
    $date        = $body['date']                   ?? date('Y-m-d');
    $aiSuggested = (int) ($body['ai_suggested']    ?? 0);

    if (!$description || $amount <= 0) {
        respond(['error' => 'Description and a positive amount are required.'], 400);
    }
    if (!in_array($type, ['income', 'expense'])) {
        respond(['error' => 'Type must be income or expense.'], 400);
    }

    $stmt = $db->prepare(
        'INSERT INTO transactions
         (user_id, description, amount, type, category, ai_suggested, transaction_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $description, $amount, $type, $category, $aiSuggested, $date]);
    $newId = $db->lastInsertId();

    // Update actual_spent in budgets table for this month
    if ($type === 'expense') {
        $month = (int) date('m', strtotime($date));
        $year  = (int) date('Y', strtotime($date));
        $db->prepare(
            'UPDATE budgets SET actual_spent = actual_spent + ?
             WHERE user_id = ? AND category = ? AND month = ? AND year = ?'
        )->execute([$amount, $userId, $category, $month, $year]);
    }

    respond(['message' => 'Transaction saved.', 'id' => $newId], 201);
}

// ── PUT — Update transaction ─────────────────────────────────
if ($method === 'PUT') {
    $id   = (int) ($_GET['id'] ?? 0);
    $body = getBody();

    if (!$id) respond(['error' => 'Transaction ID required.'], 400);

    // Verify ownership
    $stmt = $db->prepare('SELECT id FROM transactions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) respond(['error' => 'Transaction not found.'], 404);

    $fields = [];
    $params = [];
    foreach (['description', 'category', 'type'] as $col) {
        if (isset($body[$col])) { $fields[] = "$col = ?"; $params[] = $body[$col]; }
    }
    if (isset($body['amount'])) { $fields[] = 'amount = ?'; $params[] = (float) $body['amount']; }
    if (isset($body['date']))   { $fields[] = 'transaction_date = ?'; $params[] = $body['date']; }

    if (empty($fields)) respond(['error' => 'Nothing to update.'], 400);

    $params[] = $id;
    $db->prepare('UPDATE transactions SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    respond(['message' => 'Transaction updated.']);
}

// ── DELETE — Remove transaction ──────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'Transaction ID required.'], 400);

    $stmt = $db->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);

    if ($stmt->rowCount() === 0) respond(['error' => 'Transaction not found.'], 404);
    respond(['message' => 'Transaction deleted.']);
}

respond(['error' => 'Method not allowed.'], 405);
