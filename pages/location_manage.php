<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
define('PAGE_TITLE', 'Kelola Lokasi');
$db = getDB();

$msg = ''; $msgType = 'success';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_building') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$name) { $msg = 'Nama gedung wajib diisi.'; $msgType='danger'; }
        else {
            $db->prepare("INSERT INTO buildings (name, description, created_by) VALUES (?,?,?)")
               ->execute([$name, $desc ?: null, currentUser()['id']]);
            $msg = 'Gedung berhasil ditambahkan.';
        }
    }

    elseif ($action === 'add_room') {
        $bid  = cleanInt($_POST['building_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (!$bid || !$name) { $msg = 'Gedung dan nama ruangan wajib diisi.'; $msgType='danger'; }
        else {
            $db->prepare("INSERT INTO rooms (building_id, name, description, created_by) VALUES (?,?,?,?)")
               ->execute([$bid, $name, $desc ?: null, currentUser()['id']]);
            $msg = 'Ruangan berhasil ditambahkan.';
        }
    }

    elseif ($action === 'delete_building') {
        $id = cleanInt($_POST['id'] ?? 0);
        // Check if has assets
        $count = $db->prepare("SELECT COUNT(*) FROM assets WHERE building_id = ?");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            $msg = 'Tidak bisa hapus gedung yang masih memiliki aset.'; $msgType='danger';
        } else {
            $db->prepare("DELETE FROM buildings WHERE id = ?")->execute([$id]);
            $msg = 'Gedung berhasil dihapus.';
        }
    }

    elseif ($action === 'delete_room') {
        $id = cleanInt($_POST['id'] ?? 0);
        $count = $db->prepare("SELECT COUNT(*) FROM assets WHERE room_id = ?");
        $count->execute([$id]);
        if ($count->fetchColumn() > 0) {
            $msg = 'Tidak bisa hapus ruangan yang masih memiliki aset.'; $msgType='danger';
        } else {
            $db->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            $msg = 'Ruangan berhasil dihapus.';
        }
    }

    if (!$msg) $msg = ''; // clear if empty
}

// Get all buildings with rooms
$buildings = $db->query("
    SELECT b.*, COUNT(DISTINCT a.id) as asset_count
    FROM buildings b
    LEFT JOIN assets a ON a.building_id = b.id AND a.is_registered = 1
    GROUP BY b.id ORDER BY b.name
")->fetchAll();

$rooms_by_building = [];
$all_rooms = $db->query("
    SELECT r.*, COUNT(a.id) as asset_count
    FROM rooms r
    LEFT JOIN assets a ON a.room_id = r.id AND a.is_registered = 1
    GROUP BY r.id
")->fetchAll();
foreach ($all_rooms as $r) {
    $rooms_by_building[$r['building_id']][] = $r;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-primary"></i>Kelola Lokasi</h4>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i><?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- Add Building -->
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Tambah Gedung</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_building">
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Nama Gedung <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="100" placeholder="Gedung A, Gedung Utama...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Keterangan</label>
                        <input type="text" name="description" class="form-control form-control-sm" maxlength="200" placeholder="(opsional)">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus me-1"></i>Tambah Gedung</button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Tambah Ruangan</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_room">
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Gedung <span class="text-danger">*</span></label>
                        <select name="building_id" class="form-select form-select-sm" required>
                            <option value="">-- Pilih Gedung --</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Nama Ruangan <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="100" placeholder="Ruang Server, Lt.1 Depan...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Keterangan</label>
                        <input type="text" name="description" class="form-control form-control-sm" maxlength="200" placeholder="(opsional)">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus me-1"></i>Tambah Ruangan</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Building List -->
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-light fw-semibold"><i class="bi bi-building me-2"></i>Daftar Gedung & Ruangan</div>
            <div class="card-body p-0">
                <?php if ($buildings): foreach ($buildings as $b): ?>
                <div class="border-bottom p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="bi bi-building-fill me-2 text-primary"></i><?= clean($b['name']) ?></strong>
                            <?php if ($b['description']): ?><small class="text-muted ms-2"><?= clean($b['description']) ?></small><?php endif; ?>
                            <span class="badge bg-primary ms-2"><?= $b['asset_count'] ?> aset</span>
                        </div>
                        <form method="POST" onsubmit="return confirmDelete('Hapus gedung <?= clean($b['name']) ?>?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_building">
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>

                    <!-- Rooms -->
                    <?php if (!empty($rooms_by_building[$b['id']])): ?>
                    <div class="mt-2 ps-3">
                        <?php foreach ($rooms_by_building[$b['id']] as $r): ?>
                        <div class="d-flex justify-content-between align-items-center py-1 border-top">
                            <div>
                                <i class="bi bi-door-open me-2 text-muted"></i><?= clean($r['name']) ?>
                                <?php if ($r['description']): ?><small class="text-muted ms-1"><?= clean($r['description']) ?></small><?php endif; ?>
                                <span class="badge bg-secondary ms-1"><?= $r['asset_count'] ?></span>
                            </div>
                            <form method="POST" onsubmit="return confirmDelete('Hapus ruangan <?= clean($r['name']) ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_room">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.7rem;padding:2px 8px"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="ps-3 pt-1"><small class="text-muted fst-italic">Belum ada ruangan</small></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; else: ?>
                <div class="text-center py-5 text-muted"><i class="bi bi-building fs-2"></i><p>Belum ada gedung. Tambahkan gedung terlebih dahulu.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
