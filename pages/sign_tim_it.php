<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db   = getDB();
$user = currentUser();
if (($user['role'] ?? '') !== 'admin') {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$code = strtoupper(trim($_GET['code'] ?? ''));
$error = '';
$success = false;

if (!$code) {
    header('Location: ' . APP_URL . '/token_generate');
    exit;
}

$stmt = $db->prepare("SELECT * FROM hardware_submissions WHERE token_code = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$code]);
$submission = $stmt->fetch();

if (!$submission) {
    header('Location: ' . APP_URL . '/token_generate');
    exit;
}

if (!empty($submission['tim_it_signature_path'])) {
    header('Location: ' . APP_URL . '/token_generate');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tim_it_nama   = trim($_POST['tim_it_nama'] ?? '');
    $signature_b64 = $_POST['signature'] ?? '';

    if (!$tim_it_nama) {
        $error = 'Nama Tim IT wajib diisi.';
    } elseif (!$signature_b64 || !strpos($signature_b64, 'data:image/png;base64,') === 0) {
        $error = 'Tanda tangan wajib diisi.';
    } else {
        $dir = __DIR__ . '/../uploads/hardware_signatures/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $filename = $code . '_TIMITS_' . time() . '.png';
        $filepath = $dir . $filename;
        $imgdata  = base64_decode(str_replace('data:image/png;base64,', '', $signature_b64));
        if (file_put_contents($filepath, $imgdata)) {
            $rel = 'uploads/hardware_signatures/' . $filename;
            $upd = $db->prepare("UPDATE hardware_submissions SET tim_it_nama = ?, tim_it_signature_path = ? WHERE token_code = ?");
            $upd->execute([$tim_it_nama, $rel, $code]);
            $success = true;
        } else {
            $error = 'Gagal menyimpan file tanda tangan.';
        }
    }
}

define('PAGE_TITLE', 'TTD Tim IT — ' . $code);
include __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?>
<div class="alert alert-success d-flex align-items-center gap-3">
    <i class="bi bi-check-circle-fill fs-4"></i>
    <div>
        Tanda tangan Tim IT berhasil disimpan untuk token <strong><?= h($code) ?></strong>.
        <a href="<?= APP_URL ?>/token_generate" class="alert-link ms-2">Kembali ke daftar token</a>
    </div>
</div>
<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-pen me-2 text-success"></i>TTD Tim IT &mdash; <?= h($code) ?></h4>
    <a href="<?= APP_URL ?>/token_generate" class="btn btn-sm btn-outline-secondary">Batal</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:520px;">
    <div class="card-body">
        <form method="post" id="sigForm">
            <div class="mb-3">
                <label class="form-label fw-semibold">Nama Tim IT</label>
                <input type="text" name="tim_it_nama" class="form-control"
                       value="<?= h($_POST['tim_it_nama'] ?? '') ?>"
                       placeholder="Nama lengkap" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Tanda Tangan</label>
                <div class="border rounded bg-white" style="cursor:crosshair;">
                    <canvas id="sigCanvas" width="468" height="180"
                            style="display:block;width:100%;touch-action:none;"></canvas>
                </div>
                <div class="mt-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnClear">
                        <i class="bi bi-arrow-counterclockwise"></i> Hapus
                    </button>
                </div>
                <input type="hidden" name="signature" id="sigInput">
            </div>
            <button type="submit" class="btn btn-success w-100">
                <i class="bi bi-check-circle me-1"></i>Simpan TTD
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    let drawing = false, lastX = 0, lastY = 0;

    function getPos(e) {
        const r = canvas.getBoundingClientRect();
        const scaleX = canvas.width / r.width;
        const scaleY = canvas.height / r.height;
        const src = e.touches ? e.touches[0] : e;
        return [(src.clientX - r.left) * scaleX, (src.clientY - r.top) * scaleY];
    }

    canvas.addEventListener('mousedown',  e => { drawing = true; [lastX, lastY] = getPos(e); });
    canvas.addEventListener('mousemove',  e => { if (!drawing) return; const [x, y] = getPos(e); draw(lastX, lastY, x, y); lastX = x; lastY = y; });
    canvas.addEventListener('mouseup',    () => drawing = false);
    canvas.addEventListener('mouseleave', () => drawing = false);
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; [lastX, lastY] = getPos(e); }, { passive: false });
    canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const [x, y] = getPos(e); draw(lastX, lastY, x, y); lastX = x; lastY = y; }, { passive: false });
    canvas.addEventListener('touchend',   () => drawing = false);

    function draw(x1, y1, x2, y2) {
        ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke();
    }

    document.getElementById('btnClear').addEventListener('click', () => {
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
    });

    document.getElementById('sigForm').addEventListener('submit', () => {
        document.getElementById('sigInput').value = canvas.toDataURL('image/png');
    });
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>