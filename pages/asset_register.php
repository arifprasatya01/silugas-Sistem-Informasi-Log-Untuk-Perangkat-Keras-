<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Daftarkan Aset');
$db = getDB();

$code = trim($_GET['code'] ?? '');
if (!$code) { header('Location: ' . APP_URL . '/asset_list'); exit; }

// Get or create asset stub
$stmt = $db->prepare("SELECT * FROM assets WHERE asset_code = ?");
$stmt->execute([$code]);
$asset = $stmt->fetch();

// If asset doesn't exist AND code doesn't look like our format, reject
if (!$asset) {
    // Create stub
    $db->prepare("INSERT INTO assets (asset_code, is_registered, created_at) VALUES (?, 0, NOW())")
       ->execute([$code]);
    $stmt->execute([$code]);
    $asset = $stmt->fetch();
}

if ($asset['is_registered']) {
    header('Location: ' . APP_URL . '/asset_detail?code=' . urlencode($code));
    exit;
}

$buildings = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();
$type_labels = [
    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name     = trim($_POST['name'] ?? '');
    $type     = $_POST['type'] ?? '';
    $brand    = trim($_POST['brand'] ?? '');
    $model    = trim($_POST['model'] ?? '');
    $serial   = trim($_POST['serial_number'] ?? '');
    $cond     = $_POST['condition_status'] ?? 'baik';
    $building = cleanInt($_POST['building_id'] ?? 0);
    $room     = cleanInt($_POST['room_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');

    if (!$name) $errors[] = 'Nama hardware wajib diisi.';
    if (!$type) $errors[] = 'Tipe hardware wajib dipilih.';
    if (!$building) $errors[] = 'Gedung wajib dipilih.';

    $allowed_types = array_keys($type_labels);
    $allowed_cond  = ['baik','perlu_perhatian','rusak'];
    if ($type && !in_array($type, $allowed_types)) $errors[] = 'Tipe tidak valid.';
    if (!in_array($cond, $allowed_cond)) $errors[] = 'Kondisi tidak valid.';

    if (!$errors) {
        $db->prepare("
            UPDATE assets SET
                name = ?, type = ?, brand = ?, model = ?, serial_number = ?,
                condition_status = ?, building_id = ?, room_id = ?, notes = ?,
                is_registered = 1, registered_by = ?, registered_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $name, $type, $brand ?: null, $model ?: null, $serial ?: null,
            $cond, $building, $room ?: null, $notes ?: null,
            currentUser()['id'], $asset['id']
        ]);

        // Log lokasi — hanya jika lokasi berubah dari yang sudah ada (hindari duplikat log
        // ketika aset ini sudah punya lokasi sejak generate QR dan lokasinya tidak diubah)
        $location_changed = ($building != $asset['building_id']) || ($room != ($asset['room_id'] ?? 0));
        if ($location_changed || !$asset['building_id']) {
            $bname = $db->prepare("SELECT name FROM buildings WHERE id = ?");
            $bname->execute([$building]);
            $bname = $bname->fetchColumn();
            $rname = $room ? $db->prepare("SELECT name FROM rooms WHERE id = ?") : null;
            if ($rname) { $rname->execute([$room]); $rname = $rname->fetchColumn(); }

            $db->prepare("INSERT INTO location_logs (asset_id, building_id, room_id, building_name, room_name, moved_by, notes) VALUES (?,?,?,?,?,?,?)")
               ->execute([$asset['id'], $building, $room ?: null, $bname, $rname ?: null, currentUser()['id'], 'Pendaftaran aset pertama']);
        }

        // Log maintenance
        $db->prepare("INSERT INTO maintenance_logs (asset_id, action, description, performed_by) VALUES (?,?,?,?)")
           ->execute([$asset['id'], 'Pendaftaran Aset', "Aset pertama kali didaftarkan ke sistem", currentUser()['id']]);

        header('Location: ' . APP_URL . '/asset_detail?code=' . urlencode($code) . '&ok=1');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-qr-code me-2"></i>Daftarkan Aset Baru
                <span class="badge bg-white text-primary ms-2"><?= clean($code) ?></span>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <p class="text-muted small"><i class="bi bi-info-circle me-1"></i>QR Code ini belum terdaftar. Silakan isi data hardware untuk mendaftarkannya.</p>

                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nama Hardware <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= clean($_POST['name'] ?? '') ?>"
                               placeholder="Contoh: PC Kasir 1, Laptop Staff HRD" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipe <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="">-- Pilih Tipe --</option>
                            <?php foreach ($type_labels as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_POST['type']??'')===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Brand</label>
                            <input type="text" name="brand" class="form-control" value="<?= clean($_POST['brand'] ?? '') ?>" placeholder="Asus, HP, Dell..." maxlength="100">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" class="form-control" value="<?= clean($_POST['model'] ?? '') ?>" placeholder="VivoBook 14..." maxlength="100">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="serial_number" class="form-control" value="<?= clean($_POST['serial_number'] ?? '') ?>" placeholder="S/N (opsional)" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Kondisi <span class="text-danger">*</span></label>
                        <select name="condition_status" class="form-select">
                            <option value="baik" <?= ($_POST['condition_status']??'baik')==='baik'?'selected':'' ?>>Baik</option>
                            <option value="perlu_perhatian" <?= ($_POST['condition_status']??'')==='perlu_perhatian'?'selected':'' ?>>Perlu Perhatian</option>
                            <option value="rusak" <?= ($_POST['condition_status']??'')==='rusak'?'selected':'' ?>>Rusak</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gedung <span class="text-danger">*</span></label>
                        <select name="building_id" id="reg_building" class="form-select" required onchange="loadRooms(this.value,'reg_room')">
                            <option value="">-- Pilih Gedung --</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($_POST['building_id'] ?? $asset['building_id'] ?? '')==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ruangan</label>
                        <select name="room_id" id="reg_room" class="form-select">
                            <option value="">-- Pilih Ruangan --</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan tambahan (opsional)" maxlength="500"><?= clean($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-check-circle me-2"></i>Daftarkan Aset
                        </button>
                        <a href="<?= APP_URL ?>/asset_list" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
const EXISTING_ROOM_ID = <?= (int)($asset['room_id'] ?? 0) ?>;
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
                if (r.id == EXISTING_ROOM_ID) opt.selected = true;
                sel.appendChild(opt);
            });
        });
}
document.addEventListener('DOMContentLoaded', function() {
    const bld = document.getElementById('reg_building');
    if (bld && bld.value) loadRooms(bld.value, 'reg_room');
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>