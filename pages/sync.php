<?php
// ============================================================
// pages/sync.php — dipanggil lewat POST dari tombol "Sinkronisasi"
// di logbook.php (route /sync sudah di-rewrite ke file ini via .htaccess)
//
// Alur baru (server lokal TIDAK push ke SIMRS, SIMRS yang narik):
//   1) curl GET ke server lokal 10.100.1.220 → terima JSON rows
//   2) Upsert rows ke tabel `tiket` MySQL lokal
//   3) Redirect ke /logbook dengan hasil
//
// Kenapa arahnya SIMRS yang curl ke lokal?
// Server lokal (10.100.1.220) tidak bisa keluar ke internet (firewall),
// tapi SIMRS (simrs.rsudkarawang.com) bisa konek ke jaringan lokal RS.
// ============================================================

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/logbook');
    exit;
}

csrf_verify();

// ================== KONFIGURASI ==================
$localUrl = 'http://10.100.1.220/wa_message_bailey_sync/sync.php';
$apiKey   = 'RSUDk4r4w4ng'; // harus sama dengan $apiKey di sync.php lokal
// ===================================================

// -- 1) Curl ke server lokal, kirim api_key via query string --
$ch = curl_init($localUrl . '?api_key=' . urlencode($apiKey));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode("Gagal hubungi server lokal (10.100.1.220): $curlError"));
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode("Response tidak valid dari server lokal (HTTP $httpCode)"));
    exit;
}
if (isset($data['error'])) {
    header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode($data['error']));
    exit;
}

$rows = $data['data'] ?? [];
if (!is_array($rows)) {
    header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode('Format data tidak valid dari server lokal'));
    exit;
}

// -- 2) Upsert ke tabel tiket MySQL lokal --
try {
    $db = getDB();

    // Buat tabel kalau belum ada
    try {
        ensureTiketTable($db);
    } catch (Throwable $e) {
        try {
            $db->query("SELECT 1 FROM tiket LIMIT 1");
        } catch (Throwable $e2) {
            header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode(
                'Tabel `tiket` belum ada dan tidak bisa dibuat otomatis. Buat manual dulu via phpMyAdmin.'
            ));
            exit;
        }
    }

    $upsertStmt = $db->prepare("
        INSERT INTO tiket
            (source_id, ruangan, nama_user, nomor_pengirim, tanggal_lapor, tanggal_selesai,
             subject, masalah, solusi, petugas_menyelesaikan, keyword_matched, status, kategori)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            ruangan               = VALUES(ruangan),
            nama_user             = VALUES(nama_user),
            nomor_pengirim        = VALUES(nomor_pengirim),
            tanggal_lapor         = VALUES(tanggal_lapor),
            tanggal_selesai       = VALUES(tanggal_selesai),
            subject               = VALUES(subject),
            masalah               = VALUES(masalah),
            solusi                = VALUES(solusi),
            petugas_menyelesaikan = VALUES(petugas_menyelesaikan),
            keyword_matched       = VALUES(keyword_matched),
            status                = VALUES(status),
            kategori              = VALUES(kategori)
    ");

    $inserted  = 0;
    $updated   = 0;
    $unchanged = 0;

    foreach ($rows as $r) {
        $sourceId = $r['id'] ?? null;
        if (!$sourceId) continue;

        $ruangan              = $r['nama_unit'] ?: '-';
        $namaUser             = $r['pengirim']  ?: '-';
        $nomorPengirim        = $r['nomor_pengirim'] ?? null;
        $tanggalLapor         = !empty($r['tgl_lapor'])   ? date('Y-m-d H:i:s', strtotime($r['tgl_lapor']))   : null;
        $tanggalSelesai       = !empty($r['tgl_selesai']) ? date('Y-m-d H:i:s', strtotime($r['tgl_selesai'])) : null;
        $isiPesan             = trim($r['isi_pesan'] ?? '');
        $subject              = mb_substr($isiPesan, 0, 80);
        $solusi               = trim($r['solusi'] ?? '') ?: null;
        $petugasMenyelesaikan = trim($r['petugas_menyelesaikan'] ?? '') ?: null;
        $keywordMatched       = $r['keyword_matched'] ?? null;

        // Status: kd_user_done != 0 → Closed, 0 → Open
        $kdUserDone = (int) ($r['kd_user_done'] ?? 0);
        $status     = $kdUserDone !== 0 ? 'Closed' : 'Open';

        $upsertStmt->execute([
            $sourceId, $ruangan, $namaUser, $nomorPengirim, $tanggalLapor, $tanggalSelesai,
            $subject, $isiPesan, $solusi, $petugasMenyelesaikan, $keywordMatched, $status, 'Hardware'
        ]);

        // rowCount(): 1 = insert baru, 2 = update (ada perubahan), 0 = tidak berubah
        $affected = $upsertStmt->rowCount();
        if ($affected === 1)      $inserted++;
        elseif ($affected === 2)  $updated++;
        else                      $unchanged++;
    }

    header('Location: ' . APP_URL . "/logbook?ok=sync&inserted={$inserted}&updated={$updated}&skipped={$unchanged}");
    exit;

} catch (Throwable $e) {
    header('Location: ' . APP_URL . '/logbook?err=sync&msg=' . urlencode('DB error: ' . $e->getMessage()));
    exit;
}

// ── Helper ──────────────────────────────────────────────────
function ensureTiketTable(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS tiket (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_id INT NOT NULL,
            ruangan VARCHAR(255) DEFAULT NULL,
            nama_user VARCHAR(255) DEFAULT NULL,
            nomor_pengirim VARCHAR(50) DEFAULT NULL,
            tanggal_lapor DATETIME DEFAULT NULL,
            tanggal_selesai DATETIME DEFAULT NULL,
            subject VARCHAR(255) DEFAULT NULL,
            masalah TEXT DEFAULT NULL,
            solusi TEXT DEFAULT NULL,
            keyword_matched VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) DEFAULT 'Open',
            kategori VARCHAR(50) DEFAULT NULL,
            petugas_dilaporkan VARCHAR(255) DEFAULT NULL,
            petugas_menyelesaikan VARCHAR(255) DEFAULT NULL,
            tingkat_urgensi VARCHAR(20) DEFAULT 'Sedang',
            keterangan VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uniq_source_id (source_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}