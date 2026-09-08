<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
define('PAGE_TITLE', 'Edit Aset');
$db = getDB();

$code = trim($_GET['code'] ?? '');
if (!$code) { header('Location: ' . APP_URL . '/asset_list'); exit; }

$stmt = $db->prepare("SELECT * FROM assets WHERE asset_code = ?");
$stmt->execute([$code]);
$asset = $stmt->fetch();
if (!$asset) { header('Location: ' . APP_URL . '/asset_list'); exit; }

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

    if (!$name) $errors[] = 'Nama wajib diisi.';
    if (!$type) $errors[] = 'Tipe wajib dipilih.';
    if (!$building) $errors[] = 'Gedung wajib dipilih.';

    if (!$errors) {
        // If location changed, log it
        if ($building != $asset['building_id'] || $room != $asset['room_id']) {
            $bname = $db->prepare("SELECT name FROM buildings WHERE id=?"); $bname->execute([$building]); $bname=$bname->fetchColumn();
            $rname = $room ? $db->prepare("SELECT name FROM rooms WHERE id=?") : null;
            if ($rname) { $rname->execute([$room]); $rname=$rname->fetchColumn(); }
            $db->prepare("INSERT INTO location_logs (asset_id, building_id, room_id, building_name, room_name, moved_by, notes) VALUES (?,?,?,?,?,?,?)")
               ->execute([$asset['id'], $building, $room?:null, $bname, $rname?:null, currentUser()['id'], 'Edit aset oleh admin']);
        }

        $db->prepare("UPDATE assets SET name=?,type=?,brand=?,model=?,serial_number=?,condition_status=?,building_id=?,room_id=?,notes=?,updated_at=NOW() WHERE id=?")
           ->execute([$name,$type,$brand?:null,$model?:null,$serial?:null,$cond,$building,$room?:null,$notes?:null,$asset['id']]);

        header('Location: ' . APP_URL . '/asset_detail?code=' . urlencode($code));
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-8 col-lg-6">
        <div class="card">
            <div class="card-header bg-warning"><i class="bi bi-pencil me-2"></i>Edit Aset <strong><?= clean($asset['asset_code']) ?></strong></div>
            <div class="card-body">
                <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label">Nama Hardware <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= clean($_POST['name']??$asset['name']) ?>" required maxlength="150"></div>
                    <div class="mb-3"><label class="form-label">Tipe <span class="text-danger">*</span></label>
                        <select name="type" class="form-select" required>
                            <option value="">-- Pilih --</option>
                            <?php foreach($type_labels as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_POST['type']??$asset['type'])===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="row">
                        <div class="col-6 mb-3"><label class="form-label">Brand</label><input type="text" name="brand" class="form-control" value="<?= clean($_POST['brand']??$asset['brand']??'') ?>" maxlength="100"></div>
                        <div class="col-6 mb-3"><label class="form-label">Model</label><input type="text" name="model" class="form-control" value="<?= clean($_POST['model']??$asset['model']??'') ?>" maxlength="100"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control" value="<?= clean($_POST['serial_number']??$asset['serial_number']??'') ?>" maxlength="100"></div>
                    <div class="mb-3"><label class="form-label">Kondisi</label>
                        <select name="condition_status" class="form-select">
                            <?php foreach(['baik'=>'Baik','perlu_perhatian'=>'Perlu Perhatian','rusak'=>'Rusak'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_POST['condition_status']??$asset['condition_status'])===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Gedung <span class="text-danger">*</span></label>
                        <select name="building_id" id="edit_building" class="form-select" required onchange="loadRooms(this.value,'edit_room')">
                            <option value="">-- Pilih --</option>
                            <?php foreach($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= ($_POST['building_id']??$asset['building_id'])==$b['id']?'selected':'' ?>><?= clean($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="mb-3"><label class="form-label">Ruangan</label>
                        <select name="room_id" id="edit_room" class="form-select"><option value="">-- Pilih --</option></select></div>
                    <div class="mb-3"><label class="form-label">Catatan</label><textarea name="notes" class="form-control" rows="2" maxlength="500"><?= clean($_POST['notes']??$asset['notes']??'') ?></textarea></div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-check-circle me-2"></i>Simpan Perubahan</button>
                        <a href="<?= APP_URL ?>/asset_detail?code=<?= urlencode($code) ?>" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
const CURRENT_ROOM = <?= (int)($asset['room_id']??0) ?>;
function loadRooms(buildingId, targetId) {
    const sel = document.getElementById(targetId);
    sel.innerHTML = '<option value="">Memuat...</option>';
    if (!buildingId) { sel.innerHTML = '<option value="">-- Ruangan --</option>'; return; }
    fetch('<?= APP_URL ?>/api/get_rooms.php?building_id=' + buildingId).then(r=>r.json()).then(data => {
        sel.innerHTML = '<option value="">-- Ruangan --</option>';
        data.forEach(r => {
            const o = document.createElement('option');
            o.value = r.id; o.textContent = r.name;
            if (r.id == CURRENT_ROOM) o.selected = true;
            sel.appendChild(o);
        });
    });
}
document.addEventListener('DOMContentLoaded', () => {
    const b = document.getElementById('edit_building');
    if (b && b.value) loadRooms(b.value, 'edit_room');
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
