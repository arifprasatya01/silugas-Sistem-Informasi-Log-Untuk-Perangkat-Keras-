<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Daftar Pengadaan');
$db = getDB();

// Filter
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');
$year_filter   = $_GET['tahun'] ?? date('Y');

$where  = ['1=1'];
$params = [];

if ($status_filter) {
    $where[]  = 'po.status = ?';
    $params[] = $status_filter;
}
if ($search) {
    $where[]  = '(po.nomor_pengadaan LIKE ? OR po.vendor LIKE ? OR po.no_surat LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($year_filter) {
    $where[]  = 'YEAR(po.tanggal_pengadaan) = ?';
    $params[] = $year_filter;
}

$sql = "
    SELECT po.*,
           u.name AS created_by_name,
           COUNT(pi.id) AS jumlah_item
    FROM procurement_orders po
    JOIN users u ON u.id = po.created_by
    LEFT JOIN procurement_items pi ON pi.procurement_id = po.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY po.id
    ORDER BY po.tanggal_pengadaan DESC, po.id DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Summary stat
$stat = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(total_harga) AS total_nilai,
        SUM(CASE WHEN status='selesai' THEN 1 ELSE 0 END) AS selesai,
        SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) AS draft
    FROM procurement_orders
    WHERE YEAR(tanggal_pengadaan) = ?
");
$stat->execute([$year_filter]);
$summary = $stat->fetch();

$status_labels = [
    'draft'     => ['label' => 'Draft',     'class' => 'secondary'],
    'disetujui' => ['label' => 'Disetujui', 'class' => 'primary'],
    'diterima'  => ['label' => 'Diterima',  'class' => 'info'],
    'selesai'   => ['label' => 'Selesai',   'class' => 'success'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-cart-check me-2 text-primary"></i>Pengadaan Hardware</h4>
    <?php if (isAdmin()): ?>
    <a href="<?= APP_URL ?>/procurement_add" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Pengadaan
    </a>
    <?php endif; ?>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-blue">
            <div class="stat-icon"><i class="bi bi-receipt"></i></div>
            <div>
                <div class="stat-value"><?= $summary['total'] ?? 0 ?></div>
                <div class="stat-label">Total Pengadaan <?= $year_filter ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-teal">
            <div class="stat-icon"><i class="bi bi-currency-dollar"></i></div>
            <div>
                <div class="stat-value" style="font-size:1rem;">Rp <?= number_format($summary['total_nilai'] ?? 0, 0, ',', '.') ?></div>
                <div class="stat-label">Total Nilai</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-orange">
            <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-value"><?= $summary['draft'] ?? 0 ?></div>
                <div class="stat-label">Masih Draft</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-success" style="color:#fff;">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div>
                <div class="stat-value"><?= $summary['selesai'] ?? 0 ?></div>
                <div class="stat-label">Selesai</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Cari nomor, vendor, no surat..." value="<?= clean($search) ?>">
            </div>
            <div class="col-sm-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <?php foreach ($status_labels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $status_filter === $k ? 'selected' : '' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <select name="tahun" class="form-select form-select-sm">
                    <?php for ($y = date('Y'); $y >= 2023; $y--): ?>
                    <option value="<?= $y ?>" <?= $year_filter == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/procurement_list" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel -->
<div class="card">
    <div class="card-body p-0">
        <?php if ($orders): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Pengadaan</th>
                        <th>Tanggal</th>
                        <th>Vendor</th>
                        <th>Sumber Dana</th>
                        <th>Item</th>
                        <th>Total Nilai</th>
                        <th>Status</th>
                        <th class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/procurement_detail?id=<?= $o['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= clean($o['nomor_pengadaan']) ?>
                            </a>
                            <?php if ($o['no_surat']): ?>
                            <br><small class="text-muted"><?= clean($o['no_surat']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y', strtotime($o['tanggal_pengadaan'])) ?></td>
                        <td><?= clean($o['vendor']) ?></td>
                        <td><span class="badge bg-secondary"><?= strtoupper($o['sumber_dana']) ?></span></td>
                        <td class="text-center"><?= $o['jumlah_item'] ?> item</td>
                        <td class="fw-semibold">Rp <?= number_format($o['total_harga'], 0, ',', '.') ?></td>
                        <td>
                            <?php $s = $status_labels[$o['status']] ?? ['label'=>$o['status'],'class'=>'secondary']; ?>
                            <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
                        </td>
                        <td class="text-center">
                            <a href="<?= APP_URL ?>/procurement_detail?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary" title="Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (isAdmin()): ?>
                            <a href="<?= APP_URL ?>/procurement_add?edit=<?= $o['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <a href="<?= APP_URL ?>/procurement_print?id=<?= $o['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Cetak">
                                <i class="bi bi-printer"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-1"></i>
            <p class="mt-2">Belum ada data pengadaan<?= $search ? " untuk pencarian \"$search\"" : '' ?></p>
            <?php if (isAdmin()): ?>
            <a href="<?= APP_URL ?>/procurement_add" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Tambah Sekarang
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
