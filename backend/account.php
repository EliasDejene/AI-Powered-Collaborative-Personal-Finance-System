<?php
// ============================================================
// account.php — User Account Settings
// Endpoints:
//   GET  /account.php?action=profile        — get user profile + stats
//   POST /account.php?action=update_profile — update name / email
//   POST /account.php?action=change_password
//   POST /account.php?action=delete_account
// ============================================================
require_once 'config.php';
setJsonHeaders();

$userId = requireAuth();
$action = $_GET['action'] ?? 'profile';
$db     = getDB();

// ── GET: profile + account stats ─────────────────────────────
if ($action === 'profile') {
    $stmt = $db->prepare('SELECT id, first_name, last_name, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) respond(['error' => 'User not found.'], 404);

    // Account stats
    $stmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE user_id = ?");
    $stmt->execute([$userId]); $txCount = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM `groups` WHERE created_by = ?");
    $stmt->execute([$userId]); $groupsCreated = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM group_members WHERE user_id = ?");
    $stmt->execute([$userId]); $groupsJoined = (int) $stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id = ? AND type='income'
         AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())"
    );
    $stmt->execute([$userId]); $monthIncome = (float) $stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE user_id = ? AND type='expense'
         AND MONTH(transaction_date)=MONTH(CURDATE()) AND YEAR(transaction_date)=YEAR(CURDATE())"
    );
    $stmt->execute([$userId]); $monthExpense = (float) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM ai_logs WHERE user_id = ?");
    $stmt->execute([$userId]); $aiCalls = (int) $stmt->fetchColumn();

    respond([
        'user'   => $user,
        'stats'  => [
            'total_transactions' => $txCount,
            'groups_created'     => $groupsCreated,
            'groups_joined'      => $groupsJoined,
            'month_income'       => $monthIncome,
            'month_expense'      => $monthExpense,
            'ai_calls'           => $aiCalls,
            'member_since'       => date('F Y', strtotime($user['created_at'])),
        ]
    ]);
}

// ── POST: update profile ──────────────────────────────────────
if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = getBody();
    $firstName = trim($body['first_name'] ?? '');
    $lastName  = trim($body['last_name']  ?? '');
    $email     = trim($body['email']      ?? '');

    if (!$firstName || !$lastName || !$email) {
        respond(['error' => 'All fields are required.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Invalid email address.'], 400);
    }

    // Check email not taken by another user
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) respond(['error' => 'Email is already in use by another account.'], 409);

    $db->prepare('UPDATE users SET first_name=?, last_name=?, email=? WHERE id=?')
       ->execute([$firstName, $lastName, $email, $userId]);

    // Update session name
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;

    respond(['message' => 'Profile updated successfully.', 'user_name' => "$firstName $lastName"]);
}

// ── POST: change password ─────────────────────────────────────
if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body        = getBody();
    $current     = $body['current_password'] ?? '';
    $newPass     = $body['new_password']     ?? '';
    $confirmPass = $body['confirm_password'] ?? '';

    if (!$current || !$newPass || !$confirmPass) {
        respond(['error' => 'All password fields are required.'], 400);
    }
    if (strlen($newPass) < 6) {
        respond(['error' => 'New password must be at least 6 characters.'], 400);
    }
    if ($newPass !== $confirmPass) {
        respond(['error' => 'New passwords do not match.'], 400);
    }

    // Verify current password
    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!password_verify($current, $user['password_hash'])) {
        respond(['error' => 'Current password is incorrect.'], 401);
    }

    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')
       ->execute([password_hash($newPass, PASSWORD_BCRYPT), $userId]);

    respond(['message' => 'Password changed successfully.']);
}

// ── POST: delete account ──────────────────────────────────────
if ($action === 'delete_account' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body     = getBody();
    $password = $body['password'] ?? '';

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!password_verify($password, $user['password_hash'])) {
        respond(['error' => 'Incorrect password. Account not deleted.'], 401);
    }

    // Cascading deletes handle transactions, budgets, groups, logs
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    session_destroy();
    respond(['message' => 'Account deleted.']);
}

respond(['error' => 'Unknown action.'], 400);