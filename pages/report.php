<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Laporan');
$db = getDB();

// Filters
$type      = $_GET['type'] ?? '';
$status    = $_GET['status'] ?? '';
$building  = cleanInt($_GET['building'] ?? 0);
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to'] ?? '';
$report_type = $_GET['report_type'] ?? 'assets'; // assets | maintenance

$buildings = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();
$type_labels = [
    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
];

// ── ASSETS REPORT ──────────────────────────────────────
$assets = [];
if ($report_type === 'assets') {
    $where = ["a.is_registered = 1"];
    $params = [];
    if ($type)     { $where[] = "a.type = ?"; $params[] = $type; }
    if ($status)   { $where[] = "a.condition_status = ?"; $params[] = $status; }
    if ($building) { $where[] = "a.building_id = ?"; $params[] = $building; }

    $stmt = $db->prepare("
        SELECT a.*, b.name as building_name, r.name as room_name, u.name as registered_by_name
        FROM assets a
        LEFT JOIN buildings b ON b.id = a.building_id
        LEFT JOIN rooms r ON r.id = a.room_id
        LEFT JOIN users u ON u.id = a.registered_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.asset_code
    ");
    $stmt->execute($params);
    $assets = $stmt->fetchAll();
}

// ── MAINTENANCE REPORT ─────────────────────────────────
$maintenance = [];
if ($report_type === 'maintenance') {
    $where = ["1=1"];
    $params = [];
    if ($date_from) { $where[] = "ml.performed_at >= ?"; $params[] = $date_from . ' 00:00:00'; }
    if ($date_to)   { $where[] = "ml.performed_at <= ?"; $params[] = $date_to . ' 23:59:59'; }
    if ($building)  { $where[] = "a.building_id = ?"; $params[] = $building; }
    if ($type)      { $where[] = "a.type = ?"; $params[] = $type; }

    $stmt = $db->prepare("
        SELECT ml.*, a.asset_code, a.name as asset_name, a.type as asset_type,
               b.name as building_name, r.name as room_name, u.name as user_name
        FROM maintenance_logs ml
        LEFT JOIN assets a ON a.id = ml.asset_id
        LEFT JOIN buildings b ON b.id = a.building_id
        LEFT JOIN rooms r ON r.id = a.room_id
        JOIN users u ON u.id = ml.performed_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY ml.performed_at DESC
    ");
    $stmt->execute($params);
    $maintenance = $stmt->fetchAll();
}

// ── SEMESTER REPORT ──
$sem_type     = $_GET['sem_type'] ?? '';
$sem_building = cleanInt($_GET['sem_building'] ?? 0);
$sem_semester = $_GET['sem_semester'] ?? '1';
$sem_triwulan = $_GET['sem_triwulan'] ?? '1';
$sem_year     = cleanInt($_GET['sem_year'] ?? date('Y'));
if (!$sem_year) { $sem_year = (int)date('Y'); }

// TAMBAHAN: rentang tanggal bebas (custom), dipilih lewat 2 input date
// di form. Kalau dipilih tapi salah satu tanggal kosong, fallback ke
// Semester 1 tahun berjalan (default lama) supaya gak error.
$sem_custom_from = $_GET['sem_custom_from'] ?? '';
$sem_custom_to   = $_GET['sem_custom_to'] ?? '';

if ($sem_semester == 'custom' && $sem_custom_from && $sem_custom_to) {
    $sem_start = $sem_custom_from . ' 00:00:00';
    $sem_end   = $sem_custom_to . ' 23:59:59';
    $sem_label = 'Rentang ' . date('d M Y', strtotime($sem_custom_from)) . ' - ' . date('d M Y', strtotime($sem_custom_to));
} elseif ($sem_semester == '2') {
    $sem_start = "$sem_year-07-01 00:00:00";
    $sem_end   = "$sem_year-12-31 23:59:59";
    $sem_label = "Semester 2 (Juli - Desember $sem_year)";
} elseif ($sem_semester == 'all') {
    $sem_start = "$sem_year-01-01 00:00:00";
    $sem_end   = "$sem_year-12-31 23:59:59";
    $sem_label = "Semua Semester (Januari - Desember $sem_year)";
} elseif ($sem_semester == 'triwulan') {
    switch ($sem_triwulan) {
        case '2':
            $sem_start = "$sem_year-04-01 00:00:00";
            $sem_end   = "$sem_year-06-30 23:59:59";
            $sem_label = "Triwulan 2 (April - Juni $sem_year)";
            break;
        case '3':
            $sem_start = "$sem_year-07-01 00:00:00";
            $sem_end   = "$sem_year-09-30 23:59:59";
            $sem_label = "Triwulan 3 (Juli - September $sem_year)";
            break;
        case '4':
            $sem_start = "$sem_year-10-01 00:00:00";
            $sem_end   = "$sem_year-12-31 23:59:59";
            $sem_label = "Triwulan 4 (Oktober - Desember $sem_year)";
            break;
        default:
            $sem_start = "$sem_year-01-01 00:00:00";
            $sem_end   = "$sem_year-03-31 23:59:59";
            $sem_label = "Triwulan 1 (Januari - Maret $sem_year)";
            break;
    }
} else {
    $sem_start = "$sem_year-01-01 00:00:00";
    $sem_end   = "$sem_year-06-30 23:59:59";
    $sem_label = "Semester 1 (Januari - Juni $sem_year)";
}
$sem_where = ["a.is_registered = 1", "a.registered_at BETWEEN ? AND ?"];
$sem_params = [$sem_start, $sem_end];
if ($sem_type)     { $sem_where[] = "a.type = ?"; $sem_params[] = $sem_type; }
if ($sem_building) { $sem_where[] = "a.building_id = ?"; $sem_params[] = $sem_building; }
$sem_where_sql = implode(' AND ', $sem_where);

$stmt = $db->prepare("
    SELECT a.*, b.name as building_name, r.name as room_name
    FROM assets a
    LEFT JOIN buildings b ON b.id = a.building_id
    LEFT JOIN rooms r ON r.id = a.room_id
    WHERE $sem_where_sql
    ORDER BY b.name, r.name, a.asset_code
");
$stmt->execute($sem_params);
$semester_data = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT COALESCE(b.name, 'Tanpa Gedung') as building_name, COUNT(a.id) as total
    FROM assets a
    LEFT JOIN buildings b ON b.id = a.building_id
    WHERE $sem_where_sql
    GROUP BY b.id, b.name
    ORDER BY total DESC
");
$stmt->execute($sem_params);
$by_building = $stmt->fetchAll();

$stmt = $db->prepare("
    SELECT COALESCE(b.name, 'Tanpa Gedung') as building_name,
           COALESCE(r.name, 'Tanpa Ruangan') as room_name,
           a.type, COUNT(a.id) as total
    FROM assets a
    LEFT JOIN rooms r ON r.id = a.room_id
    LEFT JOIN buildings b ON b.id = a.building_id
    WHERE $sem_where_sql
    GROUP BY b.id, b.name, r.id, r.name, a.type
    ORDER BY b.name, r.name
");
$stmt->execute($sem_params);
$by_room_type = $stmt->fetchAll();

$room_pivot = [];
$room_totals = [];
$room_type_used = [];
foreach ($by_room_type as $row) {
    $b = $row['building_name'];
    $r = $row['room_name'];
    $t = $row['type'];
    $cnt = (int)$row['total'];
    if (!isset($room_pivot[$b][$r])) $room_pivot[$b][$r] = [];
    $room_pivot[$b][$r][$t] = $cnt;
    $room_totals[$b][$r] = ($room_totals[$b][$r] ?? 0) + $cnt;
    if ($cnt > 0) $room_type_used[$t] = true;
}

$room_pivot_types = [];
foreach ($type_labels as $k => $v) {
    if (!empty($room_type_used[$k])) $room_pivot_types[$k] = $v;
}

$by_room = [];
foreach ($room_pivot as $b => $rooms) {
    foreach ($rooms as $r => $counts) {
        $by_room[] = ['building_name' => $b, 'room_name' => $r];
    }
}

$stmt = $db->prepare("
    SELECT a.type, COUNT(a.id) as total
    FROM assets a
    WHERE $sem_where_sql
    GROUP BY a.type
");
$stmt->execute($sem_params);
$by_type_raw = $stmt->fetchAll();
$by_type_counts = [];
foreach ($by_type_raw as $row) {
    $by_type_counts[$row['type']] = (int)$row['total'];
}
$by_type = [];
foreach ($type_labels as $k => $v) {
    $by_type[] = [
        'key'   => $k,
        'label' => $v,
        'total' => $by_type_counts[$k] ?? 0
    ];
}
$by_type_chart = array_values(array_filter($by_type, function($t){ return $t['total'] > 0; }));

$sem_total_assets = count($semester_data);

// PERBAIKAN: sebelumnya cuma return 1 kategori (yang pertama ketemu),
// jadi laporan yang menyebut 2 hardware sekaligus (mis. "keyboard dan
// mouse mati") cuma kehitung di kategori yang urutannya lebih dulu
// dicek (Mouse), padahal Keyboard-nya juga harus kehitung. Sekarang
// return ARRAY semua kategori yang cocok, supaya 1 laporan bisa masuk
// ke lebih dari 1 bar chart kalau memang menyebut lebih dari 1 hardware.
function klasifikasiMasalahLogbook($teks) {
    $teks = strtolower($teks);
    $kategori = [
        'PC/Komputer' => ['pc','komputer','cpu','monitor','layar','hang','restart','blue screen','lemot','lambat','mati total','tidak nyala','windows','startup','RAM'],
        'Printer'     => ['printer','print','cetak','toner','catridge','cartridge','paper jam','kertas nyangkut','dot matrix','epson','canon','laserjet'],
        'Mouse'       => ['mouse','kursor','klik kanan','klik kiri','scroll'],
        'Keyboard'    => ['keyboard','tombol','ketikan','huruf tidak muncul'],
    ];
    $matched = [];
    foreach ($kategori as $nama => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($teks, $kw)) { $matched[] = $nama; break; }
        }
    }
    return $matched ?: ['Lain-lain'];
}

// ── PERUBAHAN DI SINI SAJA ──────────────────────────────────────────
// Sebelumnya cuma SELECT dari `logbook` (data manual). Ditambah UNION ALL
// ke tabel `tiket` (data hasil sync WhatsApp) supaya ikut dihitung di
// grafik Klasifikasi Masalah. Kolom subject/masalah di-COLLATE biar sama
// (perbaikan error "Illegal mix of collations" karena tabel `tiket` dan
// `logbook` dibuat dengan collation default yang berbeda).
$stmt = $db->prepare("
    SELECT subject COLLATE utf8mb4_unicode_ci AS subject,
           masalah COLLATE utf8mb4_unicode_ci AS masalah
    FROM logbook
    WHERE tanggal_lapor BETWEEN ? AND ?
    UNION ALL
    SELECT subject COLLATE utf8mb4_unicode_ci AS subject,
           masalah COLLATE utf8mb4_unicode_ci AS masalah
    FROM tiket
    WHERE tanggal_lapor BETWEEN ? AND ?
");
$stmt->execute([substr($sem_start, 0, 10), substr($sem_end, 0, 10), $sem_start, $sem_end]);
$logbook_rows = $stmt->fetchAll();
// ── AKHIR PERUBAHAN ──────────────────────────────────────────────────

$klas_labels = ['PC/Komputer', 'Printer', 'Mouse', 'Keyboard', 'Lain-lain'];
$klas_counts = array_fill_keys($klas_labels, 0);

foreach ($logbook_rows as $row) {
    $teks = ($row['subject'] ?? '') . ' ' . ($row['masalah'] ?? '');
    // Satu laporan bisa nambah ke lebih dari 1 kategori kalau memang
    // menyebut lebih dari 1 jenis hardware (mis. "keyboard dan mouse").
    foreach (klasifikasiMasalahLogbook($teks) as $kategoriCocok) {
        $klas_counts[$kategoriCocok]++;
    }
}
// klas_total = jumlah laporan asli (bukan jumlah tag), supaya badge "X laporan"
// tetap akurat meskipun sebagian laporan kehitung di >1 bar chart.
$klas_total = count($logbook_rows);

$stmt = $db->prepare("
    SELECT ml.*, a.asset_code, a.name as asset_name, u.name as user_name
    FROM maintenance_logs ml
    LEFT JOIN assets a ON a.id = ml.asset_id
    JOIN users u ON u.id = ml.performed_by
    WHERE ml.performed_at BETWEEN ? AND ?
    ORDER BY ml.performed_at DESC
");
$stmt->execute([$sem_start, $sem_end]);
$sem_maintenance = $stmt->fetchAll();

// ── TAMBAHAN: tiket (WhatsApp) yang sudah Closed pada periode yang sama
// ikut dihitung sebagai kegiatan maintenance juga, karena sekarang alur
// perbaikan lewat tiket. Pola try/catch sama seperti di logbook.php,
// supaya kalau tabel tiket belum ada / query gagal, halaman tidak error.
$sem_tiket_closed = [];
try {
    $stmtTk = $db->prepare("
        SELECT subject, masalah, solusi
        FROM tiket
        WHERE status = 'Closed' AND tanggal_lapor BETWEEN ? AND ?
    ");
    $stmtTk->execute([$sem_start, $sem_end]);
    $sem_tiket_closed = $stmtTk->fetchAll();
} catch (PDOException $e) {
    $sem_tiket_closed = [];
}
// ── AKHIR TAMBAHAN ───────────────────────────────────────────────────

function klasifikasiMaintenance($teks) {
    $teks = strtolower($teks);
    $kategori = [
        'Pendaftaran Ke Sistem'      => ['daftar','registrasi','register','pendaftaran'],
        'Pembersihan'      => ['bersih','cleaning','debu','lap kipas','servis rutin'],
        'Penambahan RAM'   => ['ram','memory','memori','upgrade ram','tambah ram'],
        'Pergantian'       => ['ganti','tukar','replace','pergantian','sparepart','spare part'],
    ];
    foreach ($kategori as $nama => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($teks, $kw)) return $nama;
        }
    }
    return 'Lain-lain';
}

$maint_labels = ['Pendaftaran Ke Sistem', 'Pembersihan', 'Penambahan RAM', 'Pergantian', 'Lain-lain'];
$maint_counts = array_fill_keys($maint_labels, 0);
foreach ($sem_maintenance as $row) {
    $teks = ($row['action'] ?? '') . ' ' . ($row['description'] ?? '');
    $maint_counts[klasifikasiMaintenance($teks)]++;
}
// TAMBAHAN: masukkan tiket Closed ke hitungan yang sama
foreach ($sem_tiket_closed as $row) {
    $teks = ($row['subject'] ?? '') . ' ' . ($row['masalah'] ?? '') . ' ' . ($row['solusi'] ?? '');
    $maint_counts[klasifikasiMaintenance($teks)]++;
}
$maint_total = array_sum($maint_counts);

// TAMBAHAN: exclude catatan "Pendaftaran aset pertama" -- itu bukan
// perpindahan lokasi beneran, cuma histori pas aset pertama kali
// didaftarkan ke sistem. Yang dihitung di sini cuma perpindahan/optimalisasi
// yang sesungguhnya (mis. "Update via maintenance" atau catatan pindah lain).
$stmt = $db->prepare("
    SELECT ll.*, a.asset_code, a.name as asset_name, u.name as user_name
    FROM location_logs ll
    JOIN assets a ON a.id = ll.asset_id
    JOIN users u ON u.id = ll.moved_by
    WHERE ll.moved_at BETWEEN ? AND ?
      AND (ll.notes IS NULL OR ll.notes NOT LIKE '%aset pertama%')
    ORDER BY ll.moved_at DESC
");
$stmt->execute([$sem_start, $sem_end]);
$sem_locations = $stmt->fetchAll();

// ── DATA MANUAL ──────────────────────────────────────────────────────
$man_kategori = $_GET['man_kategori'] ?? '';
$man_sumber   = $_GET['man_sumber'] ?? '';

$man_where = ["1=1"];
$man_params = [];
if ($man_kategori) { $man_where[] = "kategori = ?"; $man_params[] = $man_kategori; }
if ($man_sumber)   { $man_where[] = "sumber = ?";   $man_params[] = $man_sumber; }
$man_where_sql = implode(' AND ', $man_where);

$stmt = $db->prepare("SELECT * FROM manual_data WHERE $man_where_sql ORDER BY kategori, bagian, jenis_hardware");
$stmt->execute($man_params);
$manual_rows = $stmt->fetchAll();

$manual_kategori_list = $db->query("SELECT DISTINCT kategori FROM manual_data ORDER BY kategori")->fetchAll(PDO::FETCH_COLUMN);
$manual_sumber_list   = $db->query("SELECT DISTINCT sumber FROM manual_data ORDER BY sumber")->fetchAll(PDO::FETCH_COLUMN);

$manual_bagian_pairs = [];
foreach ($manual_rows as $r) {
    $manual_bagian_pairs[$r['kategori'] . '|' . $r['bagian']] = true;
}
$manual_bagian_count = count($manual_bagian_pairs);

$chart_palette = ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#858796','#5a5c69','#2c9faf','#f8a51b','#6f42c1'];

function klasifikasiHardwareManual($teks) {
    $teks = strtolower($teks);
    $printer_keywords = ['printer','print','cetak','epson','tsc','zebra','laserjet','canon'];
    foreach ($printer_keywords as $kw) {
        if (str_contains($teks, $kw)) return 'Printer';
    }
    return 'PC/Komputer';
}

$manual_pivot = [];
$manual_row_totals = [];
$manual_hw_used = [];
foreach ($manual_rows as $r) {
    $k = $r['kategori']; $hw = klasifikasiHardwareManual($r['jenis_hardware']); $j = (int)$r['jumlah'];
    if (!isset($manual_pivot[$k])) $manual_pivot[$k] = [];
    $manual_pivot[$k][$hw] = ($manual_pivot[$k][$hw] ?? 0) + $j;
    $manual_row_totals[$k] = ($manual_row_totals[$k] ?? 0) + $j;
    $manual_hw_used[$hw] = true;
}
$manual_hw_columns = array_keys($manual_hw_used);
sort($manual_hw_columns);
$manual_grand_total = array_sum(array_column($manual_rows, 'jumlah'));

$manual_groups = ['PC/Komputer' => [], 'Printer' => []];
foreach ($manual_rows as $r) {
    $grp = klasifikasiHardwareManual($r['jenis_hardware']);
    $manual_groups[$grp][] = $r;
}

// ── Build chart data per group ──
$chart_palette_kontras = [
    '#e6194b', '#3cb44b', '#4363d8', '#f58231', '#911eb4',
    '#42d4f4', '#f032e6', '#bfef45', '#469990', '#9A6324',
    '#800000', '#000075', '#a9a9a9', '#fabed4', '#dcbeff'
];

$manual_group_charts = [];
foreach ($manual_groups as $grp_name => $rows) {
    if (!$rows) continue;

    $by_kategori = [];
    foreach ($rows as $r) {
        $kat = $r['kategori'];
        $by_kategori[$kat] = ($by_kategori[$kat] ?? 0) + (int)$r['jumlah'];
    }
    arsort($by_kategori);

    $colors = [];
    $ci = 0;
    foreach ($by_kategori as $kat => $total) {
        $colors[$kat] = $chart_palette_kontras[$ci % count($chart_palette_kontras)];
        $ci++;
    }

    $manual_group_charts[$grp_name] = [
        'id'          => 'chartManualGroup' . preg_replace('/[^a-zA-Z0-9]/', '', $grp_name),
        'by_kategori' => $by_kategori,
        'colors'      => $colors,
        'total'       => array_sum($by_kategori),
    ];
}

// ── DATA PEMBELIAN (PENGADAAN) ────────────────────────────────────────
$poc_status = $_GET['poc_status'] ?? '';
$poc_search = trim($_GET['poc_q'] ?? '');
$poc_year   = $_GET['poc_tahun'] ?? date('Y');

$poc_where  = ['1=1'];
$poc_params = [];
if ($poc_status) { $poc_where[] = 'po.status = ?'; $poc_params[] = $poc_status; }
if ($poc_search) {
    $poc_where[]  = '(po.nomor_pengadaan LIKE ? OR po.vendor LIKE ? OR po.no_surat LIKE ?)';
    $poc_params[] = "%$poc_search%";
    $poc_params[] = "%$poc_search%";
    $poc_params[] = "%$poc_search%";
}
if ($poc_year) { $poc_where[] = 'YEAR(po.tanggal_pengadaan) = ?'; $poc_params[] = $poc_year; }

$stmt = $db->prepare("
      SELECT po.*,
           u.name AS created_by_name, po.tanggal_pengadaan,
           COUNT(pi.id) AS jumlah_item
    FROM procurement_orders po
    JOIN users u ON u.id = po.created_by
    LEFT JOIN procurement_items pi ON pi.procurement_id = po.id
    WHERE " . implode(' AND ', $poc_where) . "
    GROUP BY po.id
    ORDER BY po.tanggal_pengadaan DESC, po.id DESC
");
$stmt->execute($poc_params);
$poc_orders = $stmt->fetchAll();

// ── Deteksi Lokasi dari Catatan (match ke tabel rooms) ─────────────────
$all_rooms_list = $db->query("SELECT id, name FROM rooms ORDER BY name")->fetchAll();

function extractLokasiTokens($catatan) {
    if (!$catatan) return [];
    if (!preg_match('/\(([^()]+)\)\s*$/', trim($catatan), $m)) return [];
    $inside = trim($m[1]);
    $tokens = preg_split('/\s*(?:&|,|\bdan\b)\s*/i', $inside);
    $result = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t === '') continue;
        $clean = preg_replace('/^(R\.|Ruang\.?|Ka\.|Kepala)\s*/i', '', $t);
        $clean = trim($clean);
        if ($clean !== '') $result[] = $clean;
    }
    return $result;
}

function matchRoomsForCatatan($catatan, $all_rooms_list) {
    $tokens = extractLokasiTokens($catatan);
    $matched = [];
    foreach ($tokens as $tok) {
        foreach ($all_rooms_list as $room) {
            if (stripos($room['name'], $tok) !== false) {
                $matched[$room['id']] = $room['name'];
            }
        }
    }
    return array_values($matched);
}

// ── Ambil item per pengadaan ──
$poc_items_stmt = $db->prepare("SELECT nama_barang, jumlah FROM procurement_items WHERE procurement_id = ? ORDER BY id");

foreach ($poc_orders as &$o) {
    $o['lokasi_rooms'] = matchRoomsForCatatan($o['catatan'], $all_rooms_list);

    $poc_items_stmt->execute([$o['id']]);
    $poc_items_rows = $poc_items_stmt->fetchAll();

    $item_parts = [];
    foreach ($poc_items_rows as $pit) {
        $item_parts[] = trim($pit['nama_barang']) . ' (' . (int)$pit['jumlah'] . ')';
    }
    $o['item_parts'] = $item_parts;
}
unset($o);

$poc_stat = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(total_harga) AS total_nilai,
        SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft
    FROM procurement_orders
    WHERE YEAR(tanggal_pengadaan) = ?
");
$poc_stat->execute([$poc_year]);
$poc_summary = $poc_stat->fetch();

$poc_status_labels = [
    'draft'     => ['label' => 'Draft',     'class' => 'secondary'],
    'disetujui' => ['label' => 'Disetujui', 'class' => 'primary'],
    'diterima'  => ['label' => 'Diterima',  'class' => 'info'],
    'selesai'   => ['label' => 'Selesai',   'class' => 'success'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-bar-graph me-2 text-primary"></i>Laporan</h4>
    <button class="btn btn-outline-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print Laporan
    </button>
</div>

<ul class="nav nav-tabs mb-3 no-print" id="laporanTab" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-laporan-btn" type="button" data-tab-target="tab-laporan">
            <i class="bi bi-table me-1"></i>Laporan
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-semester-btn" type="button" data-tab-target="tab-semester">
            <i class="bi bi-bar-chart-line me-1"></i>Laporan Semester
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-manual-btn" type="button" data-tab-target="tab-manual">
            <i class="bi bi-journal-text me-1"></i>Data Manual (Excel)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-pembelian-btn" type="button" data-tab-target="tab-pembelian">
            <i class="bi bi-cart-check me-1"></i>Data Pembelian
        </button>
    </li>
</ul>

<div class="tab-content" id="laporanTabContent">

<!-- ══ TAB 1: LAPORAN ══ -->
<div class="tab-pane show active" id="tab-laporan" role="tabpanel">

<div class="card mb-3 no-print">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Jenis Laporan</label>
                <select name="report_type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="assets" <?= $report_type==='assets'?'selected':'' ?>>Inventaris Aset</option>
                    <option value="maintenance" <?= $report_type==='maintenance'?'selected':'' ?>>History Maintenance</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Tipe Hardware</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach ($type_labels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($report_type === 'assets'): ?>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Kondisi</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="baik" <?= $status==='baik'?'selected':'' ?>>Baik</option>
                    <option value="perlu_perhatian" <?= $status==='perlu_perhatian'?'selected':'' ?>>Perlu Perhatian</option>
                    <option value="rusak" <?= $status==='rusak'?'selected':'' ?>>Rusak</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Gedung</label>
                <select name="building" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $building==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($report_type === 'maintenance'): ?>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Dari Tanggal</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($date_from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Sampai Tanggal</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($date_to) ?>">
            </div>
            <?php endif; ?>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="?report_type=<?= clean($report_type) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="mb-3" id="print-header">
    <h5 class="fw-bold"><?= APP_NAME ?></h5>
    <div class="text-muted small">
        Laporan: <?= $report_type === 'assets' ? 'Inventaris Aset' : 'History Maintenance' ?>
        &bull; Dicetak: <?= date('d M Y H:i') ?>
        &bull; Oleh: <?= clean(currentUser()['name']) ?>
    </div>
    <hr>
</div>

<?php if ($report_type === 'assets'): ?>
<div class="card">
    <div class="card-header bg-light d-flex justify-content-between">
        <span><i class="bi bi-hdd-stack me-2"></i>Inventaris Aset</span>
        <span class="badge bg-primary"><?= count($assets) ?> aset</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>No</th><th>Kode Aset</th><th>Nama</th><th>Tipe</th>
                        <th>Brand/Model</th><th>S/N</th><th>Kondisi</th><th>Gedung</th><th>Ruangan</th><th>Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($assets): $no=1; foreach ($assets as $a): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><strong><?= clean($a['asset_code']) ?></strong></td>
                    <td><?= clean($a['name'] ?? '-') ?></td>
                    <td><?= clean($type_labels[$a['type']] ?? '-') ?></td>
                    <td><?= clean(($a['brand']??'') . ' ' . ($a['model']??'')) ?></td>
                    <td><small><?= clean($a['serial_number'] ?? '-') ?></small></td>
                    <td>
                        <?php $c = $a['condition_status'];
                        $cl = ['baik'=>'Baik','perlu_perhatian'=>'Perlu Perhatian','rusak'=>'Rusak']; ?>
                        <span class="badge badge-<?= $c ?>"><?= $cl[$c] ?></span>
                    </td>
                    <td><?= clean($a['building_name'] ?? '-') ?></td>
                    <td><?= clean($a['room_name'] ?? '-') ?></td>
                    <td><small><?= $a['registered_at'] ? date('d/m/y', strtotime($a['registered_at'])) : '-' ?></small></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="10" class="text-center py-4 text-muted">Tidak ada data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-header bg-light d-flex justify-content-between">
        <span><i class="bi bi-tools me-2"></i>History Maintenance</span>
        <span class="badge bg-primary"><?= count($maintenance) ?> kegiatan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr><th>No</th><th>Tanggal</th><th>Kode Aset</th><th>Nama Aset</th><th>Tipe</th><th>Lokasi</th><th>Kegiatan</th><th>Keterangan</th><th>Oleh</th></tr>
                </thead>
                <tbody>
                <?php if ($maintenance): $no=1; foreach ($maintenance as $m): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td><small><?= date('d/m/y H:i', strtotime($m['performed_at'])) ?></small></td>
                    <td><strong><?= clean($m['asset_code'] ?? '-') ?></strong></td>
                    <td><?= clean($m['asset_name'] ?? '-') ?></td>
                    <td><small><?= clean($type_labels[$m['asset_type']] ?? '-') ?></small></td>
                    <td><small><?= clean(($m['building_name']??'-') . ($m['room_name'] ? ' / '.$m['room_name'] : '')) ?></small></td>
                    <td><?= clean($m['action']) ?></td>
                    <td><small class="text-muted"><?= clean($m['description'] ?? '-') ?></small></td>
                    <td><?= clean($m['user_name']) ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">Tidak ada data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

</div>
<!-- END TAB 1 -->

<!-- ══ TAB 2: LAPORAN SEMESTER ══ -->
<div class="tab-pane" id="tab-semester" role="tabpanel">

    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end" id="semesterFilterForm">
                <input type="hidden" name="report_type" value="<?= clean($report_type) ?>">
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm">Tahun</label>
                    <input type="number" name="sem_year" class="form-control form-control-sm" value="<?= (int)$sem_year ?>" min="2000" max="2100">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm">Semester</label>
                    <select name="sem_semester" id="semSemesterSelect" class="form-select form-select-sm" onchange="toggleTriwulanSelect()">
                        <option value="all" <?= $sem_semester=='all'?'selected':'' ?>>Semua</option>
                        <option value="1" <?= $sem_semester=='1'?'selected':'' ?>>Semester 1 (Jan - Jun)</option>
                        <option value="2" <?= $sem_semester=='2'?'selected':'' ?>>Semester 2 (Jul - Des)</option>
                        <option value="triwulan" <?= $sem_semester=='triwulan'?'selected':'' ?>>Triwulan</option>
                        <option value="custom" <?= $sem_semester=='custom'?'selected':'' ?>>Rentang Kustom</option>
                    </select>
                </div>
                <div class="col-6 col-md-2" id="semTriwulanWrap" style="<?= $sem_semester=='triwulan' ? '' : 'display:none;' ?>">
                    <label class="form-label form-label-sm">Triwulan Ke-</label>
                    <select name="sem_triwulan" class="form-select form-select-sm">
                        <option value="1" <?= $sem_triwulan=='1'?'selected':'' ?>>TW 1 (Jan - Mar)</option>
                        <option value="2" <?= $sem_triwulan=='2'?'selected':'' ?>>TW 2 (Apr - Jun)</option>
                        <option value="3" <?= $sem_triwulan=='3'?'selected':'' ?>>TW 3 (Jul - Sep)</option>
                        <option value="4" <?= $sem_triwulan=='4'?'selected':'' ?>>TW 4 (Okt - Des)</option>
                    </select>
                </div>
                <!-- TAMBAHAN: 2 input tanggal untuk Rentang Kustom, tampil hanya kalau dipilih -->
                <div class="col-6 col-md-2" id="semCustomFromWrap" style="<?= $sem_semester=='custom' ? '' : 'display:none;' ?>">
                    <label class="form-label form-label-sm">Dari Tanggal</label>
                    <input type="date" name="sem_custom_from" class="form-control form-control-sm" value="<?= clean($sem_custom_from) ?>">
                </div>
                <div class="col-6 col-md-2" id="semCustomToWrap" style="<?= $sem_semester=='custom' ? '' : 'display:none;' ?>">
                    <label class="form-label form-label-sm">Sampai Tanggal</label>
                    <input type="date" name="sem_custom_to" class="form-control form-control-sm" value="<?= clean($sem_custom_to) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm">Tipe Hardware</label>
                    <select name="sem_type" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <?php foreach ($type_labels as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $sem_type===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label form-label-sm">Gedung</label>
                    <select name="sem_building" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $sem_building==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                    <a href="?report_type=<?= clean($report_type) ?>#semester" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-3" id="print-header-semester">
        <h5 class="fw-bold"><?= APP_NAME ?></h5>
        <div class="text-muted small">
            Laporan Semester: Distribusi Hardware Terdaftar &bull; <?= clean($sem_label) ?>
            &bull; Dicetak: <?= date('d M Y H:i') ?>
            &bull; Oleh: <?= clean(currentUser()['name']) ?>
        </div>
        <hr>
    </div>

    <div class="row g-2 mb-3 no-print">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Aset (periode ini)</div>
                    <div class="fs-4 fw-bold text-primary"><?= $sem_total_assets ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Jumlah Gedung</div>
                    <div class="fs-4 fw-bold"><?= count($by_building) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Jumlah Ruangan</div>
                    <div class="fs-4 fw-bold"><?= count($by_room) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Periode</div>
                    <div class="fs-6 fw-bold"><?= clean($sem_label) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-light"><i class="bi bi-hdd-stack me-2"></i>Indikator Jumlah per Tipe Hardware (Terdaftar)</div>
        <div class="card-body">
            <div class="row g-2 text-center">
                <?php
                $type_icons = [
                    'komputer' => 'bi-pc-display',
                    'laptop'   => 'bi-laptop',
                    'printer'  => 'bi-printer',
                    'scanner'  => 'bi-scan',
                    'server'   => 'bi-hdd-rack',
                    'network'  => 'bi-diagram-3',
                    'lainnya'  => 'bi-box-seam',
                ];
                foreach ($by_type as $t):
                    $icon = $type_icons[$t['key']] ?? 'bi-hdd';
                ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="border rounded p-2 h-100">
                        <i class="bi <?= $icon ?> fs-3 text-primary"></i>
                        <div class="fs-3 fw-bold"><?= $t['total'] ?></div>
                        <div class="text-muted small"><?= clean($t['label']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="page-break"></div>

    <div class="row g-3 mb-3 chart-row-1">
        <div class="col-6 chart-col-half">
            <div class="card chart-card-fixed">
                <div class="card-header bg-light"><i class="bi bi-building me-2"></i>Distribusi per Gedung</div>
                <div class="card-body">
                    <?php if ($by_building): ?>
                    <div class="chart-pie-wrap" style="height: 600px;">
                        <canvas id="chartBuilding"></canvas>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">Tidak ada data</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-6 chart-col-half">
            <div class="card chart-card-fixed">
                <div class="card-header bg-light"><i class="bi bi-hdd-stack me-2"></i>Distribusi per Tipe Hardware</div>
                <div class="card-body">
                    <?php if ($by_type_chart): ?>
                    <div class="chart-pie-wrap" style="height: 600px;">
                        <canvas id="chartType"></canvas>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">Tidak ada data</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="page-break"></div>

    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-light"><i class="bi bi-door-open me-2"></i>Distribusi Hardware Terdaftar (per Ruangan)</div>
                <div class="card-body p-0">
                    <?php if ($room_pivot): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 text-center align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th class="text-start">Bagian / Ruangan</th>
                                    <?php foreach ($room_pivot_types as $lbl): ?>
                                    <th><?= clean($lbl) ?></th>
                                    <?php endforeach; ?>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($room_pivot as $building_name => $rooms): ?>
                                <tr class="table-secondary">
                                    <td colspan="<?= 3 + count($room_pivot_types) ?>" class="text-start fw-bold">
                                        <?= clean($building_name) ?>
                                    </td>
                                </tr>
                                <?php $no = 1; foreach ($rooms as $room_name => $counts): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="text-start"><?= clean($room_name) ?></td>
                                    <?php foreach ($room_pivot_types as $k => $lbl): ?>
                                    <td><?= ($counts[$k] ?? 0) ?: '' ?></td>
                                    <?php endforeach; ?>
                                    <td class="fw-bold"><?= $room_totals[$building_name][$room_name] ?? 0 ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center text-muted py-4">Tidak ada data</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="page-break"></div>

    <div class="klas-maint-group">
<div class="card mb-3">
    <div class="card-header bg-light d-flex justify-content-between">
        <span><i class="bi bi-tags me-2"></i>Klasifikasi Masalah Logbook - <?= clean($sem_label) ?></span>
        <span class="badge bg-primary"><?= $klas_total ?> laporan</span>
    </div>
    <div class="card-body">
        <?php if ($klas_total): ?>
        <div class="chart-bar-wrap" style="height: 240px;">
            <canvas id="chartKlasifikasi"></canvas>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">Tidak ada data logbook pada periode ini</div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-light d-flex justify-content-between">
        <span><i class="bi bi-tools me-2"></i>Maintenance - <?= clean($sem_label) ?></span>
        <span class="badge bg-primary"><?= $maint_total ?> kegiatan</span>
    </div>
    <div class="card-body">
        <?php if ($maint_total): ?>
        <div class="chart-bar-wrap" style="height: 240px;">
            <canvas id="chartMaintenance"></canvas>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-4">Tidak ada kegiatan maintenance pada periode ini</div>
        <?php endif; ?>
    </div>
</div>
</div>

    <div class="page-break"></div>

    <div class="card mb-3 card-with-table">
        <div class="card-header bg-light d-flex justify-content-between">
            <span><i class="bi bi-arrow-left-right me-2"></i>Optimalisasi (Pindah Lokasi) - <?= clean($sem_label) ?></span>
            <span class="badge bg-primary"><?= count($sem_locations) ?> kegiatan</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead>
                        <tr><th>No</th><th>Tanggal</th><th>Kode Aset</th><th>Nama Aset</th><th>Lokasi Baru</th><th>Catatan</th><th>Oleh</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($sem_locations): $no=1; foreach ($sem_locations as $l): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td><small><?= date('d/m/y H:i', strtotime($l['moved_at'])) ?></small></td>
                            <td><strong><?= clean($l['asset_code']) ?></strong></td>
                            <td><?= clean($l['asset_name'] ?? '-') ?></td>
                            <td><?= clean($l['building_name'] ?? '-') ?><?= $l['room_name'] ? ' / '.clean($l['room_name']) : '' ?></td>
                            <td><small class="text-muted"><?= clean($l['notes'] ?? '-') ?></small></td>
                            <td><?= clean($l['user_name']) ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">Tidak ada kegiatan pindah lokasi pada periode ini</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
<!-- END TAB 2 -->

<!-- ══ TAB 3: DATA MANUAL ══ -->
<div class="tab-pane" id="tab-manual" role="tabpanel">

    <div class="alert alert-warning no-print">
        <i class="bi bi-info-circle me-1"></i>
        Data di tab ini berasal dari rekap manual Excel (sebelum sistem berjalan), disimpan di
        tabel <code>manual_data</code> — <strong>terpisah</strong> dari tabel <code>assets</code>.
    </div>

    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="report_type" value="<?= clean($report_type) ?>">
                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm">Kategori</label>
                    <select name="man_kategori" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <?php foreach ($manual_kategori_list as $k): ?>
                        <option value="<?= clean($k) ?>" <?= $man_kategori===$k?'selected':'' ?>><?= clean($k) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label form-label-sm">Sumber</label>
                    <select name="man_sumber" class="form-select form-select-sm">
                        <option value="">Semua</option>
                        <?php foreach ($manual_sumber_list as $s): ?>
                        <option value="<?= clean($s) ?>" <?= $man_sumber===$s?'selected':'' ?>><?= clean($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                    <a href="?report_type=<?= clean($report_type) ?>#manual" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-3" id="print-header-manual">
        <h5 class="fw-bold"><?= APP_NAME ?></h5>
        <div class="text-muted small">
            Laporan: Distribusi Hardware Manual &bull; Dicetak: <?= date('d M Y H:i') ?>
            &bull; Oleh: <?= clean(currentUser()['name']) ?>
        </div>
        <hr>
    </div>

    <div class="row g-2 mb-3 no-print">
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Unit (Manual)</div>
                    <div class="fs-4 fw-bold text-primary"><?= $manual_grand_total ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Jumlah Bagian/Ruangan</div>
                    <div class="fs-4 fw-bold"><?= $manual_bagian_count ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ 2 Chart + Keterangan per Induk/Kategori ══
         NOTE: chart-pie-wrap & options Chart.js di bawah ini sengaja DISAMAKAN
         dengan chart di tab "Laporan Semester" (radius diperkecil + padding
         atas/bawah diperbesar + tinggi wrapper 600px) supaya garis label
         (leader line) tidak mepet/bertabrakan seperti sebelumnya. -->
    <?php if ($manual_group_charts): ?>
    <div class="row g-3 mb-3 chart-row-1">
        <?php foreach ($manual_group_charts as $grp_name => $grp): ?>
        <div class="col-12 col-md-6 chart-col-half">
            <div class="card chart-card-fixed">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-pie-chart me-2"></i><?= clean($grp_name) ?></span>
                    <span class="badge bg-primary"><?= $grp['total'] ?> unit</span>
                </div>
                <div class="card-body">
                    <div class="chart-pie-wrap" style="height: 600px;">
                        <canvas id="<?= $grp['id'] ?>"></canvas>
                    </div>
                </div>
                <!-- Keterangan: warna + induk/kategori + jumlah + persentase -->
                <div class="card-footer p-0">
                    <table class="table table-sm table-bordered mb-0" style="font-size:12px;">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px;" class="text-center">Warna</th>
                                <th>Induk / Kategori</th>
                                <th class="text-end" style="width:55px;">Jumlah</th>
                                <th class="text-end" style="width:55px;">%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grp['by_kategori'] as $kat => $jml):
                                $warna = $grp['colors'][$kat] ?? '#858796';
                                $persen = $grp['total'] > 0 ? round(($jml / $grp['total']) * 100) : 0;
                            ?>
                            <tr>
                                <td class="text-center align-middle">
                                    <div style="width:18px;height:18px;border-radius:3px;background:<?= $warna ?>;margin:0 auto;"></div>
                                </td>
                                <td class="align-middle"><?= clean($kat) ?></td>
                                <td class="text-end fw-bold align-middle"><?= $jml ?></td>
                                <td class="text-end align-middle"><?= $persen ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-secondary">
                                <td colspan="2" class="text-end fw-bold">Total</td>
                                <td class="text-end fw-bold"><?= $grp['total'] ?></td>
                                <td class="text-end fw-bold">100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="page-break"></div>
    <?php endif; ?>

    <div class="card card-with-table">
        <div class="card-header bg-light"><i class="bi bi-journal-text me-2"></i>Distribusi Hardware Manual</div>
        <div class="card-body p-0">
            <?php if ($manual_pivot): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 text-center align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th class="text-start">Induk / Kategori</th>
                            <?php foreach ($manual_hw_columns as $hw): ?>
                            <th><?= clean($hw) ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $no = 1; foreach ($manual_pivot as $kategori => $counts): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td class="text-start fw-bold"><?= clean($kategori) ?></td>
                            <?php foreach ($manual_hw_columns as $hw): ?>
                            <td><?= ($counts[$hw] ?? 0) ?: '' ?></td>
                            <?php endforeach; ?>
                            <td class="fw-bold"><?= $manual_row_totals[$kategori] ?? 0 ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark">
                        <td colspan="<?= 2 + count($manual_hw_columns) ?>" class="text-end fw-bold">TOTAL KESELURUHAN</td>
                        <td class="fw-bold"><?= $manual_grand_total ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center text-muted py-4">Tidak ada data manual</div>
            <?php endif; ?>
        </div>
    </div>

</div>
<!-- END TAB 3 -->

<!-- ══ TAB 4: DATA PEMBELIAN ══ -->
<div class="tab-pane" id="tab-pembelian" role="tabpanel">

    <div class="card mb-3 no-print">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="report_type" value="<?= clean($report_type) ?>">
                <div class="col-sm-4">
                    <label class="form-label form-label-sm">Cari</label>
                    <input type="text" name="poc_q" class="form-control form-control-sm" placeholder="Nomor, vendor, no surat..." value="<?= clean($poc_search) ?>">
                </div>
                <div class="col-6 col-sm-3">
                    <label class="form-label form-label-sm">Status</label>
                    <select name="poc_status" class="form-select form-select-sm">
                        <option value="">Semua Status</option>
                        <?php foreach ($poc_status_labels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $poc_status === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-2">
                    <label class="form-label form-label-sm">Tahun</label>
                    <select name="poc_tahun" class="form-select form-select-sm">
                        <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
                        <option value="<?= $y ?>" <?= $poc_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                    <a href="?report_type=<?= clean($report_type) ?>#pembelian" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                </div>
            </form>
        </div>
    </div>

    <div class="mb-3" id="print-header-pembelian">
        <h5 class="fw-bold"><?= APP_NAME ?></h5>
        <div class="text-muted small">
            Laporan: Data Pembelian (Pengadaan Hardware) &bull; Tahun <?= clean($poc_year) ?>
            &bull; Dicetak: <?= date('d M Y H:i') ?>
            &bull; Oleh: <?= clean(currentUser()['name']) ?>
        </div>
        <hr>
    </div>

    <div class="row g-2 mb-3 no-print">
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Total Pengadaan <?= clean($poc_year) ?></div>
                    <div class="fs-4 fw-bold text-primary"><?= $poc_summary['total'] ?? 0 ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Masih Draft</div>
                    <div class="fs-4 fw-bold"><?= $poc_summary['draft'] ?? 0 ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Selesai</div>
                    <div class="fs-4 fw-bold text-success"><?= $poc_summary['selesai'] ?? 0 ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-with-table">
        <div class="card-header bg-light d-flex justify-content-between">
            <span><i class="bi bi-cart-check me-2"></i>Daftar Pengadaan Hardware</span>
            <span class="badge bg-primary"><?= count($poc_orders) ?> pengadaan</span>
        </div>
        <div class="card-body p-0">
            <?php if ($poc_orders): ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>No. Pengadaan</th>
                            <th style="min-width:300px;">Nama Hardware (Jumlah)</th>
                            <th style="min-width:300px;">Tanggal Distribusi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $no = 1; foreach ($poc_orders as $o): ?>
                        <tr>
                            <td><?= $no++ ?></td>
                            <td>
                                <strong><?= clean($o['nomor_pengadaan']) ?></strong>
                                <?php if ($o['no_surat']): ?>
                                <br><small class="text-muted"><?= clean($o['no_surat']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: normal; word-break: break-word;">
                                <?php if ($o['item_parts']): ?>
                                    <?php foreach ($o['item_parts'] as $ip): ?>
                                    <div><?= clean($ip) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: normal; word-break: break-word;">
    <?php if ($o['tanggal_pengadaan']): ?>
        <div><?= clean(date('d/m/Y', strtotime($o['tanggal_pengadaan']))) ?></div>
    <?php else: ?>
        <span class="text-muted">-</span>
    <?php endif; ?>
</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center text-muted py-4">
                Tidak ada data pengadaan<?= $poc_search ? ' untuk pencarian "' . clean($poc_search) . '"' : '' ?> pada tahun <?= clean($poc_year) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
<!-- END TAB 4 -->

</div>
<!-- END TAB CONTENT -->

<style>
.tab-pane { display: none; }
.tab-pane.active { display: block; }

.chart-pie-wrap {
    max-width: 650px;
    margin: 0 auto;
}
.chart-card-fixed {
    min-height: 650px;
}

.page-break { display: none; }

@media print {
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    .page-break {
        display: block;
        page-break-before: always;
        break-before: page;
    }
    .no-print { display: none !important; }

    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .card:not(.card-with-table) { page-break-inside: avoid; break-inside: avoid; }

    .card-with-table { page-break-inside: auto; break-inside: auto; }
    .card-header { page-break-after: avoid; break-after: avoid; }
    .table-responsive { page-break-inside: auto; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; break-inside: avoid; }

    .navbar { display: none !important; }
    body { padding: 0 !important; }
    .container-fluid { padding: 8px !important; }
    .table { font-size: 9pt; }
    #print-header, #print-header-semester, #print-header-manual, #print-header-pembelian { display: block !important; }
    a { color: inherit !important; text-decoration: none !important; }
    .tab-pane { display: none; }
    .tab-pane.active { display: block !important; }

    /* ── Chart pie/donut: 1 halaman penuh, tersusun atas-bawah ──
       Berlaku untuk semua .chart-row-1, baik di Laporan Semester
       maupun Data Manual, supaya gaya keduanya konsisten saat print. */
    .chart-row-1 {
        page-break-before: always; break-before: page;
        page-break-after: always; break-after: page;
        page-break-inside: avoid; break-inside: avoid;
        display: block !important;
    }
    .chart-row-1 .chart-col-half {
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        margin-bottom: 14px;
    }
    .chart-card-fixed { height: auto !important; min-height: 600px; overflow: hidden; }
    .chart-pie-wrap { max-width: 100%; width: 100%; height: 560px !important; margin: 0; overflow: hidden; }

    /* ── Chart batang (Klasifikasi & Maintenance): rapi, tidak overflow ── */
    .chart-bar-wrap {
        width: 100% !important;
        max-width: 100% !important;
        overflow: hidden !important;
        box-sizing: border-box;
        height: 220px !important;
    }
    .chart-bar-wrap canvas {
        max-width: 100% !important;
    }
    .klas-maint-group .card { overflow: hidden; }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    if (typeof Chart === 'undefined') {
        document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"><\/script>');
    }
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var palette = ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#858796','#5a5c69','#2c9faf','#f8a51b','#6f42c1'];
    var chartsInitialized = false;
    var chartInstances = [];

    // ── Plugin garis label eksternal untuk pie/doughnut ──
    // Dipakai bersama oleh chart Laporan Semester DAN chart Data Manual,
    // supaya gaya garis (leader line) & penempatan teks selalu konsisten.
    var externalLabelsPlugin = {
    id: 'externalLabels',
    afterDraw: function (chart) {
        if (chart.config.type !== 'pie' && chart.config.type !== 'doughnut') return;
        var ctx = chart.ctx;
        var meta = chart.getDatasetMeta(0);
        var dataset = chart.data.datasets[0];
        var total = dataset.data.reduce(function (a, b) { return a + b; }, 0);
        if (!total) return;

        // ── Kelompokkan berdasarkan setengah lingkaran ATAS / BAWAH ──
        var topItems = [];
        var bottomItems = [];
        var chartCx = 0, chartCy = 0, chartRadius = 0;

        meta.data.forEach(function (arc, i) {
            var value = dataset.data[i];
            if (!value) return;
            var percentage = Math.round((value / total) * 100);
            if ((value / total) * 100 < 1.5) return;

            var angle = (arc.startAngle + arc.endAngle) / 2;
            var radius = arc.outerRadius;
            chartCx = arc.x; chartCy = arc.y; chartRadius = radius;

            var x1 = arc.x + Math.cos(angle) * radius;
            var y1 = arc.y + Math.sin(angle) * radius;
            var isTop = Math.sin(angle) < 0;

            var item = {
                x1: x1, y1: y1,
                label: chart.data.labels[i] || '',
                text: value + ' (' + percentage + '%)'
            };
            if (isTop) topItems.push(item); else bottomItems.push(item);
        });

        if (!topItems.length && !bottomItems.length) return;

        // Urutkan kiri->kanan lalu beri "tier" simetris dari tengah:
        // item paling tengah = tier 0 (elbow pendek), makin ke pinggir makin tinggi tier-nya.
        function assignTiers(items) {
            items.sort(function (a, b) { return a.x1 - b.x1; });
            var n = items.length;
            var mid = (n - 1) / 2;
            items.forEach(function (item, i) {
                item.tier = Math.round(Math.abs(i - mid));
                item.isRightSide = i > mid ? true : (i < mid ? false : (item.x1 >= chartCx));
            });
            return items;
        }
        assignTiers(topItems);
        assignTiers(bottomItems);

        var stepY = 30;      // jarak vertikal antar tier (cukup untuk 2 baris teks)
        var baseOffset = 22; // jarak elbow tier-0 dari tepi pie
        var dashBase = 14;   // panjang garis horizontal tier-0
        var dashStep = 16;   // penambahan panjang garis per tier (makin ke pinggir makin panjang)

        var topBaseY = chartCy - chartRadius - baseOffset;
        var bottomBaseY = chartCy + chartRadius + baseOffset;

        function drawLabel(item, isTop) {
            var shelfY = isTop ? (topBaseY - item.tier * stepY) : (bottomBaseY + item.tier * stepY);
            var dirX = item.isRightSide ? 1 : -1;
            var dashLen = dashBase + item.tier * dashStep;
            var finalX = item.x1 + dirX * dashLen;

            ctx.beginPath();
            ctx.moveTo(item.x1, item.y1);
            ctx.lineTo(item.x1, shelfY);
            ctx.lineTo(finalX, shelfY);
            ctx.strokeStyle = '#999';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.beginPath();
            ctx.arc(item.x1, item.y1, 2, 0, Math.PI * 2);
            ctx.fillStyle = '#999';
            ctx.fill();

            var textX = finalX + (dirX * 4);
            ctx.textAlign = item.isRightSide ? 'left' : 'right';

            if (isTop) {
                ctx.textBaseline = 'bottom';
                ctx.font = 'bold 11px sans-serif';
                ctx.fillStyle = '#333';
                ctx.fillText(item.label, textX, shelfY - 2);
                ctx.font = '10px sans-serif';
                ctx.fillStyle = '#666';
                ctx.fillText(item.text, textX, shelfY + 12);
            } else {
                ctx.textBaseline = 'top';
                ctx.font = 'bold 11px sans-serif';
                ctx.fillStyle = '#333';
                ctx.fillText(item.label, textX, shelfY + 2);
                ctx.font = '10px sans-serif';
                ctx.fillStyle = '#666';
                ctx.fillText(item.text, textX, shelfY + 16);
            }
        }

        ctx.save();
        topItems.forEach(function (it) { drawLabel(it, true); });
        bottomItems.forEach(function (it) { drawLabel(it, false); });
        ctx.restore();
    }
};

    var barValueLabelsPlugin = {
        id: 'barValueLabels',
        afterDatasetsDraw: function (chart) {
            if (chart.config.type !== 'bar') return;
            var ctx = chart.ctx;
            chart.data.datasets.forEach(function (dataset, dsIndex) {
                var meta = chart.getDatasetMeta(dsIndex);
                meta.data.forEach(function (bar, i) {
                    var value = dataset.data[i];
                    ctx.save();
                    ctx.font = 'bold 10px sans-serif';
                    ctx.fillStyle = '#333';
                    if (chart.options.indexAxis === 'y') {
                        ctx.textAlign = 'left';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(value, bar.x + 6, bar.y);
                    } else {
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'bottom';
                        ctx.fillText(value, bar.x, bar.y - 4);
                    }
                    ctx.restore();
                });
            });
        }
    };

    function initSemesterCharts() {
        if (chartsInitialized) return;
        if (typeof Chart === 'undefined') { setTimeout(initSemesterCharts, 200); return; }
        var bCanvas = document.getElementById('chartBuilding');
        var tCanvas = document.getElementById('chartType');
        var kCanvas = document.getElementById('chartKlasifikasi');
        var mCanvas = document.getElementById('chartMaintenance');
        if (!bCanvas && !tCanvas && !kCanvas && !mCanvas) return;
        chartsInitialized = true;

        <?php if ($by_building): ?>
        chartInstances.push(new Chart(document.getElementById('chartBuilding'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_column($by_building, 'building_name')) ?>,
                datasets: [{ data: <?= json_encode(array_map('intval', array_column($by_building, 'total'))) ?>, backgroundColor: palette }]
            },
            options: {
    responsive: true, maintainAspectRatio: false,
    radius: '40%',
    layout: { padding: { left: 90, right: 90, top: 120, bottom: 120 } },
    plugins: { legend: { position: 'bottom', labels: { padding: 20 } } }
},
            plugins: [externalLabelsPlugin]
        }));
        <?php endif; ?>

        <?php if ($by_type_chart): ?>
        chartInstances.push(new Chart(document.getElementById('chartType'), {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($by_type_chart, 'label')) ?>,
                datasets: [{ data: <?= json_encode(array_map('intval', array_column($by_type_chart, 'total'))) ?>, backgroundColor: palette }]
            },
            options: {
    responsive: true, maintainAspectRatio: false,
    radius: '48%',
    layout: { padding: { left: 100, right: 100, top: 90, bottom: 90 } },
    plugins: { legend: { position: 'bottom', labels: { padding: 20 } } }
},
plugins: [externalLabelsPlugin]
        }));
        <?php endif; ?>

        <?php if ($maint_total): ?>
        chartInstances.push(new Chart(document.getElementById('chartMaintenance'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($maint_labels) ?>,
                datasets: [{ label: 'Jumlah Kegiatan', data: <?= json_encode(array_values($maint_counts)) ?>, backgroundColor: ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#858796'] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            plugins: [barValueLabelsPlugin]
        }));
        <?php endif; ?>

        <?php if ($klas_total): ?>
        chartInstances.push(new Chart(document.getElementById('chartKlasifikasi'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($klas_labels) ?>,
                datasets: [{ label: 'Jumlah Masalah', data: <?= json_encode(array_values($klas_counts)) ?>, backgroundColor: ['#4e73df','#1cc88a','#f6c23e','#e74a3b','#858796'] }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            plugins: [barValueLabelsPlugin]
        }));
        <?php endif; ?>
    }

    var manualChartsInitialized = false;
    function initManualCharts() {
        if (manualChartsInitialized) return;
        if (typeof Chart === 'undefined') { setTimeout(initManualCharts, 200); return; }
        var manualGroups = <?= json_encode(array_values($manual_group_charts)) ?>;
        if (!manualGroups.length) return;
        var firstCanvas = document.getElementById(manualGroups[0].id);
        if (!firstCanvas) return;
        manualChartsInitialized = true;

        manualGroups.forEach(function (grp) {
    var canvas = document.getElementById(grp.id);
    if (!canvas) return;
    var labels = Object.keys(grp.by_kategori);
    var values = labels.map(function (k) { return grp.by_kategori[k]; });
    var colors = labels.map(function (k) { return grp.colors[k]; });
    chartInstances.push(new Chart(canvas, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{ data: values, backgroundColor: colors }]
        },
        options: {
            // ── DISAMAKAN dengan chart Laporan Semester ──
            // Sebelumnya tidak ada `radius` (jadi pie 100% penuh canvas) dan
            // padding atas/bawah cuma 40px, sehingga garis label (leader line)
            // jadi mepet/tumpang-tindih. Sekarang pie diperkecil (radius 40%)
            // dan padding atas/bawah diperbesar supaya ada ruang cukup untuk
            // menyusun label bertingkat tanpa saling nabrak — sama seperti
            // chartBuilding/chartType di tab Laporan Semester.
            responsive: true, maintainAspectRatio: false,
            radius: '40%',
            layout: { padding: { left: 90, right: 90, top: 100, bottom: 100 } },
            plugins: { legend: { display: false } }
        },
        plugins: [externalLabelsPlugin]
    }));
});
    }

    // Tab switcher
    var tabButtons = document.querySelectorAll('#laporanTab [data-tab-target]');
    tabButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabButtons.forEach(function (b) { b.classList.remove('active'); });
            document.querySelectorAll('#laporanTabContent .tab-pane').forEach(function (p) { p.classList.remove('active'); });
            btn.classList.add('active');
            var targetId = btn.getAttribute('data-tab-target');
            var targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.classList.add('active');
            if (targetId === 'tab-semester') setTimeout(initSemesterCharts, 30);
            if (targetId === 'tab-manual') setTimeout(initManualCharts, 30);
        });
    });

    if (window.location.hash === '#semester') {
        var semesterBtn = document.querySelector('#tab-semester-btn');
        if (semesterBtn) semesterBtn.click();
    } else if (window.location.hash === '#manual') {
        var manualBtn = document.querySelector('#tab-manual-btn');
        if (manualBtn) manualBtn.click();
    } else if (window.location.hash === '#pembelian') {
        var pembelianBtn = document.querySelector('#tab-pembelian-btn');
        if (pembelianBtn) pembelianBtn.click();
    } else {
        setTimeout(initSemesterCharts, 100);
    }

    function resizeAllCharts() {
        chartInstances.forEach(function (c) {
            if (c) {
                c.resize();
                c.update('none');
            }
        });
    }

    window.addEventListener('beforeprint', function () {
        initSemesterCharts();
        initManualCharts();
        // paksa reflow dulu supaya ukuran container sudah final
        document.body.offsetHeight;
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                resizeAllCharts();
                // resize kedua kali dengan delay, jaga-jaga chart pertama belum sempat final
                setTimeout(resizeAllCharts, 250);
            });
        });
    });

    if (window.matchMedia) {
        window.matchMedia('print').addEventListener('change', function (mql) {
            if (mql.matches) {
                resizeAllCharts();
                setTimeout(resizeAllCharts, 250);
            }
        });
    }

    if (window.matchMedia) {
        window.matchMedia('print').addEventListener('change', function (mql) {
            if (mql.matches) chartInstances.forEach(function (c) { if (c) c.resize(); });
        });
    }
});

  function toggleTriwulanSelect() {
    var sel = document.getElementById('semSemesterSelect');
    var wrap = document.getElementById('semTriwulanWrap');
    var fromWrap = document.getElementById('semCustomFromWrap');
    var toWrap = document.getElementById('semCustomToWrap');
    if (!sel) return;
    if (wrap) wrap.style.display = (sel.value === 'triwulan') ? '' : 'none';
    var showCustom = (sel.value === 'custom') ? '' : 'none';
    if (fromWrap) fromWrap.style.display = showCustom;
    if (toWrap) toWrap.style.display = showCustom;
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>