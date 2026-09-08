<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Print Label per Lokasi');
$db = getDB();

$buildings   = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();
$building_id = cleanInt($_GET['building_id'] ?? 0);
$room_id     = cleanInt($_GET['room_id'] ?? 0);
$msg         = '';
$generated   = [];

// ── GENERATE QR untuk lokasi (POST) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $qty         = cleanInt($_POST['quantity'] ?? 0);
    $prefix      = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['prefix'] ?? 'HW'));
    $building_id = cleanInt($_POST['building_id'] ?? 0);
    $room_id     = cleanInt($_POST['room_id'] ?? 0);

    if ($qty < 1 || $qty > 200)  { $msg = 'Jumlah harus 1 - 200.'; }
    elseif (!$prefix)             { $msg = 'Prefix wajib diisi.'; }
    elseif (!$building_id)        { $msg = 'Pilih gedung terlebih dahulu.'; }
    else {
        $lastStmt = $db->prepare("SELECT asset_code FROM assets WHERE asset_code LIKE ? ORDER BY id DESC LIMIT 1");
        $lastStmt->execute([$prefix . '-%']);
        $lastCode = $lastStmt->fetchColumn();
        $lastNum  = $lastCode ? (int) substr($lastCode, strlen($prefix)+1) : 0;

        $check  = $db->prepare("SELECT id FROM assets WHERE asset_code = ?");
        $insert = $db->prepare("INSERT INTO assets (asset_code, building_id, room_id, is_registered, created_at) VALUES (?, ?, ?, 0, NOW())");

        for ($i = 1; $i <= $qty; $i++) {
            $lastNum++;
            $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);
            $check->execute([$code]);
            while ($check->fetch()) {
                $lastNum++;
                $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);
                $check->execute([$code]);
            }
            $insert->execute([$code, $building_id, $room_id ?: null]);
            $generated[] = $code;
        }
        $msg = count($generated) . ' QR berhasil di-generate untuk lokasi ini!';
    }
}

