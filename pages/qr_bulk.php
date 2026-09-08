<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
define('PAGE_TITLE', 'Generate QR Bulk');
$db = getDB();

$generated = [];
$msg = '';

// Helper: fetch QR as base64 data URI (server-side, no client network dependency)
function qrDataUri($code) {
    $target = APP_URL . '/asset_detail?code=' . urlencode($code);
    $qrApi  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($target) . '&format=png&ecc=M&margin=2';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $img = @file_get_contents($qrApi, false, $ctx);
    if ($img === false) return '';
    return 'data:image/png;base64,' . base64_encode($img);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $qty        = cleanInt($_POST['quantity'] ?? 0);
    $prefix     = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['prefix'] ?? 'HW'));
    $batch_name = trim($_POST['batch_name'] ?? '');

    if ($qty < 1 || $qty > 200) {
        $msg = 'Jumlah harus antara 1 - 200.';
    } elseif (!$prefix) {
        $msg = 'Prefix wajib diisi.';
    } else {
        $db->prepare("INSERT INTO qr_batches (batch_name, quantity, prefix, created_by) VALUES (?,?,?,?)")
           ->execute([$batch_name ?: "Batch " . date('d/m/Y H:i'), $qty, $prefix, currentUser()['id']]);

        $bulk_building = cleanInt($_POST['building_id'] ?? 0);
        $bulk_room     = cleanInt($_POST['room_id'] ?? 0);

        $stmt   = $db->prepare("SELECT id FROM assets WHERE asset_code = ?");
        $insert = $db->prepare("INSERT INTO assets (asset_code, building_id, room_id, is_registered, created_at) VALUES (?, ?, ?, 0, NOW())");

        $lastStmt = $db->prepare("SELECT asset_code FROM assets WHERE asset_code LIKE ? ORDER BY id DESC LIMIT 1");
        $lastStmt->execute([$prefix . '-%']);
        $lastCode = $lastStmt->fetchColumn();
        $lastNum  = $lastCode ? (int) substr($lastCode, strlen($prefix)+1) : 0;

        for ($i = 1; $i <= $qty; $i++) {
            $lastNum++;
            $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);
            $stmt->execute([$code]);
            while ($stmt->fetch()) {
                $lastNum++;
                $code = $prefix . '-' . str_pad($lastNum, 4, '0', STR_PAD_LEFT);
                $stmt->execute([$code]);
            }
            $insert->execute([$code, $bulk_building ?: null, $bulk_room ?: null]);
            $generated[] = $code;
        }
        $msg = count($generated) . ' QR Code berhasil di-generate!';
    }
}

