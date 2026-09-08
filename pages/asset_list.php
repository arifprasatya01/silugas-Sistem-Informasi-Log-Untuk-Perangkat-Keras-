<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Daftar Aset');
$db = getDB();

// Filter
$search   = trim($_GET['search'] ?? '');
$type     = $_GET['type'] ?? '';
$status   = $_GET['status'] ?? '';
$building = cleanInt($_GET['building'] ?? 0);
$reg      = $_GET['reg'] ?? '';

$where = ["1=1"];
$params = [];

if ($search) {
    $where[] = "(a.asset_code LIKE ? OR a.name LIKE ? OR a.brand LIKE ? OR a.serial_number LIKE ?)";
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
if ($type) { $where[] = "a.type = ?"; $params[] = $type; }
if ($status) { $where[] = "a.condition_status = ?"; $params[] = $status; }
if ($building) { $where[] = "a.building_id = ?"; $params[] = $building; }
if ($reg === '1') { $where[] = "a.is_registered = 1"; }
if ($reg === '0') { $where[] = "a.is_registered = 0"; }

$whereStr = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT a.*, b.name as building_name, r.name as room_name
    FROM assets a
    LEFT JOIN buildings b ON b.id = a.building_id
    LEFT JOIN rooms r ON r.id = a.room_id
    WHERE $whereStr
    ORDER BY a.created_at DESC
");
$stmt->execute($params);
$assets = $stmt->fetchAll();

$buildings = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();

$type_labels = [
    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="fw-bold mb-0"><i class="bi bi-hdd-stack me-2 text-primary"></i>Daftar Aset</h4>
    <?php if (currentUser()['role'] === 'admin'): ?>
    <div class="d-flex gap-2">
        <a href="<?= APP_URL ?>/asset_add" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Tambah Aset
        </a>
        <a href="<?= APP_URL ?>/qr_bulk" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-qr-code me-1"></i>Generate QR Bulk
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari kode, nama, brand..." value="<?= clean($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    <?php foreach ($type_labels as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= $type===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua Status</option>
                    <option value="baik" <?= $status==='baik'?'selected':'' ?>>Baik</option>
                    <option value="perlu_perhatian" <?= $status==='perlu_perhatian'?'selected':'' ?>>Perlu Perhatian</option>
                    <option value="rusak" <?= $status==='rusak'?'selected':'' ?>>Rusak</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="building" class="form-select form-select-sm">
                    <option value="">Semua Gedung</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $building==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="reg" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="1" <?= $reg==='1'?'selected':'' ?>>Sudah Diisi</option>
                    <option value="0" <?= $reg==='0'?'selected':'' ?>>Belum Diisi</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="bi bi-search"></i></button>
                <a href="<?= APP_URL ?>/asset_list" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Kode Aset</th>
                        <th>Nama / Tipe</th>
                        <th>Lokasi</th>
                        <th>Kondisi</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($assets): foreach ($assets as $a): ?>
                    <tr>
                        <td>
                            <a href="<?= APP_URL ?>/asset_detail?code=<?= urlencode($a['asset_code']) ?>" class="fw-bold text-decoration-none text-primary">
                                <?= clean($a['asset_code']) ?>
                            </a>
                        </td>
                        <td>
                            <?= $a['name'] ? clean($a['name']) : '<span class="text-muted fst-italic">Belum diisi</span>' ?>
                            <?php if ($a['type']): ?>
                            <br><small class="text-muted"><?= clean($type_labels[$a['type']] ?? $a['type']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($a['building_name']): ?>
                                <i class="bi bi-building text-muted me-1"></i><?= clean($a['building_name']) ?>
                                <?php if ($a['room_name']): ?> / <?= clean($a['room_name']) ?><?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $cond = $a['condition_status'] ?? 'baik';
                            $condLabel = ['baik'=>'Baik','perlu_perhatian'=>'Perlu Perhatian','rusak'=>'Rusak'];
                            ?>
                            <span class="badge badge-<?= $cond ?>"><?= $condLabel[$cond] ?? $cond ?></span>
                        </td>
                        <td>
                            <?php if ($a['is_registered']): ?>
                            <span class="badge bg-success">Terdaftar</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Belum Diisi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= APP_URL ?>/asset_detail?code=<?= urlencode($a['asset_code']) ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-inbox fs-2"></i><br>Tidak ada data aset</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 text-muted small">Total: <?= count($assets) ?> aset</div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
