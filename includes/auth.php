<?php
// includes/auth.php
require_once __DIR__ . '/../config/db.php';

// Secure session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── CSRF ─────────────────────────────────────────────
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

// ── SANITIZE ─────────────────────────────────────────
function clean($val) {
    return htmlspecialchars(trim((string)$val), ENT_QUOTES, 'UTF-8');
}

function cleanInt($val) {
    return (int) $val;
}

// ── AUTH CHECK ────────────────────────────────────────
function requireLogin() {
    // Cek session
    if (!empty($_SESSION['user_id'])) {
        return true;
    }
    // Cek remember token dari cookie
    if (!empty($_COOKIE['device_token'])) {
        $token = $_COOKIE['device_token'];
        $db = getDB();
        $stmt = $db->prepare("SELECT id, name, username, role FROM users WHERE device_token = ? AND is_logged_in = 1 LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            return true;
        }
    }
    // Redirect ke login
    header('Location: ' . APP_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

function requireAdmin() {
    requireLogin();
    if ($_SESSION['user_role'] !== 'admin') {
        http_response_code(403);
        include __DIR__ . '/../pages/403.php';
        exit;
    }
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function currentUser() {
    return [
        'id'   => $_SESSION['user_id']   ?? null,
        'name' => $_SESSION['user_name'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];
}
function isAdmin() {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// ── BRUTE FORCE PROTECTION ────────────────────────────
function checkLoginAttempts($username) {
    $db = getDB();
    $stmt = $db->prepare("SELECT login_attempts, locked_until FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) return true;
    if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
        $remaining = ceil((strtotime($row['locked_until']) - time()) / 60);
        return "Akun terkunci. Coba lagi dalam {$remaining} menit.";
    }
    return true;
}

function recordFailedLogin($username) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, login_attempts FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) return;
    $attempts = $row['login_attempts'] + 1;
    $locked_until = null;
    if ($attempts >= 5) {
        $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $attempts = 0;
    }
    $db->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?")
       ->execute([$attempts, $locked_until, $row['id']]);
}

function clearLoginAttempts($userId) {
    getDB()->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?")
           ->execute([$userId]);
}