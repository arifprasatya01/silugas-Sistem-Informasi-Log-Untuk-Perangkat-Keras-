<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Rekap & TTD Bulanan');
$db = getDB();

$namaBulan = [
    1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni',
    7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
];

$uploadDir = __DIR__ . '/../uploads/ttd/';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

// ── Handle POST (sign / delete_ttd) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'sign') {
        $bulan    = cleanInt($_POST['bulan'] ?? 0);
        $tahun    = cleanInt($_POST['tahun'] ?? 0);
        $nama_ttd = trim($_POST['nama_ttd'] ?? '');
        $jabatan  = trim($_POST['jabatan'] ?? '') ?: 'Kepala Instalasi';
        $sig_data = $_POST['signature_data'] ?? '';

        if (!$bulan || !$tahun || !$nama_ttd || !$sig_data) {
            header('Location: ' . APP_URL . '/ttd_bulanan?err=incomplete&tahun=' . $tahun);
            exit;
        }

        if (!preg_match('/^data:image\/png;base64,(.+)$/', $sig_data, $m)) {
            header('Location: ' . APP_URL . '/ttd_bulanan?err=invalidimg&tahun=' . $tahun);
            exit;
        }
        $imgData = base64_decode($m[1]);
        if ($imgData === false) {
            header('Location: ' . APP_URL . '/ttd_bulanan?err=invalidimg&tahun=' . $tahun);
            exit;
        }

        // Hitung dulu berapa laporan yang BELUM ttd bulan ini (sebelum diupdate)
        $stmtLB = $db->prepare("SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
        $stmtLB->execute([$bulan, $tahun]);
        $jml_lb = (int) $stmtLB->fetchColumn();

        $jml_tk = 0;
        try {
            $stmtTK = $db->prepare("SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
            $stmtTK->execute([$bulan, $tahun]);
            $jml_tk = (int) $stmtTK->fetchColumn();
        } catch (PDOException $e) { /* tabel tiket belum ada, biarkan 0 */ }

        if (($jml_lb + $jml_tk) === 0) {
            header('Location: ' . APP_URL . '/ttd_bulanan?err=nodata&tahun=' . $tahun);
            exit;
        }

        $filename = 'ttd_' . $tahun . '_' . str_pad($bulan, 2, '0', STR_PAD_LEFT) . '_' . time() . '.png';
        file_put_contents($uploadDir . $filename, $imgData);

        $db->prepare("
            INSERT INTO ttd_bulanan (bulan, tahun, nama_ttd, jabatan, file_ttd, jumlah_logbook, jumlah_tiket, tanggal_ttd)
            VALUES (?,?,?,?,?,?,?,NOW())
        ")->execute([$bulan, $tahun, $nama_ttd, $jabatan, $filename, $jml_lb, $jml_tk]);
        $idTtd = (int) $db->lastInsertId();

        $db->prepare("UPDATE logbook SET id_ttd=? WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL")
           ->execute([$idTtd, $bulan, $tahun]);
        try {
            $db->prepare("UPDATE tiket SET id_ttd=? WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL")
               ->execute([$idTtd, $bulan, $tahun]);
        } catch (PDOException $e) {}

        header('Location: ' . APP_URL . '/ttd_bulanan?ok=signed&tahun=' . $tahun);
        exit;
    }

    elseif ($action === 'create_link') {
        $tahunLink = cleanInt($_POST['tahun'] ?? date('Y'));

        $daftarBulan = [];
        for ($b = 1; $b <= 12; $b++) {
            $stmtLB = $db->prepare("SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
            $stmtLB->execute([$b, $tahunLink]);
            $jml = (int) $stmtLB->fetchColumn();
            try {
                $stmtTK = $db->prepare("SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
                $stmtTK->execute([$b, $tahunLink]);
                $jml += (int) $stmtTK->fetchColumn();
            } catch (PDOException $e) {}

            if ($jml > 0) {
                $daftarBulan[] = ['bulan' => $b, 'tahun' => $tahunLink, 'label' => $namaBulan[$b], 'jumlah' => $jml];
            }
        }

        if (!$daftarBulan) {
            header('Location: ' . APP_URL . '/ttd_bulanan?err=nolink&tahun=' . $tahunLink);
            exit;
        }

        $token = bin2hex(random_bytes(24));
        $db->prepare("
            INSERT INTO ttd_link (token, daftar_bulan, status, dibuat_at, dibuat_oleh)
            VALUES (?,?, 'pending', NOW(), ?)
        ")->execute([$token, json_encode($daftarBulan), $_SESSION['nama'] ?? $_SESSION['username'] ?? null]);

        header('Location: ' . APP_URL . '/ttd_bulanan?ok=linkcreated&tahun=' . $tahunLink . '&token=' . $token);
        exit;
    }

    elseif ($action === 'mark_sent') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("UPDATE ttd_link SET dikirim_at = NOW() WHERE id = ?")->execute([$id]);
        }
        header('Location: ' . APP_URL . '/ttd_bulanan?ok=sent&tahun=' . cleanInt($_POST['tahun'] ?? date('Y')));
        exit;
    }

    elseif ($action === 'delete_ttd') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $db->prepare("SELECT * FROM ttd_bulanan WHERE id=?");
            $stmt->execute([$id]);
            $ttd = $stmt->fetch();
            if ($ttd) {
                $db->prepare("UPDATE logbook SET id_ttd=NULL WHERE id_ttd=?")->execute([$id]);
                try { $db->prepare("UPDATE tiket SET id_ttd=NULL WHERE id_ttd=?")->execute([$id]); } catch (PDOException $e) {}
                if (!empty($ttd['file_ttd']) && file_exists($uploadDir . $ttd['file_ttd'])) {
                    @unlink($uploadDir . $ttd['file_ttd']);
                }
                $db->prepare("DELETE FROM ttd_bulanan WHERE id=?")->execute([$id]);
            }
        }
        header('Location: ' . APP_URL . '/ttd_bulanan?ok=deleted');
        exit;
    }
}

