<?php
// Tes konektivitas dari server SIMRS (.80) ke server lokal (.55) port 80.
// Taruh sementara di hardware/tes_koneksi.php, buka di browser, lihat hasilnya.
// HAPUS file ini setelah selesai tes (jangan dibiarkan nangkring permanen).

header('Content-Type: text/plain');

$url = 'http://10.100.1.55/wa_message_bailey_sync/sync.php';

echo "Testing koneksi ke: $url\n\n";

$start = microtime(true);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
$time     = round(microtime(true) - $start, 2);
curl_close($ch);

echo "Waktu: {$time} detik\n";
echo "HTTP Code: $httpCode\n";
echo "Curl Error: " . ($err ?: '(tidak ada)') . "\n\n";
echo "Response:\n";
echo $response !== false ? $response : '(gagal ambil response)';