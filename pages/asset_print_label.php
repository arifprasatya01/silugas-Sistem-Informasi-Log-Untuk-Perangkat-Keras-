<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../config/db.php';

$code      = trim($_GET['code'] ?? '');
$codes_raw = trim($_GET['codes'] ?? '');

$codes = [];
if ($code) {
    $codes = [$code];
} elseif ($codes_raw) {
    $codes = array_filter(array_map('trim', explode(',', $codes_raw)));
}

if (!$codes) {
    header('Location: ' . APP_URL . '/asset_list');
    exit;
}

$db = getDB();
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$stmt = $db->prepare("SELECT asset_code, name FROM assets WHERE asset_code IN ($placeholders)");
$stmt->execute(array_values($codes));
$assets = [];
while ($row = $stmt->fetch()) {
    $assets[$row['asset_code']] = $row['name'];
}

// Server-side QR fetch (sama persis dengan generate_token.php)
function qrDataUri($code) {
    $target = APP_URL . '/asset_detail?code=' . urlencode($code);
    $qrApi  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($target) . '&format=png&ecc=M&margin=2';
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $img = @file_get_contents($qrApi, false, $ctx);
    if ($img === false) return '';
    return 'data:image/png;base64,' . base64_encode($img);
}

// Build items dengan QR base64 server-side
$items = [];
foreach ($codes as $c) {
    if (!isset($assets[$c])) continue;
    $items[] = [
        'code' => $c,
        'name' => $assets[$c],
        'qr'   => qrDataUri($c),
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print Label</title>
<style>
#print-area { display: none; }
@media print {
    @page { size: 70mm 50mm; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; width: 70mm !important; background: #fff !important; }

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
    .print-label-name { font-family: Arial; font-size: 10pt; font-weight: 700; color: #333; margin-top: 1.5mm; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
}
</style>
</head>
<body>

<div class="screen-controls no-print">
    <div class="info">
        <strong>Print Label Aset</strong> &bull; <?= count($items) ?> label &bull; Ukuran: 70×50mm
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Print Sekarang</button>
</div>

<!-- PRINT AREA: hidden on screen, visible only when printing -->
<div id="print-area">
    <?php foreach ($items as $item): ?>
    <div class="print-page">
        <div class="print-label">
            <div class="print-qr-wrap">
                <div class="print-simrs">SIMRS</div>
                <?php if ($item['qr']): ?>
                <img src="<?= $item['qr'] ?>" alt="<?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?>" loading="eager">
                <?php endif; ?>
            </div>
            <div class="print-label-info">
                <div class="print-label-code"><?= htmlspecialchars($item['code'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script>
if (document.referrer || window.opener) {
    window.addEventListener('load', function() {
        setTimeout(() => window.print(), 200);
    });
}
</script>
</body>
</html>