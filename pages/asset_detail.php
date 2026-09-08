<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$db = getDB();

$code = trim($_GET['code'] ?? '');
if (!$code) {
    header('Location: ' . APP_URL . '/asset_list');
    exit;
}

// Get asset
$stmt = $db->prepare("
    SELECT a.*, b.name as building_name, r.name as room_name,
           u.name as registered_by_name
    FROM assets a
    LEFT JOIN buildings b ON b.id = a.building_id
    LEFT JOIN rooms r ON r.id = a.room_id
    LEFT JOIN users u ON u.id = a.registered_by
    WHERE a.asset_code = ?
");
$stmt->execute([$code]);
$asset = $stmt->fetch();

// QR code doesn't exist at all → buat stub dulu di asset_register
if (!$asset) {
    header('Location: ' . APP_URL . '/asset_register?code=' . urlencode($code));
    exit;
}

// Sudah punya lokasi (gedung) → tetap masuk ke detail, meski nama/tipe belum lengkap
// Belum punya lokasi sama sekali → harus diisi dulu lewat asset_register
if (!$asset['is_registered'] && !$asset['building_id']) {
    header('Location: ' . APP_URL . '/asset_register?code=' . urlencode($code));
    exit;
}

define('PAGE_TITLE', 'Detail Aset ' . $asset['asset_code']);

// Handle add maintenance POST
$msg = '';
$msgType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    csrf_verify();
    $action = trim($_POST['action'] ?? '');
    $desc   = trim($_POST['description'] ?? '');
    $new_status = $_POST['condition_status'] ?? $asset['condition_status'];
    $new_building = cleanInt($_POST['building_id'] ?? 0);
    $new_room     = cleanInt($_POST['room_id'] ?? 0);

    if (!$action) {
        $msg = 'Nama kegiatan wajib diisi.';
        $msgType = 'danger';
    } else {
        // Insert maintenance log
        $db->prepare("INSERT INTO maintenance_logs (asset_id, action, description, performed_by) VALUES (?,?,?,?)")
           ->execute([$asset['id'], $action, $desc ?: null, currentUser()['id']]);

        // Update condition if changed
        if ($new_status !== $asset['condition_status']) {
            $db->prepare("UPDATE assets SET condition_status = ? WHERE id = ?")
               ->execute([$new_status, $asset['id']]);
        }

        // Update location if changed
        if ($new_building && ($new_building != $asset['building_id'] || $new_room != $asset['room_id'])) {
            // Get building & room names for history
            $bname = $db->prepare("SELECT name FROM buildings WHERE id = ?");
            $bname->execute([$new_building]);
            $bname = $bname->fetchColumn();

            $rname = $new_room ? $db->prepare("SELECT name FROM rooms WHERE id = ?") : null;
            if ($rname) { $rname->execute([$new_room]); $rname = $rname->fetchColumn(); }

            // Log location
            $db->prepare("INSERT INTO location_logs (asset_id, building_id, room_id, building_name, room_name, moved_by, notes) VALUES (?,?,?,?,?,?,?)")
               ->execute([$asset['id'], $new_building, $new_room ?: null, $bname, $rname ?: null, currentUser()['id'], 'Update via maintenance']);

            // Update asset
            $db->prepare("UPDATE assets SET building_id = ?, room_id = ? WHERE id = ?")
               ->execute([$new_building, $new_room ?: null, $asset['id']]);
        }

        $msg = 'Maintenance berhasil dicatat!';
        // Reload
        header('Location: ' . APP_URL . '/asset_detail?code=' . urlencode($code) . '&ok=1');
        exit;
    }
}
if (isset($_GET['ok'])) { $msg = 'Maintenance berhasil dicatat!'; $msgType = 'success'; }

// Reload asset after possible update
$stmt->execute([$code]);
$asset = $stmt->fetch();

