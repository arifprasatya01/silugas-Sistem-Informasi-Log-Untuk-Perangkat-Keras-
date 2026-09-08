<?php
/**
 * Sinkronisasi tiket dari server WA Bailey (Postgres) ke tabel lokal `tiket`.
 * Hanya tiket yang isi_pesan-nya mengandung salah satu keyword di filtertiket.txt.
 * Tiket yang source_id-nya sudah pernah masuk akan di-skip (tidak dobel).
 *
 * @param PDO $localDb Koneksi ke database lokal (MySQL, tempat tabel `tiket` berada)
 * @return array{success:bool,message:string,inserted:int,skipped:int,total_remote:int}
 */
function syncTiketFromRemote(PDO $localDb): array
{
    // 1) Baca daftar keyword
    $keywordFile = __DIR__ . '/filtertiket.txt';
    if (!file_exists($keywordFile)) {
        return ['success' => false, 'message' => 'File filtertiket.txt tidak ditemukan di includes/.', 'inserted' => 0, 'skipped' => 0, 'total_remote' => 0];
    }

    $keywords = array_values(array_filter(array_map('trim', file($keywordFile))));
    if (!$keywords) {
        return ['success' => false, 'message' => 'filtertiket.txt kosong, tidak ada keyword untuk difilter.', 'inserted' => 0, 'skipped' => 0, 'total_remote' => 0];
    }

    // 2) Konek ke Postgres
    try {
        $pgDb = getPgDB();
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Gagal konek ke server 10.100.1.55: ' . $e->getMessage(), 'inserted' => 0, 'skipped' => 0, 'total_remote' => 0];
    }

    // 3) Bangun WHERE ... ILIKE '%keyword%' OR ILIKE '%keyword%' ...
    $conditions = [];
    $params     = [];
    foreach ($keywords as $i => $kw) {
        $conditions[] = "pm.isi_pesan ILIKE :kw{$i}";
        $params[":kw{$i}"] = '%' . $kw . '%';
    }
    $whereKeywords = implode(' OR ', $conditions);

    $sql = "
        SELECT
            pm.id,
            pm.isi_pesan,
            pm.pengirim,
            pm.nomor_pengirim,
            pm.tgl_lapor,
            pm.tgl_proses,
            pm.tgl_selesai,
            pm.status_selesai,
            u.nama_unit
        FROM pesan_masuk pm
        LEFT JOIN unit u ON u.kd_unit = pm.kd_unit_pelapor
        WHERE ($whereKeywords)
          AND (pm.status_hapus IS DISTINCT FROM 1)
        ORDER BY pm.tgl_lapor DESC
    ";

    try {
        $stmt = $pgDb->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Query ke Postgres gagal: ' . $e->getMessage(), 'inserted' => 0, 'skipped' => 0, 'total_remote' => 0];
    }

    // 4) Insert ke tabel lokal, skip yang source_id-nya sudah ada
    $checkStmt = $localDb->prepare("SELECT id FROM tiket WHERE source_id = ?");
    $insertStmt = $localDb->prepare("
        INSERT INTO tiket
            (source_id, ruangan, nama_user, nomor_pengirim, tanggal_lapor, tanggal_selesai, subject, masalah, keyword_matched, status, kategori)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    $inserted = 0;
    $skipped  = 0;

    foreach ($rows as $row) {
        $checkStmt->execute([$row['id']]);
        if ($checkStmt->fetch()) {
            $skipped++;
            continue;
        }

        // Cari keyword mana yang match (buat ditampilkan di UI)
        $matched = null;
        foreach ($keywords as $kw) {
            if ($kw !== '' && stripos($row['isi_pesan'] ?? '', $kw) !== false) {
                $matched = $kw;
                break;
            }
        }

        $status  = ((int)($row['status_selesai'] ?? 0) === 1) ? 'Closed' : 'Open';
        $subject = mb_substr(trim($row['isi_pesan'] ?? ''), 0, 80);

        try {
            $insertStmt->execute([
                $row['id'],
                $row['nama_unit'] ?: '-',
                $row['pengirim'] ?: '-',
                $row['nomor_pengirim'] ?: null,
                $row['tgl_lapor'] ? date('Y-m-d', strtotime($row['tgl_lapor'])) : null,
                $row['tgl_selesai'] ? date('Y-m-d', strtotime($row['tgl_selesai'])) : null,
                $subject,
                $row['isi_pesan'],
                $matched,
                $status,
                'Hardware',
            ]);
            $inserted++;
        } catch (PDOException $e) {
            // Kena UNIQUE constraint (race condition) atau error lain -> anggap skip, lanjut ke baris berikutnya
            $skipped++;
        }
    }

    return [
        'success'      => true,
        'message'      => "Sync selesai. {$inserted} tiket baru masuk, {$skipped} dilewati (sudah ada / tidak berubah).",
        'inserted'     => $inserted,
        'skipped'      => $skipped,
        'total_remote' => count($rows),
    ];
}
