<?php
// ============================================================
// tiket_sync_receive.php — Endpoint penerima data tiket WhatsApp
// (Bailey) yang sudah difilter keyword, dikirim dari server lokal
// 10.100.1.55/wa_message_bailey_sync/sync.php
//
// LETAKKAN DI: hardware/api/tiket_sync_receive.php
// (folder api/ memang sudah didesain diakses langsung tanpa login,
// lihat komentar di .htaccess)
//
// Dipanggil via curl POST, body JSON: {"api_key": "...", "data": [...]}
// Endpoint ini publik (tanpa session login) tapi dilindungi API key,
// karena dipanggil server-to-server oleh script lokal, bukan dari
// sesi browser admin yang sedang login.
// ============================================================

require_once __DIR__ . '/../config/db.php';

// HARUS SAMA PERSIS dengan $apiKey di server lokal (10.100.1.55)
define('SYNC_API_KEY', 'RSUDk4r4w4ng');

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (!is_array($payload) || !array_key_exists('api_key', $payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Request tidak valid. Endpoint ini hanya menerima push data JSON.']);
    exit;
}

if ($payload['api_key'] !== SYNC_API_KEY) {
    http_response_code(403);
    echo json_encode(['error' => 'API key salah atau tidak dikirim']);
    exit;
}

$rows = $payload['data'] ?? [];
if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format data tidak valid']);
    exit;
}

try {
    $db = getDB();
    // Coba buat tabel kalau belum ada. Kalau user DB tidak punya izin CREATE,
    // abaikan errornya SELAMA tabel memang sudah dibuat manual sebelumnya.
    try {
        ensureTiketTable($db);
    } catch (Throwable $e) {
        // Cek apakah tabel memang sudah ada (biar error CREATE tidak fatal)
        try {
            $db->query("SELECT 1 FROM tiket LIMIT 1");
        } catch (Throwable $e2) {
            // Tabel benar-benar belum ada DAN tidak bisa dibuat -> baru fatal
            throw new Exception('Tabel `tiket` belum ada dan user DB tidak punya izin CREATE TABLE. ' .
                'Buat tabel manual dulu lewat phpMyAdmin. Detail: ' . $e->getMessage());
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
        if (!$sourceId) { continue; }

        $ruangan       = $r['nama_unit'] ?: '-';
        $namaUser      = $r['pengirim'] ?: '-';
        $nomorPengirim = $r['nomor_pengirim'] ?? null;
        // Simpan tanggal + jam lengkap (sebelumnya cuma tanggal)
        $tanggalLapor   = !empty($r['tgl_lapor'])   ? date('Y-m-d H:i:s', strtotime($r['tgl_lapor']))   : null;
        $tanggalSelesai = !empty($r['tgl_selesai']) ? date('Y-m-d H:i:s', strtotime($r['tgl_selesai'])) : null;
        $isiPesan = trim($r['isi_pesan'] ?? '');
        $subject  = mb_substr($isiPesan, 0, 80);
        $solusi   = trim($r['solusi'] ?? '') ?: null;
        $petugasMenyelesaikan = trim($r['petugas_menyelesaikan'] ?? '') ?: null;
        // Status ditentukan dari kd_user_done, BUKAN status_selesai:
        // kd_user_done = 0  -> Open
        // kd_user_done != 0 -> Closed
        $kdUserDone = (int) ($r['kd_user_done'] ?? 0);
        $status     = $kdUserDone !== 0 ? 'Closed' : 'Open';
        $keywordMatched = $r['keyword_matched'] ?? null;

        $upsertStmt->execute([
            $sourceId, $ruangan, $namaUser, $nomorPengirim, $tanggalLapor, $tanggalSelesai,
            $subject, $isiPesan, $solusi, $petugasMenyelesaikan, $keywordMatched, $status, 'Hardware'
        ]);

        // MySQL rowCount() pada INSERT...ON DUPLICATE KEY UPDATE:
        // 1 = baris baru diinsert, 2 = baris lama diupdate (ada perubahan), 0 = sudah sama persis
        $affected = $upsertStmt->rowCount();
        if ($affected === 1) {
            $inserted++;
        } elseif ($affected === 2) {
            $updated++;
        } else {
            $unchanged++;
        }
    }

    echo json_encode([
        'status'         => 'ok',
        'total_diterima' => count($rows),
        'inserted'       => $inserted,
        'updated'        => $updated,
        'unchanged'      => $unchanged,
        'skipped'        => 0, // dipertahankan buat kompatibilitas, sekarang selalu 0 (tidak ada lagi yang di-skip, semua di-upsert)
    ]);
} catch (Throwable $e) {
    // Pesan error ASLI dari PDO/DB kelihatan di sini, jadi gampang di-debug
    http_response_code(500);
    echo json_encode(['error' => 'DB error: ' . $e->getMessage()]);
}

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