// Recent batches
$batches = $db->query("
    SELECT qb.*, u.name as created_by_name,
           (SELECT COUNT(*) FROM assets a WHERE a.asset_code LIKE CONCAT(qb.prefix,'-%') AND a.is_registered=0) as unregistered
    FROM qr_batches qb JOIN users u ON u.id = qb.created_by
    ORDER BY qb.created_at DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h4 class="fw-bold mb-0"><i class="bi bi-qr-code me-2 text-primary"></i>Generate QR Code Bulk</h4>
</div>

<div class="row g-3">
    <!-- Form Generate -->
    <div class="col-md-4 no-print">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Generate QR Baru</div>
            <div class="card-body">
                <?php if ($msg && !$generated): ?>
                <div class="alert alert-danger"><?= clean($msg) ?></div>
                <?php endif; ?>

                <form method="POST" id="generateForm">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nama Batch <small class="text-muted">(opsional)</small></label>
                        <input type="text" name="batch_name" class="form-control" placeholder="Contoh: Batch Gedung A" maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Prefix Kode</label>
                        <div class="input-group">
                            <input type="text" name="prefix" class="form-control" value="HW"
                                   maxlength="5" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')">
                            <span class="input-group-text text-muted">-0001</span>
                        </div>
                        <small class="text-muted">Contoh: HW → HW-0001, HW-0002</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jumlah QR <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" min="1" max="200" value="10" required>
                        <small class="text-muted">Maksimal 200 per batch</small>
                    </div>
                    <hr>
                    <p class="text-muted small mb-2"><i class="bi bi-geo-alt me-1"></i>Lokasi <small>(opsional — QR langsung terasosiasi ke lokasi ini)</small></p>
                    <div class="mb-2">
                        <select name="building_id" id="bulk_building" class="form-select form-select-sm"
                                onchange="bulkLoadRooms(this.value)">
                            <option value="">-- Pilih Gedung --</option>
                            <?php
                            $buildings_bulk = $db->query("SELECT id, name FROM buildings ORDER BY name")->fetchAll();
                            foreach ($buildings_bulk as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= clean($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <select name="room_id" id="bulk_room" class="form-select form-select-sm">
                            <option value="">-- Pilih Ruangan --</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-qr-code me-2"></i>Generate QR Code
                    </button>
                </form>
            </div>
        </div>

        <!-- Riwayat Batch -->
        <div class="card mt-3">
            <div class="card-header bg-light"><i class="bi bi-clock-history me-2"></i>Riwayat Batch</div>
            <div class="card-body p-0">
                <?php if ($batches): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Batch</th><th>Qty</th><th>Sisa</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($batches as $b): ?>
                        <tr>
                            <td>
                                <span class="fw-semibold"><?= clean($b['batch_name']) ?></span><br>
                                <small class="text-muted"><code><?= clean($b['prefix']) ?></code> · <?= date('d/m/y', strtotime($b['created_at'])) ?></small>
                            </td>
                            <td><?= $b['quantity'] ?></td>
                            <td><span class="badge bg-<?= $b['unregistered']>0?'warning text-dark':'success' ?>"><?= $b['unregistered'] ?></span></td>
                            <td>
                                <a href="<?= APP_URL ?>/asset_list?search=<?= urlencode($b['prefix'].'-') ?>&reg=0"
                                   class="btn btn-xs btn-outline-primary" style="font-size:.75rem">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-3 text-muted small"><i class="bi bi-inbox"></i> Belum ada batch</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Print Preview -->
    <div class="col-md-8">
        <?php if ($generated): ?>
        <!-- Success info -->
        <div class="alert alert-success d-flex justify-content-between align-items-center mb-3 no-print">
            <span><i class="bi bi-check-circle me-2"></i><?= clean($msg) ?></span>
            <button class="btn btn-success btn-sm fw-bold" onclick="printSelected()">
                <i class="bi bi-printer me-1"></i>Print Label Terpilih
            </button>
        </div>

        <?php
        // Pre-generate base64 QR for each code (server-side, reliable for print)
        // Build as indexed array (not assoc) to guarantee 1:1 order, no key collision risk
        $items = [];
        foreach ($generated as $idx => $c) {
            $items[] = [
                'code' => $c,
                'qr'   => qrDataUri($c),
            ];
        }
        ?>

        <!-- Preview area (screen only) -->
        <div class="card no-print">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <span><i class="bi bi-eye me-2"></i>Preview Label (<?= count($items) ?> label · ukuran 70×50mm)</span>
                <div>
                    <button type="button" class="btn btn-xs btn-outline-secondary" style="font-size:.75rem" onclick="toggleAll(true)">Pilih Semua</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary" style="font-size:.75rem" onclick="toggleAll(false)">Batal Semua</button>
                </div>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2" id="label-preview">
                    <?php foreach ($items as $item): ?>
                    <label class="label-preview-card border rounded p-2 d-flex align-items-center gap-2"
                         style="width:200px;background:#fff;cursor:pointer;">
                        <input type="checkbox" class="form-check-input label-checkbox" value="<?= clean($item['code']) ?>" checked style="flex-shrink:0">
                        <?php if ($item['qr']): ?>
                        <img src="<?= $item['qr'] ?>" width="60" height="60" alt="<?= clean($item['code']) ?>">
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold" style="font-size:.85rem;letter-spacing:.5px"><?= clean($item['code']) ?></div>
                            <div style="font-size:.7rem;color:#999">HW Monitor</div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- PRINT AREA: in-page, hidden on screen, visible only when printing -->
        <div id="print-area">
            <?php foreach ($items as $i => $item): ?>
            <div class="print-page">
            <div class="print-label" data-code="<?= clean($item['code']) ?>" data-idx="<?= $i ?>">
                <div class="print-qr-wrap">
                    <div class="print-simrs">SIMRS</div>
                    <?php if ($item['qr']): ?>
                    <img src="<?= $item['qr'] ?>" alt="<?= clean($item['code']) ?>" loading="eager">
                    <?php endif; ?>
                </div>
                <div class="print-label-info">
                    <div class="print-label-code"><?= clean($item['code']) ?></div>
                </div>
            </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Empty state -->
        <div class="card h-100 no-print">
            <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted py-5">
                <i class="bi bi-qr-code" style="font-size:4rem;opacity:.3"></i>
                <p class="mt-3 mb-0">QR Code yang di-generate akan muncul di sini</p>
                <small>Isi form di kiri lalu klik Generate</small>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
#print-area { display: none; }
@media print {
    @page { size: 70mm 50mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; width: 70mm !important; background: #fff !important; }

    /* Sembunyikan total (keluar dari flow), bukan cuma visibility */
    .no-print { display: none !important; }

    #print-area { display: block !important; width: 70mm !important; }

    .print-page {
        width: 70mm;
        height: 50mm;
        page-break-after: always;
        break-after: page;
        page-break-inside: avoid;
        break-inside: avoid;
        overflow: hidden;
        background: #fff;
    }
    .print-page:last-child { page-break-after: auto; break-after: auto; }

    .print-label {
        width: 70mm; height: 50mm;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1.5mm; padding: 3mm;
        box-sizing: border-box;
        background: #fff;
    }
    .print-qr-wrap { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
    .print-simrs { font-family: Arial; font-size: 13pt; font-weight: 900; letter-spacing: 3px; color: #000; margin-bottom: 1.5mm; }
    .print-label img { width: 26mm; height: 26mm; flex-shrink: 0; display: block; }
    .print-label-info { text-align: center; }
    .print-label-code { font-family: Arial; font-size: 15pt; font-weight: 900; letter-spacing: 1px; word-break: break-all; }
}
</style>
<script>
function toggleAll(checked) {
    document.querySelectorAll('.label-checkbox').forEach(cb => cb.checked = checked);
}

function printSelected() {
    const checked = Array.from(document.querySelectorAll('.label-checkbox:checked')).map(cb => cb.value);
    if (!checked.length) {
        alert('Pilih minimal 1 label untuk diprint.');
        return;
    }
    // Hapus seluruh halaman (.print-page) yang tidak dipilih dari print-area
    document.querySelectorAll('#print-area .print-page').forEach(page => {
        const label = page.querySelector('.print-label');
        if (!label || !checked.includes(label.dataset.code)) {
            page.remove();
        }
    });
    window.print();
}

function bulkLoadRooms(buildingId) {
    const sel = document.getElementById('bulk_room');
    sel.innerHTML = '<option value="">-- Pilih Ruangan --</option>';
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>