// ── AMBIL ASET EXISTING berdasarkan filter ───────────
$assets = [];
if ($building_id) {
    $where  = ["a.building_id = ?"];
    $params = [$building_id];
    if ($room_id) { $where[] = "a.room_id = ?"; $params[] = $room_id; }
    $stmt = $db->prepare("
        SELECT a.asset_code, a.name, a.is_registered,
               b.name as building_name, r.name as room_name
        FROM assets a
        LEFT JOIN buildings b ON b.id = a.building_id
        LEFT JOIN rooms r ON r.id = a.room_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.asset_code
    ");
    $stmt->execute($params);
    $assets = $stmt->fetchAll();
}

// Nama lokasi
$building_name = '';
$room_name_str = '';
if ($building_id) {
    $s = $db->prepare("SELECT name FROM buildings WHERE id = ?");
    $s->execute([$building_id]); $building_name = $s->fetchColumn();
}
if ($room_id) {
    $s = $db->prepare("SELECT name FROM rooms WHERE id = ?");
    $s->execute([$room_id]); $room_name_str = $s->fetchColumn();
}

// Assets to print = generated baru ATAU semua aset di lokasi tersebut
$print_assets = $generated ?: array_column($assets, 'asset_code');
// Build map for names
$asset_map = [];
foreach ($assets as $a) $asset_map[$a['asset_code']] = $a;

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
    <h4 class="fw-bold mb-0"><i class="bi bi-printer me-2 text-primary"></i>Print Label per Lokasi</h4>
    <?php if ($print_assets): ?>
    <button class="btn btn-primary no-print" onclick="openPrint()">
        <i class="bi bi-printer me-1"></i>Print <?= count($print_assets) ?> Label
    </button>
    <?php endif; ?>
</div>

<!-- Filter Lokasi -->
<div class="card mb-3 no-print">
    <div class="card-header bg-light fw-semibold"><i class="bi bi-geo-alt me-2"></i>Filter Lokasi</div>
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end" id="filterForm">
            <div class="col-12 col-md-4">
                <label class="form-label">Gedung</label>
                <select name="building_id" id="filter_building" class="form-select"
                        onchange="loadRooms(this.value, true)">
                    <option value="">-- Pilih Gedung --</option>
                    <?php foreach ($buildings as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $building_id==$b['id']?'selected':'' ?>>
                        <?= clean($b['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label">Ruangan <small class="text-muted">(kosong = semua)</small></label>
                <select name="room_id" id="filter_room" class="form-select">
                    <option value="">-- Semua Ruangan --</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Tampilkan</button>
                <a href="<?= APP_URL ?>/print_by_location" class="btn btn-outline-secondary"><i class="bi bi-x"></i></a>
            </div>
        </form>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $generated ? 'success' : 'danger' ?> alert-dismissible fade show no-print">
    <i class="bi bi-<?= $generated ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i><?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3 no-print">
    <!-- Generate QR untuk lokasi ini -->
    <div class="col-md-4">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-qr-code me-2"></i>Generate QR untuk Lokasi Ini
            </div>
            <div class="card-body">
                <p class="text-muted small">Generate QR baru dan langsung terasosiasi ke gedung/ruangan yang dipilih.</p>
                <form method="POST" id="generateForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="building_id" id="gen_building_id" value="<?= $building_id ?>">
                    <input type="hidden" name="room_id" id="gen_room_id" value="<?= $room_id ?>">
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Gedung <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="gen_building_sel"
                                onchange="genLoadRooms(this.value)">
                            <option value="">-- Pilih Gedung --</option>
                            <?php foreach ($buildings as $b): ?>
                            <option value="<?= $b['id'] ?>" <?= $building_id==$b['id']?'selected':'' ?>>
                                <?= clean($b['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Ruangan</label>
                        <select class="form-select form-select-sm" id="gen_room_sel"
                                onchange="document.getElementById('gen_room_id').value=this.value">
                            <option value="">-- Semua/Tidak Spesifik --</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Prefix</label>
                        <input type="text" name="prefix" class="form-control form-control-sm" value="HW"
                               maxlength="5" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jumlah QR <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control form-control-sm"
                               min="1" max="200" value="10" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-qr-code me-1"></i>Generate & Preview
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Aset existing di lokasi -->
    <div class="col-md-8">
        <?php if ($building_id): ?>
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-building me-2"></i>
                    <strong><?= clean($building_name) ?></strong>
                    <?= $room_name_str ? ' / ' . clean($room_name_str) : ' — Semua Ruangan' ?>
                    <span class="badge bg-primary ms-2"><?= count($assets) ?> aset</span>
                </span>
                <?php if ($assets): ?>
                <button class="btn btn-sm btn-outline-primary" onclick="openPrint()">
                    <i class="bi bi-printer me-1"></i>Print Semua
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($assets): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($assets as $a): ?>
                    <div class="border rounded p-2 d-flex align-items-center gap-2"
                         style="width:200px;background:#fff;">
                        <img src="<?= APP_URL ?>/api/generate_qr.php?code=<?= urlencode($a['asset_code']) ?>"
                             width="60" height="60" alt="<?= clean($a['asset_code']) ?>">
                        <div style="overflow:hidden;min-width:0">
                            <div class="fw-bold" style="font-size:.82rem"><?= clean($a['asset_code']) ?></div>
                            <?php if ($a['name']): ?>
                            <div class="text-muted" style="font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                <?= clean($a['name']) ?>
                            </div>
                            <?php endif; ?>
                            <span class="badge badge-<?= $a['is_registered']?'baik':'perlu_perhatian' ?>" style="font-size:.65rem">
                                <?= $a['is_registered'] ? 'Terdaftar' : 'Belum diisi' ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox fs-2"></i>
                    <p class="mt-2">Belum ada aset di lokasi ini.<br>
                    <small>Generate QR baru menggunakan form di kiri.</small></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card h-100">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted py-5">
                <i class="bi bi-building" style="font-size:3.5rem;opacity:.25"></i>
                <p class="mt-3 mb-0">Pilih gedung terlebih dahulu</p>
                <small>untuk melihat & generate aset per lokasi</small>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── PRINT AREA ── -->
<?php if ($print_assets): ?>
<div id="print-area">
    <?php foreach ($print_assets as $code):
        $a = $asset_map[$code] ?? null;
    ?>
    <div class="print-label">
        <div class="print-qr-wrap">
            <div class="print-simrs">SIMRS</div>
            <img src="<?= APP_URL ?>/api/generate_qr.php?code=<?= urlencode($code) ?>" alt="<?= clean($code) ?>">
        </div>
        <div class="print-label-info">
            <div class="print-label-code"><?= clean($code) ?></div>
            <?php if ($a && $a['name']): ?>
            <div class="print-label-name"><?= clean($a['name']) ?></div>
            <?php endif; ?>
            <div class="print-label-loc">
                <?php if ($building_name): ?>
                <?= clean($building_name) ?><?= $room_name_str ? ' / '.clean($room_name_str) : '' ?>
                <?php elseif ($a && $a['building_name']): ?>
                <?= clean($a['building_name']) ?><?= $a['room_name'] ? ' / '.clean($a['room_name']) : '' ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
#print-area { display: none; }
@media print {
    @page { size: 70mm 50mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; width: 70mm !important; background: #fff !important; }

    .no-print { display: none !important; }

    #print-area { display: block !important; width: 70mm !important; }

    .print-label {
        width: 70mm; height: 50mm;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.5mm; padding: 3mm;
        box-sizing: border-box;
        page-break-after: always; break-after: page;
        page-break-inside: avoid; break-inside: avoid; overflow: hidden;
    }
    .print-label:last-child { page-break-after: auto; break-after: auto; }

    .print-qr-wrap { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
    .print-simrs { font-family: Arial; font-size: 13pt; font-weight: 900; letter-spacing: 3px; color: #000; margin-bottom: 1.5mm; }
    .print-label img { width: 26mm; height: 26mm; flex-shrink: 0; display: block; }
    .print-label-info { text-align: center; }
    .print-label-code { font-family: Arial; font-size: 15pt; font-weight: 900; letter-spacing: 1px; word-break: break-all; }
    .print-label-name { font-family: Arial; font-size: 10pt; font-weight: 700; color: #333; margin-top: 1.5mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .print-label-loc  { font-family: Arial; font-size: 8pt; font-weight: 600; color: #555; margin-top: 1mm; }
}
</style>

<script>
function openPrint() {
    const codes = <?= json_encode($print_assets) ?>;
    if (!codes.length) {
        alert('Tidak ada label untuk diprint.');
        return;
    }
    window.print();
}

function loadRooms(buildingId, autoSubmit) {
    const sel = document.getElementById('filter_room');
    sel.innerHTML = '<option value="">-- Semua Ruangan --</option>';
    if (!buildingId) { if (autoSubmit) document.getElementById('filterForm').submit(); return; }
    fetch('<?= APP_URL ?>/api/get_rooms.php?building_id=' + buildingId)
        .then(r => r.json()).then(data => {
            data.forEach(r => {
                const o = document.createElement('option');
                o.value = r.id; o.textContent = r.name;
                if (r.id == <?= $room_id ?: 0 ?>) o.selected = true;
                sel.appendChild(o);
            });
            if (autoSubmit) document.getElementById('filterForm').submit();
        });
}

function genLoadRooms(buildingId) {
    document.getElementById('gen_building_id').value = buildingId;
    document.getElementById('gen_room_id').value = '';
    const sel = document.getElementById('gen_room_sel');
    sel.innerHTML = '<option value="">-- Semua/Tidak Spesifik --</option>';
    if (!buildingId) return;
    fetch('<?= APP_URL ?>/api/get_rooms.php?building_id=' + buildingId)
        .then(r => r.json()).then(data => {
            data.forEach(r => {
                const o = document.createElement('option');
                o.value = r.id; o.textContent = r.name;
                sel.appendChild(o);
            });
        });
}

document.addEventListener('DOMContentLoaded', () => {
    // Load filter rooms
    const fb = document.getElementById('filter_building');
    if (fb && fb.value) loadRooms(fb.value, false);

    // Load generate rooms
    const gb = document.getElementById('gen_building_sel');
    if (gb && gb.value) genLoadRooms(gb.value);

    // Sync generate room select to hidden input
    document.getElementById('gen_room_sel').addEventListener('change', function() {
        document.getElementById('gen_room_id').value = this.value;
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>