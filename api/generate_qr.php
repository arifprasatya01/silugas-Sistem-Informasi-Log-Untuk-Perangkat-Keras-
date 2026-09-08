<?php
// api/generate_qr.php
// Generates QR code as PNG using Google Charts API as fallback
// or outputs redirect to QR service

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$code = trim($_GET['code'] ?? '');
if (!$code) { http_response_code(400); exit; }

// Build the URL that QR should point to
$url = APP_URL . '/asset_detail?code=' . urlencode($code);

// Use QR Server API (no API key needed, free)
$qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($url) . '&format=png&ecc=M&margin=2';

header('Location: ' . $qr_url);
exit;
