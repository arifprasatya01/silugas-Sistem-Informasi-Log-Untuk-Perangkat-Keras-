<?php
// ================== KONFIGURASI ==================
$apiUrl = 'http://10.100.1.55:8082/api_sync.php';
$apiKey = 'RSUDk4r4w4ng';

$mysqlHost = 'localhost';
$mysqlUser = 'rsud_simrs';      // GANTI sesuai db website kamu
$mysqlPass = 'RSUDk4r4w4ng';      // GANTI sesuai db website kamu
$mysqlDb   = 'rsud_simrs';   // GANTI sesuai db website kamu
// ===================================================

// 1. Ambil data dari API lokal
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . $apiKey]);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    die("Gagal konek ke API: $curlError");
}
if ($httpCode !== 200) {
    die("API merespon dengan error. HTTP Code: $httpCode. Response: $response");
}

$data = json_decode($response, true);
if ($data === null) {
    die("Gagal decode JSON dari API.");
}

// 2. Koneksi ke MySQL website
$mysqli = new mysqli($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
if ($mysqli->connect_error) {
    die('Koneksi MySQL gagal: ' . $mysqli->connect_error);
}

// 3. Sync data (insert baru / update yang sudah ada)
$stmt = $mysqli->prepare("
    INSERT INTO pesan_masuk 
    (id, whatsapp_id, pengirim, isi_pesan, tgl_lapor, nomor_pengirim, status_selesai, kd_divisi, tgl_proses, tgl_selesai, attachments, kd_unit_pelapor, chat_id, kd_user_proses, kd_user_done, kd_user_up, tgl_up, status_hapus)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        whatsapp_id=VALUES(whatsapp_id),
        pengirim=VALUES(pengirim),
        isi_pesan=VALUES(isi_pesan),
        tgl_lapor=VALUES(tgl_lapor),
        nomor_pengirim=VALUES(nomor_pengirim),
        status_selesai=VALUES(status_selesai),
        kd_divisi=VALUES(kd_divisi),
        tgl_proses=VALUES(tgl_proses),
        tgl_selesai=VALUES(tgl_selesai),
        attachments=VALUES(attachments),
        kd_unit_pelapor=VALUES(kd_unit_pelapor),
        chat_id=VALUES(chat_id),
        kd_user_proses=VALUES(kd_user_proses),
        kd_user_done=VALUES(kd_user_done),
        kd_user_up=VALUES(kd_user_up),
        tgl_up=VALUES(tgl_up),
        status_hapus=VALUES(status_hapus)
");

if (!$stmt) {
    die('Prepare statement gagal: ' . $mysqli->error);
}

$inserted = 0;
foreach ($data as $row) {
    $stmt->bind_param(
        "sssssssssssssssss",
        $row['id'], $row['whatsapp_id'], $row['pengirim'], $row['isi_pesan'],
        $row['tgl_lapor'], $row['nomor_pengirim'], $row['status_selesai'], $row['kd_divisi'],
        $row['tgl_proses'], $row['tgl_selesai'], $row['attachments'], $row['kd_unit_pelapor'],
        $row['chat_id'], $row['kd_user_proses'], $row['kd_user_done'], $row['kd_user_up'],
        $row['tgl_up'], $row['status_hapus']
    );
    if ($stmt->execute()) {
        $inserted++;
    }
}

$stmt->close();
$mysqli->close();

echo "Sync selesai. Total baris diproses: $inserted dari " . count($data) . " data.";