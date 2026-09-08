<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
define('PAGE_TITLE', 'Tambah Aset');
$db = getDB();

$buildings = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();
$type_labels = [
    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
];

$errors = [];
$success_code = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['prefix'] ?? 'HW'));
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
    if (!$type) $errors[] = 'Tipe wajib dipilih.';
    if (!$building) $errors[] = 'Gedung wajib dipilih.';

    if (!$errors) {
        // Generate code
        $lastStmt = $db->prepare("SELECT asset_code FROM assets WHERE asset_code LIKE ? ORDER BY id DESC LIMIT 1");
        $lastStmt->execute([$prefix . '-%']);
        $lastCode = $lastStmt->fetchColumn();
        $lastNum  = $lastCode ? (int) substr($lastCode, strlen($prefix)+1) : 0;
        $lastNum++;
        $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);

        // Ensure unique
        $check = $db->prepare("SELECT id FROM assets WHERE asset_code = ?");
        $check->execute([$code]);
        while ($check->fetch()) {
            $lastNum++;
            $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);
            $check->execute([$code]);
        }

        $hash = password_hash($code, PASSWORD_BCRYPT); // not used, just unique token

        $db->prepare("
            INSERT INTO assets (asset_code, name, type, brand, model, serial_number, condition_status,
                building_id, room_id, notes, is_registered, registered_by, registered_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,1,?,NOW())
        ")->execute([
            $code, $name, $type, $brand?:null, $model?:null, $serial?:null, $cond,
            $building, $room?:null, $notes?:null, currentUser()['id']
        ]);

        $id = $db->lastInsertId();

        // Location log
        $bname = $db->prepare("SELECT name FROM buildings WHERE id=?"); $bname->execute([$building]); $bname=$bname->fetchColumn();
        $rname = $room ? $db->prepare("SELECT name FROM rooms WHERE id=?") : null;
        if ($rname) { $rname->execute([$room]); $rname=$rname->fetchColumn(); }
        $db->prepare("INSERT INTO location_logs (asset_id, building_id, room_id, building_name, room_name, moved_by, notes) VALUES (?,?,?,?,?,?,?)")
           ->execute([$id, $building, $room?:null, $bname, $rname?:null, currentUser()['id'], 'Pendaftaran aset']);

        $db->prepare("INSERT INTO maintenance_logs (asset_id, action, description, performed_by) VALUES (?,?,?,?)")
           ->execute([$id, 'Pendaftaran Aset', 'Aset didaftarkan ke sistem', currentUser()['id']]);

        header('Location: ' . APP_URL . '/asset_detail?code=' . urlencode($code) . '&ok=1');
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Tambah Aset Manual</div>
            <div class="card-body">
                <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Prefix Kode</label>
                        <input type="text" name="prefix" class="form-control" value="HW" maxlength="5"
                               oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                        <small class="text-muted">Kode akan di-generate otomatis, contoh: HW-0001</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Nama Hardware <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= clean($_POST['name']??'') ?>" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipe <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach ($type_labels as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_POST['type']??'')===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3"><label class="form-label">Brand</label><input type="text" name="brand" class="form-control" value="<?= clean($_POST['brand']??'') ?>" maxlength="100"></div>
                        <div class="col-6 mb-3"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= clean($_POST['model']??'') ?>" maxlength="100"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control" value="<?= clean($_POST['serial_number']??'') ?>" maxlength="100"></div>
                    <div class="mb-3">
                        <label class="form-label">Kondisi</label>
                        <select name="condition_status" class="form-select">
                            <option value="baik">Baik</option>
                            <option value="perlu_perhatian">Perlu Perhatian</option>
                            <option value="rusak">Rusak</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Gedung <span class="text-danger">*</span></label>
                        <select name="building_id" id="add_building" class="form-select" required onchange="loadRooms(this.value,'add_room')">
                            <option value="">-- Pilih Gedung --</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ruangan</label>
                        <select name="room_id" id="add_room" class="form-select"><option value="">-- Pilih Ruangan --</option></select>
                    </div>
                    <div class="mb-3"><label class="form-label">Catatan</label><textarea name="notes" class="form-control" rows="2" maxlength="500"><?= clean($_POST['notes']??'') ?></textarea></div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>Tambah Aset</button>
                        <a href="<?= APP_URL ?>/asset_list" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function loadRooms(buildingId, targetId) {
    const sel = document.getElementById(targetId);
    sel.innerHTML = '<option value="">Memuat...</option>';
    if (!buildingId) { sel.innerHTML = '<option value="">-- Ruangan --</option>'; return; }
    fetch('<?= APP_URL ?>/api/get_rooms.php?building_id=' + buildingId)
        .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="">-- Ruangan --</option>';
            data.forEach(r => { const o=document.createElement('option'); o.value=r.id; o.textContent=r.name; sel.appendChild(o); });
        });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
