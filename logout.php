<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$db = getDB();

// Clear DB
$db->prepare("UPDATE users SET is_logged_in = 0, device_token = NULL WHERE id = ?")
   ->execute([$user['id']]);

// Clear session
$_SESSION = [];
session_destroy();

// Clear cookie
setcookie('device_token', '', ['expires' => time()-3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

header('Location: ' . APP_URL . '/index.php');
exit;