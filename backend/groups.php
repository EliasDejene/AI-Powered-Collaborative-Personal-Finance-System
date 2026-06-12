<?php
// ============================================================
// groups.php — Collaborative Financial Groups
// Endpoints:
//   GET    /groups.php                 — list my groups
//   POST   /groups.php                 — create new group
//   GET    /groups.php?id=N            — get one group's details
//   POST   /groups.php?id=N&action=invite  — invite member
//   POST   /groups.php?id=N&action=tx      — add group transaction
//   GET    /groups.php?id=N&action=tx      — list group transactions
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = (int) ($_GET['id']     ?? 0);
$action = $_GET['action'] ?? '';

// ── GET: list all groups the user belongs to ─────────────────
if ($method === 'GET' && !$id) {
    $stmt = $db->prepare(
        'SELECT g.*, gm.role,
                (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count
         FROM `groups` g
         JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
         ORDER BY g.created_at DESC'
    );
    $stmt->execute([$userId]);
    respond(['groups' => $stmt->fetchAll()]);
}

// ── POST: create new group ────────────────────────────────────
if ($method === 'POST' && !$id) {
    $body = getBody();
    $name = trim($body['name']        ?? '');
    $desc = trim($body['description'] ?? '');
    $budget = (float) ($body['total_budget'] ?? 0);

    if (!$name) respond(['error' => 'Group name is required.'], 400);

    $db->prepare(
        'INSERT INTO `groups` (name, description, created_by, total_budget) VALUES (?, ?, ?, ?)'
    )->execute([$name, $desc, $userId, $budget]);

    $groupId = $db->lastInsertId();

    // Creator automatically becomes admin member
    $db->prepare(
        'INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)'
    )->execute([$groupId, $userId, 'admin']);

    respond(['message' => 'Group created.', 'group_id' => $groupId], 201);
}

// ── GET: single group details ─────────────────────────────────
if ($method === 'GET' && $id && !$action) {
    // Check membership
    $stmt = $db->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $membership = $stmt->fetch();
    if (!$membership) respond(['error' => 'Group not found or access denied.'], 404);

    // Group info
    $stmt = $db->prepare('SELECT * FROM `groups` WHERE id = ?');
    $stmt->execute([$id]);
    $group = $stmt->fetch();

    // Members list
    $stmt = $db->prepare(
        'SELECT u.id, u.first_name, u.last_name, u.email, gm.role
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?'
    );
    $stmt->execute([$id]);
    $members = $stmt->fetchAll();

    respond(['group' => $group, 'members' => $members, 'my_role' => $membership['role']]);
}

// ── POST: invite member by email ──────────────────────────────
if ($method === 'POST' && $id && $action === 'invite') {
    // Must be admin
    $stmt = $db->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $my = $stmt->fetch();
    if (!$my || $my['role'] !== 'admin') respond(['error' => 'Only admins can invite members.'], 403);

    $body  = getBody();
    $email = trim($body['email'] ?? '');
    $role  = $body['role'] ?? 'contributor';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'Invalid email.'], 400);
    if (!in_array($role, ['admin', 'contributor', 'viewer'])) $role = 'contributor';

    // Find user by email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $invited = $stmt->fetch();
    if (!$invited) respond(['error' => 'No user found with that email.'], 404);

    $invitedId = $invited['id'];

    // Check not already a member
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$id, $invitedId]);
    if ($stmt->fetch()) respond(['error' => 'User is already in this group.'], 409);

    $db->prepare('INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, ?)')
       ->execute([$id, $invitedId, $role]);

    respond(['message' => 'Member added to group.']);
}

// ── POST: add group transaction ────────────────────────────────
if ($method === 'POST' && $id && $action === 'tx') {
    $stmt = $db->prepare('SELECT role FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    $my = $stmt->fetch();
    if (!$my || $my['role'] === 'viewer') respond(['error' => 'Viewers cannot add transactions.'], 403);

    $body   = getBody();
    $desc   = trim($body['description']      ?? '');
    $amount = (float) ($body['amount']       ?? 0);
    $cat    = $body['category']              ?? 'Other';
    $date   = $body['date']                  ?? date('Y-m-d');

    if (!$desc || $amount <= 0) respond(['error' => 'Description and positive amount required.'], 400);

    $db->prepare(
        'INSERT INTO group_transactions (group_id, added_by, description, amount, category, transaction_date)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$id, $userId, $desc, $amount, $cat, $date]);

    respond(['message' => 'Group transaction recorded.'], 201);
}

// ── GET: list group transactions ───────────────────────────────
if ($method === 'GET' && $id && $action === 'tx') {
    $stmt = $db->prepare('SELECT id FROM group_members WHERE group_id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) respond(['error' => 'Access denied.'], 403);

    $stmt = $db->prepare(
        'SELECT gt.*, CONCAT(u.first_name, " ", u.last_name) AS added_by_name
         FROM group_transactions gt
         JOIN users u ON u.id = gt.added_by
         WHERE gt.group_id = ?
         ORDER BY gt.transaction_date DESC'
    );
    $stmt->execute([$id]);
    respond(['transactions' => $stmt->fetchAll()]);
}

respond(['error' => 'Invalid request.'], 400);
