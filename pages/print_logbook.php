<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function tgl_indo($ymd) {
    if (!$ymd) return '-';
    $bulan = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
              '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) return h($ymd);
    $y = $parts[0]; $m = $parts[1]; $d = $parts[2];
    return ((int)$d) . ' ' . (isset($bulan[$m]) ? $bulan[$m] : $m) . ' ' . $y;
}

$db = getDB();

// ── Filter (sama seperti logbook.php, supaya hasil cetak = hasil filter) ──
$search        = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_dari   = $_GET['dari'] ?? '';
$filter_sampai = $_GET['sampai'] ?? '';

$where  = ["1=1"];
$params = [];
if ($search) {
    $where[] = "(ruangan LIKE ? OR nama_user LIKE ? OR subject LIKE ? OR masalah LIKE ?)";
    $params  = array_merge($params, array_fill(0, 4, "%$search%"));
}
if ($filter_status) { $where[] = "status = ?"; $params[] = $filter_status; }
if ($filter_dari)   { $where[] = "tanggal_lapor >= ?"; $params[] = $filter_dari; }
if ($filter_sampai) { $where[] = "tanggal_lapor <= ?"; $params[] = $filter_sampai; }
$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("SELECT * FROM logbook WHERE $whereStr AND ruangan IS NOT NULL ORDER BY tanggal_lapor ASC, id ASC");
$stmt->execute($params);
$data = $stmt->fetchAll();

// Label periode untuk judul cetak
$periode = 'Semua Data';
if ($filter_dari && $filter_sampai) {
    $periode = tgl_indo($filter_dari) . ' s/d ' . tgl_indo($filter_sampai);
} elseif ($filter_dari) {
    $periode = 'Sejak ' . tgl_indo($filter_dari);
} elseif ($filter_sampai) {
    $periode = 'Sampai ' . tgl_indo($filter_sampai);
}

// Tanggal cetak (untuk baris "Karawang, ...")
// - Kalau filter bulan = bulan berjalan (atau tidak difilter) -> pakai tanggal hari ini
// - Kalau filter bulan beda dari bulan berjalan -> pakai hari kerja TERAKHIR di bulan itu (bukan Sabtu/Minggu)
$bulanCetak = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
               '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

$bulanBerjalan = date('Y-m');
$sampaiBulan   = $filter_sampai ? substr($filter_sampai, 0, 7) : '';

