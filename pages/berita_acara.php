<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/db.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$db = getDB();

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function bulan_indo($n) {
    $b = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    return $b[(int)$n] ?? $n;
}

$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$error = '';
$notice = '';
$ba = null;

if ($code !== '') {
    $stmt = $db->prepare("SELECT * FROM berita_acara WHERE code = ?");
    $stmt->execute([$code]);
    $ba = $stmt->fetch();
}

if ($code === '' || !$ba) {
    $error = 'Token Berita Acara tidak ditemukan.';
}

function save_signature($code, $prefix, $signature_data) {
    $sig_dir = __DIR__ . '/../uploads/hardware_signatures';
    if (!is_dir($sig_dir)) { mkdir($sig_dir, 0775, true); }
    $base64   = substr($signature_data, strpos($signature_data, 'base64,') + 7);
    $binary   = base64_decode($base64);
    $sig_name = $code . '_ba_' . $prefix . '_' . time() . '.png';
    file_put_contents($sig_dir . '/' . $sig_name, $binary);
    return 'uploads/hardware_signatures/' . $sig_name;
}

if ($ba && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($ba['status'] === 'complete') {
        $error = 'Berita acara ini sudah lengkap (kedua pihak sudah TTD) dan terkunci.';

    } elseif ($action === 'save_items') {
        if (!empty($ba['items_locked'])) {
            $error = 'Daftar perangkat sudah disimpan dan terkunci, tidak bisa diubah lagi.';
        } else {
            $hw_names  = $_POST['hw_name'] ?? [];
            $hw_jumlah = $_POST['hw_jumlah'] ?? [];
            $items = [];
            foreach ($hw_names as $i => $n) {
                $n = trim($n);
                $j = trim($hw_jumlah[$i] ?? '');
                if ($n !== '' || $j !== '') $items[] = ['nama' => $n, 'jumlah' => $j];
            }
            $stmt = $db->prepare("UPDATE berita_acara SET items = ?, items_locked = 1 WHERE code = ?");
            $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $code]);
            $notice = 'Daftar perangkat tersimpan dan terkunci.';
        }

    } elseif ($action === 'save_menyerahkan') {
        if (!empty($ba['menyerahkan_signature_path'])) {
            $error = 'Pihak Yang Menyerahkan sudah TTD sebelumnya, tidak bisa diubah lagi.';
        } else {
        $nama = trim($_POST['menyerahkan_nama'] ?? '');
        $nip = trim($_POST['menyerahkan_nip'] ?? '');
        $unit_kerja = trim($_POST['menyerahkan_unit_kerja'] ?? '');
        $jabatan = trim($_POST['menyerahkan_jabatan'] ?? '');
        $signature_data = $_POST['menyerahkan_signature_data'] ?? '';

        $errs = [];
        if ($nama === '') $errs[] = 'Nama (Yang Menyerahkan) wajib diisi.';
        if ($unit_kerja === '') $errs[] = 'Unit Kerja (Yang Menyerahkan) wajib diisi.';
        if ($jabatan === '') $errs[] = 'Jabatan (Yang Menyerahkan) wajib diisi.';
        if ($signature_data === '' || strpos($signature_data, 'base64,') === false) $errs[] = 'Tanda tangan (Yang Menyerahkan) wajib diisi.';

        if ($errs) {
            $error = implode(' ', $errs);
        } else {
            $sig_path = save_signature($code, 'menyerahkan', $signature_data);
            $stmt = $db->prepare("UPDATE berita_acara SET
                menyerahkan_nama=?, menyerahkan_nip=?, menyerahkan_unit_kerja=?, menyerahkan_jabatan=?,
                menyerahkan_signature_path=?, menyerahkan_signed_at=NOW() WHERE code=?");
            $stmt->execute([$nama, $nip, $unit_kerja, $jabatan, $sig_path, $code]);
            $notice = 'TTD pihak Yang Menyerahkan tersimpan.';
        }
        }

    } elseif ($action === 'save_menerima') {
        if (!empty($ba['menerima_signature_path'])) {
            $error = 'Pihak Yang Menerima sudah TTD sebelumnya, tidak bisa diubah lagi.';
        } else {
        $nama = trim($_POST['menerima_nama'] ?? '');
        $nip = trim($_POST['menerima_nip'] ?? '');
        $unit_kerja = trim($_POST['menerima_unit_kerja'] ?? '');
        $jabatan = trim($_POST['menerima_jabatan'] ?? '');
        $signature_data = $_POST['menerima_signature_data'] ?? '';

        $errs = [];
        if ($nama === '') $errs[] = 'Nama (Yang Menerima) wajib diisi.';
        if ($unit_kerja === '') $errs[] = 'Unit Kerja (Yang Menerima) wajib diisi.';
        if ($jabatan === '') $errs[] = 'Jabatan (Yang Menerima) wajib diisi.';
        if ($signature_data === '' || strpos($signature_data, 'base64,') === false) $errs[] = 'Tanda tangan (Yang Menerima) wajib diisi.';

        if ($errs) {
            $error = implode(' ', $errs);
        } else {
            $sig_path = save_signature($code, 'menerima', $signature_data);
            $stmt = $db->prepare("UPDATE berita_acara SET
                menerima_nama=?, menerima_nip=?, menerima_unit_kerja=?, menerima_jabatan=?,
                menerima_signature_path=?, menerima_signed_at=NOW() WHERE code=?");
            $stmt->execute([$nama, $nip, $unit_kerja, $jabatan, $sig_path, $code]);
            $notice = 'TTD pihak Yang Menerima tersimpan.';
        }
        }
    }

    // Cek apakah kedua pihak sudah TTD -> kunci jadi complete
    $stmt = $db->prepare("SELECT * FROM berita_acara WHERE code = ?");
    $stmt->execute([$code]);
    $ba = $stmt->fetch();

    if ($ba && $ba['status'] === 'open' && $ba['menyerahkan_signature_path'] && $ba['menerima_signature_path']) {
        $db->prepare("UPDATE berita_acara SET status='complete', completed_at=NOW() WHERE code=?")->execute([$code]);
        $stmt = $db->prepare("SELECT * FROM berita_acara WHERE code = ?");
        $stmt->execute([$code]);
        $ba = $stmt->fetch();
        $notice .= ' Berita acara lengkap dan sudah terkunci.';
    }
}

$items = [];
if ($ba) {
    $items = json_decode($ba['items'] ?? '[]', true);
    if (!is_array($items)) { $items = []; }
}

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Berita Acara Serah Terima &mdash; <?= h(defined('APP_NAME') ? APP_NAME : 'Hardware Monitoring') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --primary:#0E6E66; --primary-dark:#0A4F49; --primary-50:#E6F1EF; --border:#DCE6E5; --danger:#B3261E; --danger-50:#FBEAE8; --success:#1F7A4D; --success-50:#E4F4ED; }
  *{box-sizing:border-box;}
  body{
    background:#F4F7F7; margin:0; padding:28px 14px 60px;
    font-family:'IBM Plex Sans', Arial, sans-serif; color:#15171a; font-size:14px;
  }
  .doc{ max-width:780px; margin:0 auto; background:#fff; border:1px solid var(--border); border-radius:10px;
        padding:32px 38px; box-shadow:0 1px 2px rgba(0,0,0,.05), 0 4px 16px rgba(0,0,0,.05); }

  .topbar{ max-width:780px; margin:0 auto 16px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;}
  .badge{ font-size:12.5px; font-weight:600; padding:6px 12px; border-radius:20px; }
  .badge-open{ background:var(--success-50); color:var(--success); }
  .badge-complete{ background:#EEF1F1; color:#5B7077; }
  .btn{ border:none; border-radius:7px; font-size:13.5px; font-weight:600; padding:8px 14px; cursor:pointer; font-family:inherit; }
  .btn-primary{ background:var(--primary); color:#fff; }
  .btn-primary:hover{ background:var(--primary-dark); }
  .btn-outline{ background:#fff; border:1px solid var(--border); color:#15171a; }
  .btn-outline:hover{ background:#F4F7F7; }
  .btn-danger-outline{ background:#fff; border:1px solid var(--border); color:var(--danger); font-size:12.5px; padding:5px 10px; }
  .btn-warning{ background:#D97B29; color:#fff; }

  .alert{ max-width:780px; margin:0 auto 14px; border-radius:8px; padding:10px 14px; font-size:13.5px; }
  .alert-danger{ background:var(--danger-50); color:var(--danger); }
  .alert-success{ background:var(--success-50); color:var(--success); }

  table.print-wrap{ width:100%; border-collapse:collapse; }
  table.print-wrap > thead > tr > td, table.print-wrap > tbody > tr > td{ padding:0; }

  table.kop{ width:100%; border-collapse:collapse; margin-bottom:0; }
  table.kop td{ vertical-align:middle; padding:0 6px; }
  table.kop td.logo-l, table.kop td.logo-r{ width:100px; }
  table.kop td.logo-r{ text-align:right; }
  table.kop td.logo-l img, table.kop td.logo-r img{ max-width:100%; max-height:105px; }
  table.kop td.kop-text{ text-align:center; line-height:1.32; }
  .kop-text .l1, .kop-text .l2{ font-size:13px; }
  .kop-text .l3{ font-size:14.5px; font-weight:bold; }
  .kop-text .l4{ font-size:15.5px; font-weight:bold; }
  .kop-text .l5{ font-size:11.5px; margin-top:3px; }
  .kop-text .l6{ font-size:11px; }
  .kop-rule{ border-bottom:4px double #000; margin:6px 0 18px; }

  h1.doc-title{ text-align:center; font-size:14px; font-weight:bold; text-decoration:underline; margin:0 0 18px; letter-spacing:.2px; }
  p.doc-p{ margin:0 0 12px; font-size:13.5px; line-height:1.6; }

  table.fields{ width:100%; margin:0 0 14px 22px; font-size:13.5px; }
  table.fields td{ padding:3px 0; vertical-align:bottom; }
  table.fields td.lbl{ width:108px; color:#15171a; }
  table.fields td.colon{ width:14px; }
  .blank-input{
    width:100%; border:none; border-bottom:1.3px dotted #777; background:transparent;
    font-family:inherit; font-size:13.5px; padding:2px 2px; outline:none;
  }
  .blank-input:focus{ border-bottom-color:var(--primary); }
  .blank-text{ border-bottom:1px solid transparent; padding:2px 2px; display:inline-block; min-width:120px; }

  table.items{ width:100%; border-collapse:collapse; margin:0 0 14px; border:1px solid #000; }
  table.items th, table.items td{ border:1px solid #000; padding:6px 8px; font-size:13px; text-align:left; }
  table.items th{ text-align:center; font-weight:bold; background:#F0F0F0; }
  table.items td.no{ text-align:center; width:8%; }
  table.items td.jml{ width:16%; }
  table.items input{ width:100%; border:none; background:transparent; font-family:inherit; font-size:13px; padding:2px; outline:none; }
  table.items input:focus{ background:var(--primary-50); }
  .rm-row-btn{ border:none; background:transparent; color:var(--danger); cursor:pointer; font-size:14px; padding:0 4px; }
  .rm-row-btn:disabled{ opacity:.3; cursor:not-allowed; }
  .items-actions{ display:flex; justify-content:space-between; align-items:center; margin:-4px 0 18px; }

  table.signrow{ width:100%; border-collapse:collapse; margin-top:26px; text-align:center; }
  table.signrow td{ width:50%; vertical-align:top; padding:0 14px; font-size:13.5px; }
  .sig-canvas-wrap{ border:1px solid var(--border); border-radius:8px; background:#fcfdfd; position:relative; overflow:hidden; margin:8px 0; }
  .sig-canvas-wrap canvas{ display:block; width:100%; height:110px; touch-action:none; cursor:crosshair; }
  .sig-placeholder{ position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:#b9c2c2; font-size:12px; pointer-events:none; }
  .sig-img-box{ height:90px; display:flex; align-items:center; justify-content:center; margin:8px 0; }
  .sig-img-box img{ max-height:85px; max-width:200px; }
  .side-field{ text-align:left; margin-bottom:8px; }
  .side-field label{ display:block; font-size:11px; color:#5B7077; font-weight:600; text-transform:uppercase; margin-bottom:2px; }
  .side-field input{ width:100%; border:1px solid var(--border); border-radius:6px; padding:6px 9px; font-size:13.5px; font-family:inherit; }
  .side-card{ border:1px dashed var(--border); border-radius:10px; padding:14px; margin-top:10px; background:#FAFCFC; }

  .mengetahui{ text-align:center; margin-top:30px; font-size:13.5px; line-height:1.7; }
  .mengetahui .gap{ height:55px; }

  @page{ size: A4; margin: 10mm 14mm; }

  @media print{
    .topbar, .alert, .no-print{ display:none !important; }
    body{ background:#fff; padding:0; font-size:12.5px; }
    .doc{ border:none; box-shadow:none; padding:0; max-width:100%; }
    .blank-input, .side-card{ display:none !important; }
    table.kop td.logo-l img, table.kop td.logo-r img{ max-height:80px; }
    .kop-rule{ margin:4px 0 12px; }
    h1.doc-title{ margin-bottom:12px; }
    p.doc-p{ margin-bottom:8px; }
    table.fields{ margin-bottom:10px; }
    table.signrow{ margin-top:16px; }
    .sig-img-box{ height:65px; margin:4px 0; }
    .mengetahui{ margin-top:18px; }
    .mengetahui .gap{ height:35px; }
  }
</style>
</head>
<body>

<?php if (!$ba): ?>
  <div class="alert alert-danger"><?= h($error ?: 'Token Berita Acara tidak ditemukan.') ?></div>
<?php else: ?>

  <div class="topbar no-print">
    <span class="badge <?= $ba['status']==='complete' ? 'badge-complete' : 'badge-open' ?>">
      Token <?= h($ba['code']) ?> &mdash; <?= $ba['status']==='complete' ? 'Lengkap (Terkunci)' : 'Belum Lengkap' ?>
    </span>
    <div style="display:flex; gap:8px;">
      <a class="btn btn-outline" href="<?= APP_URL ?>/berita_acara?code=<?= h($code) ?>">&#8635; Muat Ulang</a>
      <?php if ($ba['status']==='complete'): ?>
        <button type="button" class="btn btn-warning" onclick="window.print()">&#128424; Cetak</button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($notice): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

  <div class="doc">
  <form id="mainForm" method="post">
  <input type="hidden" name="code" value="<?= h($code) ?>">

  <table class="print-wrap">
  <thead><tr><td>

    <table class="kop">
      <tr>
        <td class="logo-l"><img src="<?= APP_URL ?>/assets/icons/logo-kabupaten.png" alt="Logo Kabupaten Karawang"></td>
        <td class="kop-text">
          <div class="l1">PEMERINTAH KABUPATEN KARAWANG</div>
          <div class="l2">DINAS KESEHATAN</div>
          <div class="l3">UNIT ORGANISASI BERSIFAT KHUSUS</div>
          <div class="l4">RUMAH SAKIT UMUM DAERAH KARAWANG</div>
          <div class="l5">Jalan Galuh Mas Raya No. 1 Karawang</div>
          <div class="l6">Telepon : (0267) 640444, 640555 Pos-el : rsudkrw@yahoo.co.id Kode Pos 41361</div>
        </td>
        <td class="logo-r"><img src="<?= APP_URL ?>/assets/icons/logo-rsud.png" alt="Logo RSUD Karawang"></td>
      </tr>
    </table>
    <div class="kop-rule"></div>

  </td></tr></thead>
  <tbody><tr><td>

    <h1 class="doc-title">BERITA ACARA SERAH TERIMA PERANGKAT KERAS</h1>

    <?php
      $d = (int)date('j', strtotime($ba['tanggal']));
      $m = bulan_indo(date('n', strtotime($ba['tanggal'])));
      $y = date('Y', strtotime($ba['tanggal']));
      $menyerahkanLocked = !empty($ba['menyerahkan_signature_path']);
      $menerimaLocked    = !empty($ba['menerima_signature_path']);
      $isComplete        = $ba['status'] === 'complete';
    ?>

    <p class="doc-p">Pada hari ini Tanggal <?= $d ?> Bulan <?= $m ?> Tahun <?= $y ?>, yang bertanda tangan dibawah ini kami:</p>

    <table class="fields">
      <tr><td class="lbl">Nama</td><td class="colon">:</td><td>
        <?php if ($menyerahkanLocked): ?><span class="blank-text"><?= h($ba['menyerahkan_nama']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menyerahkan_nama" value="<?= h($_POST['menyerahkan_nama'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">NIP</td><td class="colon">:</td><td>
        <?php if ($menyerahkanLocked): ?><span class="blank-text"><?= h($ba['menyerahkan_nip']) ?: '&mdash;' ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menyerahkan_nip" value="<?= h($_POST['menyerahkan_nip'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">Unit Kerja</td><td class="colon">:</td><td>
        <?php if ($menyerahkanLocked): ?><span class="blank-text"><?= h($ba['menyerahkan_unit_kerja']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menyerahkan_unit_kerja" value="<?= h($_POST['menyerahkan_unit_kerja'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">Jabatan</td><td class="colon">:</td><td>
        <?php if ($menyerahkanLocked): ?><span class="blank-text"><?= h($ba['menyerahkan_jabatan']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menyerahkan_jabatan" value="<?= h($_POST['menyerahkan_jabatan'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
    </table>

    <p class="doc-p">Menyerahkan perangkat keras kepada :</p>

    <table class="fields">
      <tr><td class="lbl">Nama</td><td class="colon">:</td><td>
        <?php if ($menerimaLocked): ?><span class="blank-text"><?= h($ba['menerima_nama']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menerima_nama" value="<?= h($_POST['menerima_nama'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">NIP</td><td class="colon">:</td><td>
        <?php if ($menerimaLocked): ?><span class="blank-text"><?= h($ba['menerima_nip']) ?: '&mdash;' ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menerima_nip" value="<?= h($_POST['menerima_nip'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">Unit Kerja</td><td class="colon">:</td><td>
        <?php if ($menerimaLocked): ?><span class="blank-text"><?= h($ba['menerima_unit_kerja']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menerima_unit_kerja" value="<?= h($_POST['menerima_unit_kerja'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
      <tr><td class="lbl">Jabatan</td><td class="colon">:</td><td>
        <?php if ($menerimaLocked): ?><span class="blank-text"><?= h($ba['menerima_jabatan']) ?></span>
        <?php else: ?><input type="text" class="blank-input" name="menerima_jabatan" value="<?= h($_POST['menerima_jabatan'] ?? '') ?>" placeholder="…………………………………"><?php endif; ?>
      </td></tr>
    </table>

    <p class="doc-p">Dengan Rincian Sebagai Berikut</p>

    <?php $itemsLocked = !empty($ba['items_locked']); ?>

    <?php if ($itemsLocked): ?>
      <table class="items">
        <thead><tr><th style="width:8%;">No</th><th>Nama Perangkat Keras</th><th class="jml">Jumlah</th></tr></thead>
        <tbody>
          <?php if ($items): foreach ($items as $i => $it): ?>
          <tr>
            <td class="no"><?= $i+1 ?></td>
            <td><?= h($it['nama'] ?? '') ?></td>
            <td class="jml"><?= h($it['jumlah'] ?? '') ?></td>
          </tr>
          <?php endforeach; else: ?>
          <tr><td class="no">&nbsp;</td><td>&nbsp;</td><td class="jml">&nbsp;</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    <?php else: ?>
    <div>
      <table class="items">
        <thead><tr><th style="width:8%;">No</th><th>Nama Perangkat Keras</th><th class="jml">Jumlah</th><th class="no-print" style="width:30px;"></th></tr></thead>
        <tbody id="itemRows">
          <?php if ($items): foreach ($items as $i => $it): ?>
          <tr>
            <td class="no"><?= $i+1 ?></td>
            <td><input type="text" name="hw_name[]" value="<?= h($it['nama'] ?? '') ?>" placeholder="Nama perangkat"></td>
            <td class="jml"><input type="text" name="hw_jumlah[]" value="<?= h($it['jumlah'] ?? '') ?>" placeholder="Jumlah"></td>
            <td class="no-print"><button type="button" class="rm-row-btn">&times;</button></td>
          </tr>
          <?php endforeach; else: ?>
          <tr>
            <td class="no">1</td>
            <td><input type="text" name="hw_name[]" placeholder="Nama perangkat"></td>
            <td class="jml"><input type="text" name="hw_jumlah[]" placeholder="Jumlah"></td>
            <td class="no-print"><button type="button" class="rm-row-btn" disabled>&times;</button></td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="items-actions no-print">
        <button type="button" class="btn btn-outline" id="addItemBtn">+ Tambah Perangkat</button>
        <button type="submit" name="action" value="save_items" class="btn btn-primary">Simpan Daftar Perangkat (sekali simpan, terkunci)</button>
      </div>
    </div>
    <?php endif; ?>

    <p class="doc-p">Perangkat keras tersebut dalam keadaan baik dan berfungsi. Sejak penandatanganan berita acara ini, maka perangkat keras tersebut menjadi tanggung jawab <strong>penerima,</strong> agar memelihara / merawat dengan baik serta dipergunakan untuk keperluan sebagaimana mestinya.</p>

    <p class="doc-p">Demikian berita acara serah terima perangkat keras ini dibuat oleh kedua belah pihak.</p>

    <table class="signrow">
      <tr>
        <td>Yang menyerahkan</td>
        <td>Yang menerima</td>
      </tr>
      <tr>
        <td>
          <?php if ($menyerahkanLocked): ?>
            <div class="sig-img-box"><img src="<?= APP_URL ?>/<?= h($ba['menyerahkan_signature_path']) ?>" alt="TTD Menyerahkan"></div>
          <?php else: ?>
            <div class="sig-canvas-wrap no-print">
              <canvas id="sigMenyerahkan" class="sig-canvas"></canvas>
              <div class="sig-placeholder">Tanda tangan di sini</div>
            </div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($menerimaLocked): ?>
            <div class="sig-img-box"><img src="<?= APP_URL ?>/<?= h($ba['menerima_signature_path']) ?>" alt="TTD Menerima"></div>
          <?php else: ?>
            <div class="sig-canvas-wrap no-print">
              <canvas id="sigMenerima" class="sig-canvas"></canvas>
              <div class="sig-placeholder">Tanda tangan di sini</div>
            </div>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <td>( <?= h($ba['menyerahkan_nama']) ?: '…………………………' ?> )</td>
        <td>( <?= h($ba['menerima_nama']) ?: '…………………………' ?> )</td>
      </tr>
      <tr class="no-print">
        <td>
          <?php if (!$menyerahkanLocked): ?>
          <div class="side-card">
            <input type="hidden" name="menyerahkan_signature_data" id="sigDataMenyerahkan">
            <button type="button" class="btn-danger-outline" id="clearSigMenyerahkan">Hapus TTD</button>
            <button type="submit" name="action" value="save_menyerahkan" class="btn btn-primary" style="width:100%; margin-top:8px;">Simpan &amp; TTD (Menyerahkan)</button>
          </div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$menerimaLocked): ?>
          <div class="side-card">
            <input type="hidden" name="menerima_signature_data" id="sigDataMenerima">
            <button type="button" class="btn-danger-outline" id="clearSigMenerima">Hapus TTD</button>
            <button type="submit" name="action" value="save_menerima" class="btn btn-primary" style="width:100%; margin-top:8px;">Simpan &amp; TTD (Menerima)</button>
          </div>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <div class="mengetahui">
      Mengetahui<br>
      Ka. Instalasi SIMRS
      <div class="gap"></div>
      dr. Ucu Nurhadiat, Sp,An.<br>
      Nip. 19771012 201001 1 007
    </div>

  </td></tr></tbody>
  </table>
  </form>


  </div>

<?php endif; ?>

<script>
(function () {
  function getCroppedSignature(canvas) {
    const c = canvas.getContext('2d');
    const w = canvas.width, h = canvas.height;
    let data;
    try { data = c.getImageData(0, 0, w, h).data; } catch (e) { return canvas.toDataURL('image/png'); }
    let minX = w, minY = h, maxX = 0, maxY = 0, found = false;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (data[(y * w + x) * 4 + 3] > 10) {
          found = true;
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
    if (!found) return null;
    const pad = 14;
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
    maxX = Math.min(w, maxX + pad); maxY = Math.min(h, maxY + pad);
    const cw = maxX - minX, ch = maxY - minY;
    const out = document.createElement('canvas');
    out.width = cw; out.height = ch;
    out.getContext('2d').drawImage(canvas, minX, minY, cw, ch, 0, 0, cw, ch);
    return out.toDataURL('image/png');
  }

  function setupSigPad(canvas, clearBtnId) {
    if (!canvas) return;
    const wrap = canvas.closest('.sig-canvas-wrap');
    const placeholder = wrap ? wrap.querySelector('.sig-placeholder') : null;
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * ratio;
    canvas.height = 110 * ratio;
    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.1; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#15171a';
    let drawing = false, last = null, hasContent = false;
    canvas._hasContent = function () { return hasContent; };
    canvas._clear = function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasContent = false;
      if (placeholder) placeholder.style.display = 'block';
    };

    function pos(e) { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function start(e) { e.preventDefault(); drawing = true; hasContent = true; if (placeholder) placeholder.style.display = 'none'; last = pos(e); }
    function move(e) { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; }
    function end() { drawing = false; }

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);

    const clearBtn = document.getElementById(clearBtnId);
    if (clearBtn) clearBtn.addEventListener('click', function () { canvas._clear(); });
  }

  function setupItemRows() {
    const wrap = document.getElementById('itemRows');
    const addBtn = document.getElementById('addItemBtn');
    if (!wrap) return;
    function renumber() {
      const rows = wrap.querySelectorAll('tr');
      rows.forEach(function (row, i) { row.querySelector('.no').textContent = i + 1; });
      wrap.querySelectorAll('.rm-row-btn').forEach(function (b) { b.disabled = (rows.length <= 1); });
    }
    if (addBtn) addBtn.addEventListener('click', function () {
      const row = document.createElement('tr');
      row.innerHTML =
        '<td class="no"></td>' +
        '<td><input type="text" name="hw_name[]" placeholder="Nama perangkat"></td>' +
        '<td class="jml"><input type="text" name="hw_jumlah[]" placeholder="Jumlah"></td>' +
        '<td class="no-print"><button type="button" class="rm-row-btn">&times;</button></td>';
      row.querySelector('.rm-row-btn').addEventListener('click', function () { row.remove(); renumber(); });
      wrap.appendChild(row);
      renumber();
    });
    wrap.querySelectorAll('.rm-row-btn').forEach(function (b) {
      b.addEventListener('click', function () { b.closest('tr').remove(); renumber(); });
    });
    renumber();
  }

  function setupMainFormSubmit() {
    const form = document.getElementById('mainForm');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      const submitter = e.submitter; // tombol yang benar-benar diklik
      const action = submitter ? submitter.value : '';

      if (action === 'save_menyerahkan') {
        const canvas = document.getElementById('sigMenyerahkan');
        if (canvas) {
          if (!canvas._hasContent()) {
            e.preventDefault();
            alert('Mohon tanda tangan (Yang Menyerahkan) terlebih dahulu.');
            return;
          }
          document.getElementById('sigDataMenyerahkan').value = getCroppedSignature(canvas) || '';
        }
      } else if (action === 'save_menerima') {
        const canvas = document.getElementById('sigMenerima');
        if (canvas) {
          if (!canvas._hasContent()) {
            e.preventDefault();
            alert('Mohon tanda tangan (Yang Menerima) terlebih dahulu.');
            return;
          }
          document.getElementById('sigDataMenerima').value = getCroppedSignature(canvas) || '';
        }
      }
      // action === 'save_items' tidak perlu validasi tambahan di sini
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupItemRows();
    setupSigPad(document.getElementById('sigMenyerahkan'), 'clearSigMenyerahkan');
    setupSigPad(document.getElementById('sigMenerima'), 'clearSigMenerima');
    setupMainFormSubmit();
  });
})();
</script>

</body>
</html>