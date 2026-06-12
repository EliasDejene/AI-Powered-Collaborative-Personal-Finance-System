<?php
// ============================================================
// auth.php — Login & Signup
// Endpoints:
//   POST /auth.php?action=signup
//   POST /auth.php?action=login
//   POST /auth.php?action=logout
//   GET  /auth.php?action=me
// ============================================================
require_once 'config.php';
setJsonHeaders();
session_start();

$action = $_GET['action'] ?? '';
$body   = getBody();

// ── SIGNUP ───────────────────────────────────────────────────
if ($action === 'signup') {
    $firstName = trim($body['first_name'] ?? '');
    $lastName  = trim($body['last_name']  ?? '');
    $email     = trim($body['email']      ?? '');
    $password  = $body['password']        ?? '';

    // Basic validation
    if (!$firstName || !$lastName || !$email || !$password) {
        respond(['error' => 'All fields are required.'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Invalid email address.'], 400);
    }
    if (strlen($password) < 6) {
        respond(['error' => 'Password must be at least 6 characters.'], 400);
    }

    $db = getDB();

    // Check duplicate email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        respond(['error' => 'Email is already registered.'], 409);
    }

    // Insert new user
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$firstName, $lastName, $email, $hash]);
    $userId = $db->lastInsertId();

    // Auto-login after signup
    $_SESSION['user_id']   = $userId;
    $_SESSION['user_name'] = $firstName . ' ' . $lastName;

    respond([
        'message'   => 'Account created successfully.',
        'user_id'   => $userId,
        'user_name' => $firstName . ' ' . $lastName,
    ], 201);
}

// ── LOGIN ────────────────────────────────────────────────────
if ($action === 'login') {
    $email    = trim($body['email']    ?? '');
    $password = $body['password']      ?? '';

    if (!$email || !$password) {
        respond(['error' => 'Email and password are required.'], 400);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, first_name, last_name, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(['error' => 'Invalid email or password.'], 401);
    }

    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];

    respond([
        'message'   => 'Login successful.',
        'user_id'   => $user['id'],
        'user_name' => $user['first_name'] . ' ' . $user['last_name'],
    ]);
}

// ── LOGOUT ───────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    respond(['message' => 'Logged out.']);
}

// ── ME (get current user) ────────────────────────────────────
if ($action === 'me') {
    $userId = requireAuth();
    $db     = getDB();
    $stmt   = $db->prepare('SELECT id, first_name, last_name, email, created_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    respond($user ?: ['error' => 'User not found'], $user ? 200 : 404);
}

respond(['error' => 'Unknown action. Use ?action=signup|login|logout|me'], 400);