// ── Mode Cetak: hanya laporan yang SUDAH di-TTD ──────────────
if (isset($_GET['print']) && isset($_GET['bulan']) && isset($_GET['tahun'])) {
    $pBulan = cleanInt($_GET['bulan']);
    $pTahun = cleanInt($_GET['tahun']);

    $stmt = $db->prepare("SELECT * FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NOT NULL ORDER BY tanggal_lapor ASC, id ASC");
    $stmt->execute([$pBulan, $pTahun]);
    $rowsLB = $stmt->fetchAll();

    $rowsTK = [];
    try {
        $stmtT = $db->prepare("SELECT * FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NOT NULL ORDER BY tanggal_lapor ASC, id ASC");
        $stmtT->execute([$pBulan, $pTahun]);
        $rowsTK = $stmtT->fetchAll();
    } catch (PDOException $e) {}

    $printRows = array_merge($rowsLB, $rowsTK);
    usort($printRows, fn($a, $b) => strcmp($a['tanggal_lapor'] ?? '', $b['tanggal_lapor'] ?? ''));

    // Ambil batch TTD terakhir untuk bulan ini (Kainstal)
    $stTtd = $db->prepare("SELECT * FROM ttd_bulanan WHERE bulan=? AND tahun=? ORDER BY id DESC LIMIT 1");
    $stTtd->execute([$pBulan, $pTahun]);
    $ttdInfo = $stTtd->fetch();

    function h2($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
    <meta charset="UTF-8">
    <title>Logbook Hardware - <?= $namaBulan[$pBulan] ?? '' ?> <?= $pTahun ?></title>
    <style id="pageOrientationStyle">
      @page { size: A4 landscape; margin: 12mm 14mm; }
    </style>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color:#000; margin:0; }
        .wrap { max-width: 1000px; margin: 0 auto; }

        table.kop { width:100%; border-collapse:collapse; margin-bottom:0; }
        table.kop td { vertical-align:middle; padding:0 8px; }
        table.kop td.logo-left  { width:100px; text-align:left; }
        table.kop td.logo-right { width:110px; text-align:right; }
        table.kop td.logo-left img, table.kop td.logo-right img { max-width:100%; max-height:95px; }
        table.kop td.kop-text { text-align:center; line-height:1.35; }
        .kop-text .l1, .kop-text .l2 { font-size:13px; }
        .kop-text .l3 { font-size:14.5px; font-weight:bold; }
        .kop-text .l4 { font-size:15.5px; font-weight:bold; }
        .kop-text .l5 { font-size:11.5px; margin-top:3px; }
        .kop-text .l6 { font-size:11px; }
        .kop-rule { border-bottom:4px double #000; margin-bottom:12px; padding-top:6px; }

        h1 { text-align:center; font-size:14px; font-weight:bold; margin:12px 0 2px; letter-spacing:.4px; text-transform:uppercase; }
        .periode { text-align:center; font-size:11.5px; margin-bottom:12px; }

        table.data { width:100%; border-collapse:collapse; margin-bottom:14px; border:1px solid #000; }
        table.data th { border:1px solid #000; padding:5px 6px; font-size:10.5px; text-align:center; font-weight:bold; background:#D9D9D9; }
        table.data td { border:1px solid #000; padding:4px 6px; font-size:10px; text-align:left; vertical-align:top; }
        table.data td.no, table.data td.center { text-align:center; }

        table.sign2 { width:100%; border-collapse:collapse; margin-top:18px; }
        table.sign2 td { width:50%; vertical-align:top; padding:0 14px; font-size:12px; text-align:center; }
        table.sign2 .tgl-cetak { text-align:center; margin-bottom:2px; }
        table.sign2 .jabatan { margin-bottom:0; }
        table.sign2 .ttd-slot { height:55px; text-align:center; line-height:55px; }
        table.sign2 .ttd-img { max-height:50px; max-width:140px; vertical-align:middle; }
        table.sign2 .nama { text-decoration:underline; font-weight:bold; margin-top:4px; }
        table.sign2 .nip { margin-top:2px; }

        .toolbar { text-align:center; margin: 16px 0 24px; }
        .toolbar button {
            background:#1a237e; color:#fff; border:none; border-radius:6px; padding:9px 18px;
            font-size:14px; cursor:pointer; font-family: 'Segoe UI', sans-serif; margin:0 4px;
        }
        .toolbar button.outline { background:#fff; color:#1a237e; border:1.5px solid #1a237e; }
        @media print { .toolbar { display:none !important; } }
    </style>
    </head>
    <body>

    <div class="toolbar">
      <button onclick="cetak('landscape')">&#128424; Cetak Landscape</button>
      <button class="outline" onclick="cetak('portrait')">&#128424; Cetak Portrait</button>
    </div>
    <script>
    function cetak(orientasi) {
        document.getElementById('pageOrientationStyle').innerHTML =
            '@page { size: A4 ' + orientasi + '; margin: 12mm 14mm; }';
        window.print();
    }
    </script>

    <div class="wrap">

      <table class="kop">
        <tr>
          <td class="logo-left">
            <img src="<?= APP_URL ?>/assets/icons/logo-kabupaten.png" alt="Logo Kabupaten Karawang">
          </td>
          <td class="kop-text">
            <div class="l1">PEMERINTAH KABUPATEN KARAWANG</div>
            <div class="l2">DINAS KESEHATAN</div>
            <div class="l3">UNIT ORGANISASI BERSIFAT KHUSUS</div>
            <div class="l4">RUMAH SAKIT UMUM DAERAH KARAWANG</div>
            <div class="l5">Jalan Galuh Mas Raya No. 1 Karawang</div>
            <div class="l6">Telepon : (0267) 640444, 640555 Pos-el : rsudkrw@yahoo.co.id Kode Pos 41361</div>
          </td>
          <td class="logo-right">
            <img src="<?= APP_URL ?>/assets/icons/logo-rsud.png" alt="Logo RSUD Karawang">
          </td>
        </tr>
      </table>
      <div class="kop-rule"></div>

      <h1>Logbook Hardware SIMRS</h1>
      <div class="periode">Periode: <?= h2($namaBulan[$pBulan] ?? '') ?> <?= h2($pTahun) ?> (Sudah Ditandatangani)</div>

      <table class="data">
        <thead>
            <tr>
                <th>No</th><th>Tgl Lapor</th><th>Ruangan</th><th>User</th>
                <th>Subject</th><th>Masalah</th><th>Solusi</th><th>Status</th><th>Petugas</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$printRows): ?>
            <tr><td colspan="9" class="center">Tidak ada data yang sudah di-TTD untuk bulan ini</td></tr>
        <?php else: $no = 1; foreach ($printRows as $row): ?>
            <tr>
                <td class="no"><?= $no++ ?></td>
                <td class="center"><?= $row['tanggal_lapor'] ? date('d/m/Y', strtotime($row['tanggal_lapor'])) : '-' ?></td>
                <td><?= h2($row['ruangan'] ?? '-') ?></td>
                <td><?= h2($row['nama_user'] ?? '-') ?></td>
                <td><?= h2($row['subject'] ?? '-') ?></td>
                <td><?= h2($row['masalah'] ?? '-') ?></td>
                <td><?= h2($row['solusi'] ?? '-') ?></td>
                <td><?= h2($row['status'] ?? '-') ?></td>
                <td><?= h2($row['petugas_menyelesaikan'] ?: ($row['petugas_dilaporkan'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <table class="sign2">
        <tr>
          <td>&nbsp;</td>
          <td class="tgl-cetak">Karawang, &nbsp;<?= date('d') ?>&nbsp; <?= $namaBulan[(int)date('m')] ?> <?= date('Y') ?></td>
        </tr>
        <tr>
          <td>
            <div class="jabatan">Pj. Hardware dan Inventory</div>
            <div class="ttd-slot"><img src="<?= APP_URL ?>/uploads/s/riky_irawan.png" alt="TTD Riky Irawan" class="ttd-img"></div>
            <div class="nama">Riky Irawan, ST</div>
            <div class="nip">NIP. 19820809 202321 1 008</div>
          </td>
          <td>
            <div class="jabatan"><?= $ttdInfo ? h2($ttdInfo['jabatan']) : 'Ka. Instalasi SIMRS' ?></div>
            <div class="ttd-slot">
                <?php if ($ttdInfo): ?>
                <img src="<?= APP_URL ?>/uploads/ttd/<?= h2($ttdInfo['file_ttd']) ?>" alt="TTD Kainstal" class="ttd-img">
                <?php endif; ?>
            </div>
            <div class="nama"><?= $ttdInfo ? h2($ttdInfo['nama_ttd']) : '&nbsp;' ?></div>
            <div class="nip">NIP. 19771012 201001 1 007</div>
          </td>
        </tr>
      </table>

    </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Flash message ─────────────────────────────────────────────
$ok  = $_GET['ok'] ?? '';
$err = $_GET['err'] ?? '';
$msg = ''; $msgType = 'success';
if ($ok === 'signed')          { $msg = 'Tanda tangan berhasil disimpan untuk semua laporan yang belum di-TTD pada bulan tersebut.'; }
elseif ($ok === 'deleted')     { $msg = 'Data TTD dihapus, laporan terkait kembali berstatus belum TTD.'; }
elseif ($ok === 'linkcreated') { $msg = 'Link TTD berhasil dibuat. Salin link di bawah dan kirim ke Kainstal via WhatsApp.'; }
elseif ($ok === 'sent')        { $msg = 'Link ditandai sudah dikirim.'; }
elseif ($err === 'incomplete') { $msg = 'Data tidak lengkap (nama/tanda tangan kosong), gagal disimpan.'; $msgType = 'danger'; }
elseif ($err === 'invalidimg') { $msg = 'Gagal membaca hasil tanda tangan, silakan coba lagi.'; $msgType = 'danger'; }
elseif ($err === 'nodata')     { $msg = 'Tidak ada laporan yang perlu di-TTD pada bulan tersebut.'; $msgType = 'danger'; }
elseif ($err === 'nolink')     { $msg = 'Tidak ada laporan yang perlu ditandatangani pada tahun tersebut, link tidak dibuat.'; $msgType = 'danger'; }

$linkBaru = null;
if ($ok === 'linkcreated' && !empty($_GET['token'])) {
    $stmtLink = $db->prepare("SELECT * FROM ttd_link WHERE token = ?");
    $stmtLink->execute([trim($_GET['token'])]);
    $linkBaru = $stmtLink->fetch();
}

// ── Rekap per bulan ──────────────────────────────────────────
$tahunAktif   = cleanInt($_GET['tahun'] ?? date('Y'));
$filterStatus = $_GET['fstatus'] ?? '';

function hitungLogbookTtd($db, $bulan, $tahun, $ttd = null) {
    $sql = "SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=?";
    if ($ttd === true)  $sql .= " AND id_ttd IS NOT NULL";
    if ($ttd === false) $sql .= " AND id_ttd IS NULL";
    $stmt = $db->prepare($sql);
    $stmt->execute([$bulan, $tahun]);
    return (int) $stmt->fetchColumn();
}
function hitungTiketTtd($db, $bulan, $tahun, $ttd = null) {
    try {
        $sql = "SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=?";
        if ($ttd === true)  $sql .= " AND id_ttd IS NOT NULL";
        if ($ttd === false) $sql .= " AND id_ttd IS NULL";
        $stmt = $db->prepare($sql);
        $stmt->execute([$bulan, $tahun]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

$rekap = [];
for ($b = 1; $b <= 12; $b++) {
    $total = hitungLogbookTtd($db, $b, $tahunAktif) + hitungTiketTtd($db, $b, $tahunAktif);
    $sudah = hitungLogbookTtd($db, $b, $tahunAktif, true) + hitungTiketTtd($db, $b, $tahunAktif, true);
    $belum = hitungLogbookTtd($db, $b, $tahunAktif, false) + hitungTiketTtd($db, $b, $tahunAktif, false);

    if ($total === 0)     { $stat = 'kosong'; }
    elseif ($belum === 0) { $stat = 'sudah'; }
    elseif ($sudah === 0) { $stat = 'belum'; }
    else                  { $stat = 'sebagian'; }

    if ($filterStatus && $stat !== $filterStatus) continue;

    $ttdRow = null;
    if ($sudah > 0) {
        $stTtd = $db->prepare("SELECT * FROM ttd_bulanan WHERE bulan=? AND tahun=? ORDER BY id DESC LIMIT 1");
        $stTtd->execute([$b, $tahunAktif]);
        $ttdRow = $stTtd->fetch();
    }

    $rekap[] = ['bulan' => $b, 'total' => $total, 'sudah' => $sudah, 'belum' => $belum, 'status' => $stat, 'ttd' => $ttdRow];
}

include __DIR__ . '/../includes/header.php';
?>

<style>
    .badge-stat-sudah    { background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    .badge-stat-belum    { background:#fff3e0; color:#e65100; border:1px solid #ffe0b2; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    .badge-stat-sebagian { background:#fff8e1; color:#8d6e00; border:1px solid #ffe082; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    .badge-stat-kosong   { background:#f1f1f1; color:#777; border:1px solid #ddd; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    #ttdCanvas { border: 1px dashed #999; border-radius: 6px; background:#fff; touch-action:none; cursor:crosshair; width:100%; max-width:560px; height:200px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Rekap &amp; TTD Bulanan</h4>
        <div class="text-muted small">Tanda tangan Kainstal untuk laporan logbook hardware per bulan</div>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= clean($msgType) ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i> <?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Tahun</label>
                <input type="number" name="tahun" class="form-control form-control-sm" value="<?= (int)$tahunAktif ?>" min="2000" max="2100">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label form-label-sm">Status TTD</label>
                <select name="fstatus" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="belum"    <?= $filterStatus === 'belum' ? 'selected' : '' ?>>Belum di-TTD</option>
                    <option value="sebagian" <?= $filterStatus === 'sebagian' ? 'selected' : '' ?>>Sebagian</option>
                    <option value="sudah"    <?= $filterStatus === 'sudah' ? 'selected' : '' ?>>Sudah di-TTD</option>
                    <option value="kosong"   <?= $filterStatus === 'kosong' ? 'selected' : '' ?>>Tidak ada data</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="<?= APP_URL ?>/ttd_bulanan" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Rekap -->
<div class="card">
    <div class="card-header bg-light">
        <i class="bi bi-calendar3 me-2"></i>Rekap Tahun <?= (int)$tahunAktif ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Bulan</th><th>Total Laporan</th><th>Sudah TTD</th><th>Belum TTD</th>
                        <th>Status</th><th>Ditandatangani Oleh</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rekap): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">Tidak ada bulan yang cocok dengan filter</td></tr>
                <?php else: foreach ($rekap as $r): ?>
                    <tr>
                        <td class="fw-semibold"><?= $namaBulan[$r['bulan']] ?></td>
                        <td><?= $r['total'] ?></td>
                        <td><?= $r['sudah'] ?></td>
                        <td><?= $r['belum'] ?></td>
                        <td>
                            <?php if ($r['status'] === 'sudah'): ?>
                                <span class="badge-stat-sudah"><i class="bi bi-check-circle-fill me-1"></i>Sudah TTD</span>
                            <?php elseif ($r['status'] === 'sebagian'): ?>
                                <span class="badge-stat-sebagian"><i class="bi bi-exclamation-circle-fill me-1"></i>Sebagian</span>
                            <?php elseif ($r['status'] === 'belum'): ?>
                                <span class="badge-stat-belum"><i class="bi bi-hourglass-split me-1"></i>Belum TTD</span>
                            <?php else: ?>
                                <span class="badge-stat-kosong">Tidak ada data</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if ($r['ttd']): ?>
                                <?= clean($r['ttd']['nama_ttd']) ?><br>
                                <small class="text-muted"><?= date('d/m/Y', strtotime($r['ttd']['tanggal_ttd'])) ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if (in_array($r['status'], ['belum', 'sebagian'])): ?>
                                    <button class="btn btn-sm btn-primary"
                                        onclick="openTtdModal(<?= $r['bulan'] ?>, <?= (int)$tahunAktif ?>, '<?= $namaBulan[$r['bulan']] ?>', <?= $r['belum'] ?>)">
                                        <i class="bi bi-pen me-1"></i>TTD Sekarang
                                    </button>
                                <?php endif; ?>
                                <?php if ($r['sudah'] > 0): ?>
                                    <a class="btn btn-sm btn-outline-primary" target="_blank"
                                       href="<?= APP_URL ?>/ttd_bulanan?print=1&bulan=<?= $r['bulan'] ?>&tahun=<?= (int)$tahunAktif ?>">
                                        <i class="bi bi-printer me-1"></i>Cetak
                                    </a>
                                <?php endif; ?>
                                <?php if ($r['ttd']): ?>
                                    <form method="POST" onsubmit="return confirm('Hapus TTD bulan ini? Laporan yang tercakup akan kembali berstatus belum TTD.')" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_ttd">
                                        <input type="hidden" name="id" value="<?= (int)$r['ttd']['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus TTD">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Link TTD untuk Kainstal -->
<div class="card mt-3">
    <div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-link-45deg me-2"></i>Link TTD untuk Kainstal (via WhatsApp)</span>
        <form method="POST" onsubmit="return confirm('Buat link TTD baru untuk semua bulan yang belum ditandatangani di tahun <?= (int)$tahunAktif ?>?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_link">
            <input type="hidden" name="tahun" value="<?= (int)$tahunAktif ?>">
            <button type="submit" class="btn btn-sm btn-success">
                <i class="bi bi-plus-lg me-1"></i>Buat Link TTD (Tahun <?= (int)$tahunAktif ?>)
            </button>
        </form>
    </div>

    <?php if ($linkBaru): ?>
    <div class="card-body border-bottom bg-light-subtle">
        <?php
            $daftarBulanBaru = json_decode($linkBaru['daftar_bulan'], true) ?: [];
            $labelsBaru = array_map(fn($it) => ($namaBulan[$it['bulan']] ?? '') . ' ' . $it['tahun'], $daftarBulanBaru);
            $urlBaru = APP_URL . '/ttd_public?token=' . $linkBaru['token'];
            $pesanWa = "Assalamu'alaikum, mohon ijin untuk menandatangani Laporan Logbook Hardware bulan "
                . implode(', ', $labelsBaru) . ".\n\nSilakan buka link berikut untuk tanda tangan:\n" . $urlBaru . "\n\nTerima kasih.";
        ?>
        <label class="form-label small text-muted mb-1">Link untuk bulan: <?= htmlspecialchars(implode(', ', $labelsBaru)) ?></label>
        <div class="input-group mb-2">
            <input type="text" class="form-control form-control-sm" id="linkBaruUrl" value="<?= htmlspecialchars($urlBaru) ?>" readonly>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="copyLink()"><i class="bi bi-clipboard me-1"></i>Salin</button>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-success" target="_blank"
               href="https://wa.me/?text=<?= urlencode($pesanWa) ?>">
                <i class="bi bi-whatsapp me-1"></i>Kirim via WhatsApp
            </a>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_sent">
                <input type="hidden" name="id" value="<?= (int)$linkBaru['id'] ?>">
                <input type="hidden" name="tahun" value="<?= (int)$tahunAktif ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-check2 me-1"></i>Tandai Sudah Dikirim
                </button>
            </form>
        </div>
    </div>
    <script>
        function copyLink() {
            const el = document.getElementById('linkBaruUrl');
            el.select(); el.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(el.value);
        }
    </script>
    <?php endif; ?>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>Bulan Diminta</th><th>Status</th><th>Dibuat</th><th>Dikirim</th><th>Ditandatangani</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $stmtHist = $db->prepare("SELECT * FROM ttd_link ORDER BY id DESC LIMIT 20");
                $stmtHist->execute();
                $riwayatLink = $stmtHist->fetchAll();
                ?>
                <?php if (!$riwayatLink): ?>
                    <tr><td colspan="6" class="text-center py-3 text-muted">Belum ada link yang dibuat</td></tr>
                <?php else: foreach ($riwayatLink as $rl):
                    $items = json_decode($rl['daftar_bulan'], true) ?: [];
                    $labels = array_map(fn($it) => ($namaBulan[$it['bulan']] ?? '') . ' ' . $it['tahun'], $items);
                    $urlRl = APP_URL . '/ttd_public?token=' . $rl['token'];
                ?>
                    <tr>
                        <td style="font-size:.82rem;"><?= htmlspecialchars(implode(', ', $labels)) ?></td>
                        <td>
                            <?php if ($rl['status'] === 'signed'): ?>
                                <span class="badge-stat-sudah"><i class="bi bi-check-circle-fill me-1"></i>Sudah TTD</span>
                            <?php else: ?>
                                <span class="badge-stat-belum"><i class="bi bi-hourglass-split me-1"></i>Menunggu</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= date('d/m/y H:i', strtotime($rl['dibuat_at'])) ?></small></td>
                        <td><small><?= $rl['dikirim_at'] ? date('d/m/y H:i', strtotime($rl['dikirim_at'])) : '<span class="text-muted">-</span>' ?></small></td>
                        <td><small><?= $rl['signed_at'] ? date('d/m/y H:i', strtotime($rl['signed_at'])) . ' oleh ' . htmlspecialchars($rl['nama_ttd']) : '<span class="text-muted">-</span>' ?></small></td>
                        <td>
                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?= htmlspecialchars($urlRl) ?>" title="Buka link">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal TTD -->
<div class="modal fade" id="modalTtd" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pen me-2"></i>Tanda Tangan Kainstal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formTtd">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="sign">
                <input type="hidden" name="bulan" id="ttd_bulan">
                <input type="hidden" name="tahun" id="ttd_tahun">
                <input type="hidden" name="signature_data" id="ttd_signature_data">
                <div class="modal-body">
                    <p class="mb-2" id="ttd_info_text"></p>
                    <div class="mb-3">
                        <label class="form-label">Nama Kainstal <span class="text-danger">*</span></label>
                        <input type="text" name="nama_ttd" id="ttd_nama" class="form-control" required placeholder="Nama lengkap">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jabatan</label>
                        <input type="text" name="jabatan" id="ttd_jabatan" class="form-control" value="Kepala Instalasi">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Tanda Tangan <span class="text-danger">*</span></label>
                        <canvas id="ttdCanvas" width="560" height="200"></canvas>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearCanvas()">
                        <i class="bi bi-eraser me-1"></i>Hapus &amp; Ulangi
                    </button>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary" id="btnSimpanTtd">
                        <i class="bi bi-save me-1"></i>Simpan Tanda Tangan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let canvas, ctx, drawing = false;

function initCanvas() {
    canvas = document.getElementById('ttdCanvas');
    ctx = canvas.getContext('2d');
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#111';
    clearCanvas();

    const pos = (e) => {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const cx = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        const cy = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
        return { x: cx * scaleX, y: cy * scaleY };
    };

    const start = (e) => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
    const move  = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
    const end   = () => { drawing = false; };

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
}

function clearCanvas() {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function isCanvasEmpty() {
    const blank = document.createElement('canvas');
    blank.width = canvas.width; blank.height = canvas.height;
    const bctx = blank.getContext('2d');
    bctx.fillStyle = '#ffffff';
    bctx.fillRect(0, 0, blank.width, blank.height);
    return canvas.toDataURL() === blank.toDataURL();
}

function openTtdModal(bulan, tahun, bulanLabel, belumCount) {
    document.getElementById('ttd_bulan').value = bulan;
    document.getElementById('ttd_tahun').value = tahun;
    document.getElementById('ttd_info_text').innerHTML =
        'Menandatangani <strong>' + belumCount + ' laporan</strong> yang belum di-TTD pada periode <strong>' + bulanLabel + ' ' + tahun + '</strong>.';
    document.getElementById('ttd_nama').value = '';
    if (!canvas) initCanvas(); else clearCanvas();
    new bootstrap.Modal(document.getElementById('modalTtd')).show();
}

document.getElementById('formTtd').addEventListener('submit', function (e) {
    if (isCanvasEmpty()) {
        e.preventDefault();
        alert('Tanda tangan belum diisi. Silakan gambar tanda tangan di kotak yang tersedia.');
        return;
    }
    document.getElementById('ttd_signature_data').value = canvas.toDataURL('image/png');
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>