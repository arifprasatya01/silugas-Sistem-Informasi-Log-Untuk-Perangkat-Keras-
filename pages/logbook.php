<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Logbook Hardware');
$db = getDB();

// ── Handle POST (add / edit / delete / delete_tiket) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $ruangan               = trim($_POST['ruangan'] ?? '');
        $nama_user             = trim($_POST['nama_user'] ?? '');
        $tanggal_lapor         = $_POST['tanggal_lapor'] ?? '';
        $tanggal_selesai       = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : null;
        $subject               = trim($_POST['subject'] ?? '');
        $masalah               = trim($_POST['masalah'] ?? '');
        $solusi                = trim($_POST['solusi'] ?? '');
        $status                = $_POST['status'] ?? 'Open';
        $petugas_dilaporkan    = trim($_POST['petugas_dilaporkan'] ?? '');
        $petugas_menyelesaikan = trim($_POST['petugas_menyelesaikan'] ?? '');
        $tingkat_urgensi       = $_POST['tingkat_urgensi'] ?? 'Sedang';
        $keterangan            = trim($_POST['keterangan'] ?? '');

        if (!$ruangan || !$nama_user || !$tanggal_lapor || !$subject || !$masalah) {
            $msg = 'Ruangan, User, Tanggal Lapor, Subject, dan Masalah wajib diisi.';
            $msgType = 'danger';
        } else {
            $db->prepare("
                INSERT INTO logbook
                    (ruangan, nama_user, tanggal_lapor, tanggal_selesai, subject, masalah, kategori, solusi, status, petugas_dilaporkan, petugas_menyelesaikan, tingkat_urgensi, keterangan)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $ruangan, $nama_user, $tanggal_lapor, $tanggal_selesai, $subject, $masalah,
                'Hardware', $solusi ?: null, $status, $petugas_dilaporkan ?: null,
                $petugas_menyelesaikan ?: null, $tingkat_urgensi, $keterangan ?: null
            ]);
            header('Location: ' . APP_URL . '/logbook?ok=add');
            exit;
        }
    }

    elseif ($action === 'edit') {
        $id                    = cleanInt($_POST['id'] ?? 0);
        $ruangan               = trim($_POST['ruangan'] ?? '');
        $nama_user             = trim($_POST['nama_user'] ?? '');
        $tanggal_lapor         = $_POST['tanggal_lapor'] ?? '';
        $tanggal_selesai       = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : null;
        $subject               = trim($_POST['subject'] ?? '');
        $masalah               = trim($_POST['masalah'] ?? '');
        $solusi                = trim($_POST['solusi'] ?? '');
        $status                = $_POST['status'] ?? 'Open';
        $petugas_dilaporkan    = trim($_POST['petugas_dilaporkan'] ?? '');
        $petugas_menyelesaikan = trim($_POST['petugas_menyelesaikan'] ?? '');
        $tingkat_urgensi       = $_POST['tingkat_urgensi'] ?? 'Sedang';
        $keterangan            = trim($_POST['keterangan'] ?? '');

        if (!$id || !$ruangan || !$nama_user || !$tanggal_lapor || !$subject || !$masalah) {
            $msg = 'Data tidak lengkap.';
            $msgType = 'danger';
        } else {
            $db->prepare("
                UPDATE logbook SET
                    ruangan=?, nama_user=?, tanggal_lapor=?, tanggal_selesai=?, subject=?, masalah=?,
                    solusi=?, status=?, petugas_dilaporkan=?, petugas_menyelesaikan=?, tingkat_urgensi=?, keterangan=?
                WHERE id=?
            ")->execute([
                $ruangan, $nama_user, $tanggal_lapor, $tanggal_selesai, $subject, $masalah,
                $solusi ?: null, $status, $petugas_dilaporkan ?: null,
                $petugas_menyelesaikan ?: null, $tingkat_urgensi, $keterangan ?: null, $id
            ]);
            header('Location: ' . APP_URL . '/logbook?ok=edit');
            exit;
        }
    }

    elseif ($action === 'delete') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM logbook WHERE id = ?")->execute([$id]);
        }
        header('Location: ' . APP_URL . '/logbook?ok=delete');
        exit;
    }

    // Hapus cache tiket hasil sync (tidak menghapus data asli di server WA)
    elseif ($action === 'delete_tiket') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare("DELETE FROM tiket WHERE id = ?")->execute([$id]);
        }
        header('Location: ' . APP_URL . '/logbook?ok=delete');
        exit;
    }
}

