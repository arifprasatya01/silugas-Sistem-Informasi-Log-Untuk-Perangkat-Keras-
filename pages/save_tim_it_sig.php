<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
header('Content-Type: application/json');

$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true);

$code = strtoupper(trim($body['code'] ?? ''));
$nama = trim($body['nama'] ?? '');
$sig  = $body['signature'] ?? '';

if (!$code || !$nama || !$sig || !strpos($sig, 'data:image/png;base64,') === 0) {
    echo json_encode(['ok' => false, 'error' => 'Data tidak lengkap.']);
    exit;
}

$stmt = $db->prepare("SELECT id, tim_it_signature_path FROM hardware_submissions WHERE token_code = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$code]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Submission tidak ditemukan.']);
    exit;
}
if (!empty($row['tim_it_signature_path'])) {
    echo json_encode(['ok' => false, 'error' => 'TTD sudah ada.']);
    exit;
}

$dir = __DIR__ . '/../uploads/hardware_signatures/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = $code . '_TIMITS_' . time() . '.png';
$imgdata  = base64_decode(str_replace('data:image/png;base64,', '', $sig));

if (!file_put_contents($dir . $filename, $imgdata)) {
    echo json_encode(['ok' => false, 'error' => 'Gagal simpan file.']);
    exit;
}

$rel = 'uploads/hardware_signatures/' . $filename;
$upd = $db->prepare("UPDATE hardware_submissions SET tim_it_nama = ?, tim_it_signature_path = ? WHERE token_code = ?");
$upd->execute([$nama, $rel, $code]);

echo json_encode(['ok' => true]);