if ($filter_sampai && $sampaiBulan !== $bulanBerjalan) {
    // Filter "sampai" bukan bulan berjalan -> pakai hari kerja terakhir di bulan "sampai" itu
    $tglAcuan = new DateTime($sampaiBulan . '-01');
    $tglAcuan->modify('last day of this month');
    while ((int)$tglAcuan->format('N') >= 6) { // 6=Sabtu, 7=Minggu
        $tglAcuan->modify('-1 day');
    }
    $cetak_hari  = $tglAcuan->format('d');
    $cetak_bulan = $bulanCetak[$tglAcuan->format('m')];
    $cetak_tahun = $tglAcuan->format('Y');
} else {
    $cetak_hari  = date('d');
    $cetak_bulan = $bulanCetak[date('m')];
    $cetak_tahun = date('Y');
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak Logbook Hardware SIMRS</title>
<style id="pageOrientationStyle">
  @page { size: A4 landscape; margin: 12mm 14mm; }
</style>
<style>
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color:#000; margin:0; }
  .wrap { max-width: 1000px; margin: 0 auto; }

  /* ── KOP SURAT ───────────────────────────────── */
  table.kop { width:100%; border-collapse:collapse; margin-bottom:0; }
  table.kop td { vertical-align:middle; padding:0 8px; }
  table.kop td.logo-left  { width:100px; text-align:left; }
  table.kop td.logo-right { width:110px; text-align:right; }
  table.kop td.logo-left img, table.kop td.logo-right img { max-width:100%; max-height:95px; }
  table.kop td.kop-text { text-align:center; line-height:1.35; }
  .kop-text .l1 { font-size:13px; }
  .kop-text .l2 { font-size:13px; }
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
  table.data td.empty-row { height:22px; }

  /* ── Mode Portrait: kolom & font dikecilkan biar tetap muat ── */
  body.portrait-mode .wrap { max-width: 720px; }
  body.portrait-mode table.data th { font-size:8.5px; padding:3px 3px; }
  body.portrait-mode table.data td { font-size:7.8px; padding:3px 3px; }
  body.portrait-mode .kop-text .l1, body.portrait-mode .kop-text .l2 { font-size:11px; }
  body.portrait-mode .kop-text .l3 { font-size:12px; }
  body.portrait-mode .kop-text .l4 { font-size:13px; }
  body.portrait-mode .kop-text .l5, body.portrait-mode .kop-text .l6 { font-size:9.5px; }

  /* ── Tanda tangan 2 kolom (sesuai contoh) ────── */
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
  @media print { .toolbar, .no-print { display:none !important; } }
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="cetak('landscape')">&#128424; Cetak Landscape</button>
  <button class="outline" onclick="cetak('portrait')">&#128424; Cetak Portrait</button>
</div>

<script>
function cetak(orientasi) {
    document.getElementById('pageOrientationStyle').innerHTML =
        '@page { size: A4 ' + orientasi + '; margin: 12mm 14mm; }';
    document.body.classList.toggle('portrait-mode', orientasi === 'portrait');
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
  <div class="periode">Periode: <?= h($periode) ?></div>

  <table class="data">
    <thead>
      <tr>
        <th style="width:3%;">No</th>
        <th style="width:7%;">Tgl Lapor</th>
        <th style="width:7%;">Tgl Selesai</th>
        <th style="width:10%;">Ruangan</th>
        <th style="width:8%;">User</th>
        <th style="width:12%;">Subject</th>
        <th style="width:17%;">Masalah</th>
        <th style="width:17%;">Solusi</th>
        <th style="width:8%;">Petugas</th>
        <th style="width:5%;">Urgensi</th>
        <th style="width:6%;">Status</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$data): ?>
      <tr><td colspan="11" class="center">Tidak ada data untuk periode ini.</td></tr>
      <?php else: $no = 1; foreach ($data as $row): ?>
      <tr>
        <td class="no"><?= $no++ ?></td>
        <td class="center"><?= $row['tanggal_lapor'] ? date('d/m/y', strtotime($row['tanggal_lapor'])) : '-' ?></td>
        <td class="center"><?= $row['tanggal_selesai'] ? date('d/m/y', strtotime($row['tanggal_selesai'])) : '-' ?></td>
        <td><?= h($row['ruangan'] ?? '-') ?></td>
        <td><?= h($row['nama_user'] ?? '-') ?></td>
        <td><?= h($row['subject'] ?? '-') ?></td>
        <td><?= h($row['masalah'] ?? '-') ?></td>
        <td><?= h($row['solusi'] ?? '-') ?></td>
        <td><?= h($row['petugas_menyelesaikan'] ?: ($row['petugas_dilaporkan'] ?? '-')) ?></td>
        <td class="center"><?= h($row['tingkat_urgensi'] ?? '-') ?></td>
        <td class="center"><?= h($row['status'] ?? '-') ?></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <table class="sign2">
    <tr>
      <td style="text-align:left;">&nbsp;</td>
      <td class="tgl-cetak">Karawang, &nbsp;<?= h($cetak_hari) ?>&nbsp; <?= h($cetak_bulan) ?> <?= h($cetak_tahun) ?></td>
    </tr>
    <tr>
      <td>
        <div class="jabatan">Ka. Instalasi SIMRS</div>
        <div class="ttd-slot">&nbsp;</div>
        <div class="nama">dr. Ucu Nurhadiat, Sp.An</div>
        <div class="nip">NIP. 19771012 201001 1 007</div>
      </td>
      <td>
        <div class="jabatan">Pj. Hardware dan Inventory</div>
        <div class="ttd-slot"><img src="<?= APP_URL ?>/uploads/s/riky_irawan.png" alt="TTD Riky Irawan" class="ttd-img"></div>
        <div class="nama">Riky Irawan, ST</div>
        <div class="nip">NIP. 19820809 202321 1 008</div>
      </td>
    </tr>
  </table>

</div>
</body>
</html>