// Maintenance history
$maintenance = $db->prepare("
    SELECT ml.*, u.name as user_name
    FROM maintenance_logs ml
    JOIN users u ON u.id = ml.performed_by
    WHERE ml.asset_id = ?
    ORDER BY ml.performed_at DESC
");
$maintenance->execute([$asset['id']]);
$maintenance = $maintenance->fetchAll();

// Location history
$locations = $db->prepare("
    SELECT ll.*, u.name as user_name
    FROM location_logs ll
    JOIN users u ON u.id = ll.moved_by
    WHERE ll.asset_id = ?
    ORDER BY ll.moved_at DESC
");
$locations->execute([$asset['id']]);
$locations = $locations->fetchAll();

// Buildings for form
$buildings = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();

$type_labels = [
    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
];
$cond_labels = ['baik'=>'Baik','perlu_perhatian'=>'Perlu Perhatian','rusak'=>'Rusak'];

include __DIR__ . '/../includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i><?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!$asset['is_registered']): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-exclamation-triangle me-2"></i>Data hardware ini belum lengkap (nama/tipe belum diisi).</span>
    <a href="<?= APP_URL ?>/asset_register?code=<?= urlencode($code) ?>" class="btn btn-sm btn-warning fw-bold">
        <i class="bi bi-pencil-square me-1"></i>Lengkapi Data
    </a>
</div>
<?php endif; ?>

<!-- Asset Header -->
<div class="asset-detail-header">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <div class="asset-code-badge mb-2"><?= clean($asset['asset_code']) ?></div>
            <h4 class="fw-bold mb-1"><?= $asset['name'] ? clean($asset['name']) : '<em>Nama belum diisi</em>' ?></h4>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php if ($asset['type']): ?>
                <span class="badge bg-white text-primary"><?= clean($type_labels[$asset['type']] ?? $asset['type']) ?></span>
                <?php endif; ?>
                <span class="badge badge-<?= $asset['condition_status'] ?>"><?= $cond_labels[$asset['condition_status']] ?></span>
                <?php if ($asset['building_name']): ?>
                <span class="text-white-50"><i class="bi bi-building me-1"></i><?= clean($asset['building_name']) ?><?= $asset['room_name'] ? ' / '.clean($asset['room_name']) : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/asset_print_label?code=<?= urlencode($code) ?>" class="btn btn-sm btn-light" target="_blank">
                <i class="bi bi-printer me-1"></i>Print Label
            </a>
            <?php if (currentUser()['role'] === 'admin'): ?>
            <a href="<?= APP_URL ?>/asset_edit?code=<?= urlencode($code) ?>" class="btn btn-sm btn-warning">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 1. Informasi Aset -->
<div class="card mb-3">
    <div class="card-header bg-light"><i class="bi bi-info-circle me-2"></i>Informasi Aset</div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted" width="35%">Nama Hardware</td><td><strong><?= $asset['name'] ? clean($asset['name']) : '-' ?></strong></td></tr>
            <tr><td class="text-muted">Brand / Model</td><td><?= clean(($asset['brand'] ?? '-') . ' ' . ($asset['model'] ?? '')) ?></td></tr>
            <tr><td class="text-muted">Serial Number</td><td><?= clean($asset['serial_number'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Gedung</td><td><?= $asset['building_name'] ? clean($asset['building_name']) : '-' ?></td></tr>
            <tr><td class="text-muted">Ruangan</td><td><?= $asset['room_name'] ? clean($asset['room_name']) : '-' ?></td></tr>
            <tr><td class="text-muted">Kondisi</td><td><span class="badge badge-<?= $asset['condition_status'] ?>"><?= $cond_labels[$asset['condition_status']] ?></span></td></tr>
            <tr><td class="text-muted">Didaftarkan oleh</td><td><?= clean($asset['registered_by_name'] ?? '-') ?></td></tr>
            <tr><td class="text-muted">Terdaftar</td><td><?= $asset['registered_at'] ? date('d M Y H:i', strtotime($asset['registered_at'])) : '-' ?></td></tr>
            <?php if ($asset['notes']): ?>
            <tr><td class="text-muted">Catatan</td><td><?= clean($asset['notes']) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- 2. History Tabs (Maintenance & Lokasi) -->
<ul class="nav nav-tabs mb-3" id="historyTab">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tab-maintenance">
            <i class="bi bi-tools me-1"></i>History Maintenance
            <span class="badge bg-primary ms-1"><?= count($maintenance) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tab-location">
            <i class="bi bi-geo-alt me-1"></i>History Lokasi
            <span class="badge bg-secondary ms-1"><?= count($locations) ?></span>
        </a>
    </li>
</ul>

<div class="tab-content mb-3">
    <!-- Maintenance History -->
    <div class="tab-pane fade show active" id="tab-maintenance">
        <div class="card">
            <div class="card-body">
                <?php if ($maintenance): ?>
                <div class="timeline">
                    <?php foreach ($maintenance as $m): ?>
                    <div class="timeline-item">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                            <div>
                                <strong><?= clean($m['action']) ?></strong>
                                <div class="time"><i class="bi bi-person me-1"></i><?= clean($m['user_name']) ?> &bull; <?= date('d M Y H:i', strtotime($m['performed_at'])) ?></div>
                                <?php if ($m['description']): ?>
                                <p class="mb-0 text-muted small mt-1"><?= clean($m['description']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-2"></i><p>Belum ada history maintenance</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Location History -->
    <div class="tab-pane fade" id="tab-location">
        <div class="card">
            <div class="card-body">
                <?php if ($locations): ?>
                <div class="timeline">
                    <?php foreach ($locations as $l): ?>
                    <div class="timeline-item">
                        <div>
                            <strong><i class="bi bi-building me-1"></i><?= clean($l['building_name'] ?? '-') ?><?= $l['room_name'] ? ' / '.clean($l['room_name']) : '' ?></strong>
                            <div class="time"><i class="bi bi-person me-1"></i><?= clean($l['user_name']) ?> &bull; <?= date('d M Y H:i', strtotime($l['moved_at'])) ?></div>
                            <?php if ($l['notes']): ?>
                            <p class="mb-0 text-muted small mt-1"><?= clean($l['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-inbox fs-2"></i><p>Belum ada history lokasi</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- 3. Tambah Maintenance -->
<div class="card border-primary">
    <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Tambah Maintenance</div>
    <div class="card-body">
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="add_maintenance" value="1">
            <div class="mb-2">
                <label class="form-label form-label-sm">Kegiatan <span class="text-danger">*</span></label>
                <input type="text" name="action" class="form-control form-control-sm"
                       placeholder="Contoh: Ganti RAM, Bersihkan debu, Instal ulang OS..."
                       value="<?= clean($_POST['action'] ?? '') ?>" required maxlength="200">
            </div>
            <div class="mb-2">
                <label class="form-label form-label-sm">Keterangan</label>
                <textarea name="description" class="form-control form-control-sm" rows="2"
                          placeholder="Detail pekerjaan (opsional)"><?= clean($_POST['description'] ?? '') ?></textarea>
            </div>
            <div class="row">
                <div class="col-md-6 mb-2">
                    <label class="form-label form-label-sm">Update Kondisi</label>
                    <select name="condition_status" class="form-select form-select-sm">
                        <option value="baik" <?= $asset['condition_status']==='baik'?'selected':'' ?>>Baik</option>
                        <option value="perlu_perhatian" <?= $asset['condition_status']==='perlu_perhatian'?'selected':'' ?>>Perlu Perhatian</option>
                        <option value="rusak" <?= $asset['condition_status']==='rusak'?'selected':'' ?>>Rusak</option>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label form-label-sm">Gedung (jika pindah)</label>
                    <select name="building_id" id="mnt_building" class="form-select form-select-sm" onchange="loadRooms(this.value,'mnt_room')">
                        <option value="">-- Gedung --</option>
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $asset['building_id']==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label form-label-sm">Ruangan</label>
                    <select name="room_id" id="mnt_room" class="form-select form-select-sm">
                        <option value="">-- Ruangan --</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm w-100 mt-2">
                <i class="bi bi-check-circle me-1"></i>Simpan Maintenance
            </button>
        </form>
    </div>
</div>

<script>
function loadRooms(buildingId, targetId) {
    const sel = document.getElementById(targetId);
    sel.innerHTML = '<option value="">Memuat...</option>';
    if (!buildingId) { sel.innerHTML = '<option value="">-- Ruangan --</option>'; return; }
    fetch('<?= APP_URL ?>/api/get_rooms.php?building_id=' + buildingId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Ruangan --</option>';
            data.forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.id; opt.textContent = r.name;
                <?php if ($asset['room_id']): ?>
                if (r.id == <?= (int)$asset['room_id'] ?>) opt.selected = true;
                <?php endif; ?>
                sel.appendChild(opt);
            });
        });
}
// Auto load rooms on page load
document.addEventListener('DOMContentLoaded', function() {
    const bld = document.getElementById('mnt_building');
    if (bld && bld.value) loadRooms(bld.value, 'mnt_room');
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>