// ── Flash message (setelah redirect) ───────────────────────
$msg     = $msg ?? '';
$msgType = $msgType ?? 'success';
$ok = $_GET['ok'] ?? '';
$err = $_GET['err'] ?? '';
$okMap = [
    'add'    => 'Laporan berhasil ditambahkan!',
    'edit'   => 'Data berhasil diperbarui!',
    'delete' => 'Data berhasil dihapus.',
];
if ($ok === 'sync') {
    $inserted = (int)($_GET['inserted'] ?? 0);
    $updated  = (int)($_GET['updated'] ?? 0);
    $skipped  = (int)($_GET['skipped'] ?? 0);
    $msg = "Sinkronisasi selesai. {$inserted} tiket baru ditambahkan, {$updated} diperbarui (status/data berubah), {$skipped} dilewati.";
    $msgType = 'success';
} elseif ($ok && isset($okMap[$ok])) {
    $msg = $okMap[$ok]; $msgType = 'success';
} elseif ($err === 'sync') {
    $msg = 'Sinkronisasi gagal: ' . ($_GET['msg'] ?? 'Terjadi kesalahan.');
    $msgType = 'danger';
}

// ── Filter & Search ─────────────────────────────────────────
$search        = trim($_GET['q'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_dari   = $_GET['dari'] ?? '';
$filter_sampai = $_GET['sampai'] ?? '';

// ── Pagination ───────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; // ubah sesuai kebutuhan

// -- Query tabel logbook (input manual) --
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

$stmt = $db->prepare("SELECT * FROM logbook WHERE $whereStr AND ruangan IS NOT NULL ORDER BY tanggal_lapor DESC, id DESC");
$stmt->execute($params);
$dataLogbook = $stmt->fetchAll();

// -- Query tabel tiket (hasil sync dari WA Bailey) --
$whereT  = ["1=1"];
$paramsT = [];
if ($search) {
    $whereT[] = "(ruangan LIKE ? OR nama_user LIKE ? OR subject LIKE ? OR masalah LIKE ?)";
    $paramsT  = array_merge($paramsT, array_fill(0, 4, "%$search%"));
}
if ($filter_status) { $whereT[] = "status = ?"; $paramsT[] = $filter_status; }
if ($filter_dari)   { $whereT[] = "tanggal_lapor >= ?"; $paramsT[] = $filter_dari; }
if ($filter_sampai) { $whereT[] = "tanggal_lapor <= ?"; $paramsT[] = $filter_sampai; }
$whereTStr = implode(' AND ', $whereT);

$dataTiket = [];
try {
    $stmtT = $db->prepare("SELECT * FROM tiket WHERE $whereTStr ORDER BY tanggal_lapor DESC, id DESC");
    $stmtT->execute($paramsT);
    $dataTiket = $stmtT->fetchAll();
} catch (PDOException $e) {
    // Tabel tiket belum dibuat -> anggap kosong, jangan bikin halaman error
    $dataTiket = [];
}

// -- Gabungkan dua sumber jadi satu list, tandai asalnya --
$data = [];
foreach ($dataLogbook as $row) {
    $row['sumber'] = 'manual';
    $data[] = $row;
}
foreach ($dataTiket as $row) {
    $row['sumber'] = 'whatsapp';
    $row['tingkat_urgensi']       = $row['tingkat_urgensi'] ?? 'Sedang';
    $row['petugas_dilaporkan']    = $row['petugas_dilaporkan'] ?? null;
    $row['petugas_menyelesaikan'] = $row['petugas_menyelesaikan'] ?? null;
    $data[] = $row;
}
usort($data, function ($a, $b) {
    $cmp = strcmp($b['tanggal_lapor'] ?? '', $a['tanggal_lapor'] ?? '');
    if ($cmp !== 0) return $cmp;
    return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
});

// -- Potong data sesuai halaman aktif --
$totalData  = count($data);
$totalPages = max(1, (int)ceil($totalData / $perPage));
$page       = min($page, $totalPages); // jaga2 kalau page melebihi total
$offset     = ($page - 1) * $perPage;
$dataPaged  = array_slice($data, $offset, $perPage);

// ── Stats (gabungan logbook + tiket) ────────────────────────
$total_lb    = (int) $db->query("SELECT COUNT(*) FROM logbook")->fetchColumn();
$open_lb     = (int) $db->query("SELECT COUNT(*) FROM logbook WHERE status='Open'")->fetchColumn();
$closed_lb   = (int) $db->query("SELECT COUNT(*) FROM logbook WHERE status='Closed'")->fetchColumn();
$bulan_lb    = (int) $db->query("SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=MONTH(NOW()) AND YEAR(tanggal_lapor)=YEAR(NOW())")->fetchColumn();

$total_tk = 0; $open_tk = 0; $closed_tk = 0; $bulan_tk = 0;
try {
    $total_tk  = (int) $db->query("SELECT COUNT(*) FROM tiket")->fetchColumn();
    $open_tk   = (int) $db->query("SELECT COUNT(*) FROM tiket WHERE status='Open'")->fetchColumn();
    $closed_tk = (int) $db->query("SELECT COUNT(*) FROM tiket WHERE status='Closed'")->fetchColumn();
    $bulan_tk  = (int) $db->query("SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=MONTH(NOW()) AND YEAR(tanggal_lapor)=YEAR(NOW())")->fetchColumn();
} catch (PDOException $e) {
    // tabel tiket belum ada, biarkan 0
}

$total        = $total_lb + $total_tk;
$open_count   = $open_lb + $open_tk;
$closed_count = $closed_lb + $closed_tk;
$bulan_ini    = $bulan_lb + $bulan_tk;

include __DIR__ . '/../includes/header.php';
?>

<style>
    /* Badge khusus Logbook, mengikuti palet warna app.css (--success/--warning/--danger) */
    .badge-lb-open    { background:#fff3e0; color:#e65100; border:1px solid #ffe0b2; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    .badge-lb-closed  { background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9; font-size:.72rem; padding:.3rem .6rem; border-radius:6px; font-weight:600; }
    .badge-urg-rendah { background:#e8f5e9; color:#1b5e20; border:1px solid #c8e6c9; }
    .badge-urg-sedang { background:#fff8e1; color:#e65100; border:1px solid #ffe0b2; }
    .badge-urg-tinggi { background:#ffebee; color:#b71c1c; border:1px solid #ffcdd2; }
    .badge-urg { font-size:.68rem; padding:.25rem .55rem; border-radius:5px; font-weight:600; }
    .badge-src-manual   { background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; font-size:.68rem; padding:.25rem .5rem; border-radius:5px; font-weight:600; }
    .badge-src-whatsapp { background:#e6f7ee; color:#0f5132; border:1px solid #b7ebc9; font-size:.68rem; padding:.25rem .5rem; border-radius:5px; font-weight:600; }
    .lb-subject-col { max-width: 220px; }
    .lb-truncate-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-journal-text me-2 text-primary"></i>Logbook Hardware</h4>
        <div class="text-muted small"><?= date('l, d F Y') ?> &bull; Total <?= $total ?> laporan (manual + WhatsApp)</div>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="<?= APP_URL ?>/sync" onsubmit="return confirm('Tarik data tiket baru dari server WA Bailey sekarang?')" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn btn-outline-success">
                <i class="bi bi-arrow-repeat me-1"></i>Sinkronisasi
            </button>
        </form>
        <a href="<?= APP_URL ?>/print_logbook?q=<?= urlencode($search) ?>&status=<?= urlencode($filter_status) ?>&dari=<?= urlencode($filter_dari) ?>&sampai=<?= urlencode($filter_sampai) ?>"
           target="_blank" class="btn btn-outline-primary">
            <i class="bi bi-printer me-1"></i>Cetak
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalTambahLog">
            <i class="bi bi-plus-lg me-1"></i>Tambah Laporan
        </button>
      <a href="<?= APP_URL ?>/ttd_bulanan" class="btn btn-outline-success">
    <i class="bi bi-pen me-1"></i>Rekap &amp; TTD Bulanan
</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= clean($msgType) ?> alert-dismissible fade show" role="alert">
    <i class="bi bi-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i> <?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-blue">
            <i class="bi bi-list-ul stat-icon"></i>
            <div><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Laporan</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-orange">
            <i class="bi bi-hourglass-split stat-icon"></i>
            <div><div class="stat-value"><?= $open_count ?></div><div class="stat-label">Masih Open</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-teal">
            <i class="bi bi-check2-circle stat-icon"></i>
            <div><div class="stat-value"><?= $closed_count ?></div><div class="stat-label">Selesai</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-red">
            <i class="bi bi-calendar-month stat-icon"></i>
            <div><div class="stat-value"><?= $bulan_ini ?></div><div class="stat-label">Bulan Ini</div></div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label form-label-sm">Cari</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Ruangan, user, subject, masalah..." value="<?= clean($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label form-label-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="Open" <?= $filter_status === 'Open' ? 'selected' : '' ?>>Open</option>
                    <option value="Closed" <?= $filter_status === 'Closed' ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
    <label class="form-label form-label-sm">Dari Tanggal</label>
    <input type="date" name="dari" class="form-control form-control-sm" value="<?= clean($filter_dari) ?>">
</div>
<div class="col-6 col-md-2">
    <label class="form-label form-label-sm">Sampai Tanggal</label>
    <input type="date" name="sampai" class="form-control form-control-sm" value="<?= clean($filter_sampai) ?>">
</div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i> Filter</button>
                <a href="<?= APP_URL ?>/logbook" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <span><i class="bi bi-table me-2"></i>Data Laporan Hardware</span>
        <span class="badge bg-primary"><?= $totalData ?> laporan</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead>
                    <tr>
                        <th>No</th><th>Sumber</th><th>Kd Tiket</th><th>Tgl Lapor</th><th>Tgl Selesai</th><th>Ruangan</th><th>User</th>
                        <th>Subject / Masalah</th><th>Solusi</th><th>Petugas</th><th>Status</th><th>Urgensi</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$dataPaged): ?>
                    <tr><td colspan="13" class="text-center py-4 text-muted"><i class="bi bi-inbox d-block fs-3 mb-2 opacity-50"></i>Tidak ada data ditemukan</td></tr>
                <?php else: $no = $offset + 1; foreach ($dataPaged as $row): $isManual = ($row['sumber'] === 'manual'); ?>
                    <tr>
                        <td><?= $no++ ?></td>
                        <td>
                            <?php if ($isManual): ?>
                                <span class="badge-src-manual"><i class="bi bi-pencil-square me-1"></i>Manual</span>
                            <?php else: ?>
                                <span class="badge-src-whatsapp"><i class="bi bi-whatsapp me-1"></i>WhatsApp</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isManual && !empty($row['source_id'])): ?>
                                <span class="badge bg-light text-dark border" style="font-size:.72rem;">#<?= (int)$row['source_id'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= $row['tanggal_lapor'] ? date($isManual ? 'd/m/y' : 'd/m/y H:i', strtotime($row['tanggal_lapor'])) : '-' ?></small></td>
                        <td><small><?= $row['tanggal_selesai'] ? date($isManual ? 'd/m/y' : 'd/m/y H:i', strtotime($row['tanggal_selesai'])) : '<span class="text-muted">-</span>' ?></small></td>
                        <td class="fw-semibold" style="font-size:.82rem;"><?= clean($row['ruangan'] ?? '-') ?></td>
                        <td style="font-size:.8rem;"><?= clean($row['nama_user'] ?? '-') ?></td>
                        <td class="lb-subject-col">
                            <div class="fw-semibold" style="font-size:.82rem;"><?= clean($row['subject'] ?? '') ?></div>
                            <div class="text-muted lb-truncate-2" style="font-size:.74rem;"><?= clean($row['masalah'] ?? '') ?></div>
                        </td>
                        <td class="lb-subject-col">
                            <div class="text-muted lb-truncate-2" style="font-size:.76rem;"><?= clean($row['solusi'] ?? '-') ?></div>
                        </td>
                        <td style="font-size:.78rem; white-space:nowrap;"><?= clean($row['petugas_menyelesaikan'] ?: ($row['petugas_dilaporkan'] ?? '-')) ?></td>
                        <td>
                            <?php if ($row['status'] === 'Open'): ?>
                                <span class="badge-lb-open"><i class="bi bi-circle-fill me-1" style="font-size:.4rem;vertical-align:middle;"></i>Open</span>
                            <?php else: ?>
                                <span class="badge-lb-closed"><i class="bi bi-check-circle-fill me-1"></i>Closed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-urg badge-urg-<?= strtolower($row['tingkat_urgensi'] ?? 'sedang') ?>">
                                <?= clean($row['tingkat_urgensi'] ?? 'Sedang') ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($isManual): ?>
                                    <button class="btn btn-sm btn-outline-primary" title="Edit" onclick="editLog(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Yakin hapus data ini?')" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" onsubmit="return confirm('Hapus tiket ini dari daftar lokal? (data asli di WhatsApp tidak terhapus)')" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_tiket">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus dari cache lokal">
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

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qs = fn($p) => '?' . http_build_query(array_merge($_GET, ['page' => $p]));
        ?>
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $qs(max(1, $page - 1)) ?>">&laquo;</a>
        </li>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $qs($i) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $qs(min($totalPages, $page + 1)) ?>">&raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<!-- Modal Tambah -->
<div class="modal fade" id="modalTambahLog" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Tambah Laporan Hardware</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ruangan <span class="text-danger">*</span></label>
                            <input type="text" name="ruangan" class="form-control" required placeholder="Nama ruangan / poli">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama User / Pelapor <span class="text-danger">*</span></label>
                            <input type="text" name="nama_user" class="form-control" required placeholder="Nama yang melapor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Lapor <span class="text-danger">*</span></label>
                            <input type="date" name="tanggal_lapor" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" required placeholder="Judul singkat masalah">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Detail Masalah <span class="text-danger">*</span></label>
                            <textarea name="masalah" class="form-control" required placeholder="Tulis detail masalah yang dilaporkan..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Solusi</label>
                            <textarea name="solusi" class="form-control" placeholder="Tulis solusi yang diberikan..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Open">Open</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tingkat Urgensi</label>
                            <select name="tingkat_urgensi" class="form-select">
                                <option value="Rendah">Rendah</option>
                                <option value="Sedang" selected>Sedang</option>
                                <option value="Tinggi">Tinggi</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Petugas Dilaporkan</label>
                            <input type="text" name="petugas_dilaporkan" class="form-control" placeholder="Nama petugas">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Petugas Menyelesaikan</label>
                            <input type="text" name="petugas_menyelesaikan" class="form-control" placeholder="Nama petugas">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Keterangan</label>
                            <input type="text" name="keterangan" class="form-control" placeholder="Keterangan tambahan">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Edit -->
<div class="modal fade" id="modalEditLog" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Laporan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="lb_edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Ruangan</label>
                            <input type="text" name="ruangan" id="lb_edit_ruangan" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nama User</label>
                            <input type="text" name="nama_user" id="lb_edit_nama_user" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Lapor</label>
                            <input type="date" name="tanggal_lapor" id="lb_edit_tanggal_lapor" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" id="lb_edit_tanggal_selesai" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject</label>
                            <input type="text" name="subject" id="lb_edit_subject" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Detail Masalah</label>
                            <textarea name="masalah" id="lb_edit_masalah" class="form-control"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Solusi</label>
                            <textarea name="solusi" id="lb_edit_solusi" class="form-control"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="lb_edit_status" class="form-select">
                                <option value="Open">Open</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tingkat Urgensi</label>
                            <select name="tingkat_urgensi" id="lb_edit_tingkat_urgensi" class="form-select">
                                <option value="Rendah">Rendah</option>
                                <option value="Sedang">Sedang</option>
                                <option value="Tinggi">Tinggi</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Petugas Dilaporkan</label>
                            <input type="text" name="petugas_dilaporkan" id="lb_edit_petugas_dilaporkan" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Petugas Menyelesaikan</label>
                            <input type="text" name="petugas_menyelesaikan" id="lb_edit_petugas_menyelesaikan" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Keterangan</label>
                            <input type="text" name="keterangan" id="lb_edit_keterangan" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save me-1"></i>Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editLog(row) {
    document.getElementById('lb_edit_id').value = row.id;
    document.getElementById('lb_edit_ruangan').value = row.ruangan || '';
    document.getElementById('lb_edit_nama_user').value = row.nama_user || '';
    document.getElementById('lb_edit_tanggal_lapor').value = row.tanggal_lapor || '';
    document.getElementById('lb_edit_tanggal_selesai').value = row.tanggal_selesai || '';
    document.getElementById('lb_edit_subject').value = row.subject || '';
    document.getElementById('lb_edit_masalah').value = row.masalah || '';
    document.getElementById('lb_edit_solusi').value = row.solusi || '';
    document.getElementById('lb_edit_status').value = row.status || 'Open';
    document.getElementById('lb_edit_tingkat_urgensi').value = row.tingkat_urgensi || 'Sedang';
    document.getElementById('lb_edit_petugas_dilaporkan').value = row.petugas_dilaporkan || '';
    document.getElementById('lb_edit_petugas_menyelesaikan').value = row.petugas_menyelesaikan || '';
    document.getElementById('lb_edit_keterangan').value = row.keterangan || '';
    new bootstrap.Modal(document.getElementById('modalEditLog')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>