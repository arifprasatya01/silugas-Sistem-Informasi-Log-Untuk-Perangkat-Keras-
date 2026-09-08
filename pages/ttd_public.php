<?php
// ============================================================
// Halaman PUBLIK untuk tanda tangan Kainstal via link WhatsApp.
// Tidak memanggil requireLogin() secara sengaja - akses hanya
// dikontrol lewat token acak yang tidak bisa ditebak.
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();

$namaBulan = [
    1=>'Januari', 2=>'Februari', 3=>'Maret', 4=>'April', 5=>'Mei', 6=>'Juni',
    7=>'Juli', 8=>'Agustus', 9=>'September', 10=>'Oktober', 11=>'November', 12=>'Desember'
];

$uploadDir = __DIR__ . '/../uploads/ttd/';
if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

function hitungBelum($db, $bulan, $tahun) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
    $stmt->execute([$bulan, $tahun]);
    $n = (int) $stmt->fetchColumn();
    try {
        $stmtT = $db->prepare("SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
        $stmtT->execute([$bulan, $tahun]);
        $n += (int) $stmtT->fetchColumn();
    } catch (PDOException $e) {}
    return $n;
}

// ── Ambil token ───────────────────────────────────────────────
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$errorPage = null;

if (!$token) {
    $errorPage = 'Link tidak valid atau sudah kadaluarsa.';
} else {
    $stmt = $db->prepare("SELECT * FROM ttd_link WHERE token = ?");
    $stmt->execute([$token]);
    $link = $stmt->fetch();
    if (!$link) {
        $errorPage = 'Link tidak ditemukan. Silakan hubungi bagian IT untuk mendapatkan link baru.';
    }
}

// ── Handle submit tanda tangan ──────────────────────────────
$justSigned = false;
if (!$errorPage && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'public_sign') {
    csrf_verify();

    if ($link['status'] === 'signed') {
        $errorPage = 'Laporan ini sudah ditandatangani sebelumnya.';
    } else {
        $nama_ttd = trim($_POST['nama_ttd'] ?? '');
        $jabatan  = trim($_POST['jabatan'] ?? '') ?: 'Kepala Instalasi';
        $sig_data = $_POST['signature_data'] ?? '';

        if (!$nama_ttd || !$sig_data || !preg_match('/^data:image\/png;base64,(.+)$/', $sig_data, $m)) {
            $errorPage = 'Data tidak lengkap. Silakan isi nama dan tanda tangan, lalu coba lagi.';
        } else {
            $imgData = base64_decode($m[1]);
            $daftarBulan = json_decode($link['daftar_bulan'], true) ?: [];

            $filename = 'ttd_link_' . substr($link['token'], 0, 10) . '_' . time() . '.png';
            file_put_contents($uploadDir . $filename, $imgData);

            $createdIds = [];
            foreach ($daftarBulan as $item) {
                $bulan = (int)($item['bulan'] ?? 0);
                $tahun = (int)($item['tahun'] ?? 0);
                if (!$bulan || !$tahun) continue;

                // Hitung ulang saat ini (bisa berbeda dari saat link dibuat)
                $stmtLB = $db->prepare("SELECT COUNT(*) FROM logbook WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
                $stmtLB->execute([$bulan, $tahun]);
                $jml_lb = (int) $stmtLB->fetchColumn();

                $jml_tk = 0;
                try {
                    $stmtTK = $db->prepare("SELECT COUNT(*) FROM tiket WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL");
                    $stmtTK->execute([$bulan, $tahun]);
                    $jml_tk = (int) $stmtTK->fetchColumn();
                } catch (PDOException $e) {}

                if (($jml_lb + $jml_tk) === 0) continue; // sudah ditandatangani lewat jalur lain

                $db->prepare("
                    INSERT INTO ttd_bulanan (bulan, tahun, nama_ttd, jabatan, file_ttd, jumlah_logbook, jumlah_tiket, tanggal_ttd)
                    VALUES (?,?,?,?,?,?,?,NOW())
                ")->execute([$bulan, $tahun, $nama_ttd, $jabatan, $filename, $jml_lb, $jml_tk]);
                $idTtd = (int) $db->lastInsertId();
                $createdIds[] = $idTtd;

                $db->prepare("UPDATE logbook SET id_ttd=? WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL")
                   ->execute([$idTtd, $bulan, $tahun]);
                try {
                    $db->prepare("UPDATE tiket SET id_ttd=? WHERE MONTH(tanggal_lapor)=? AND YEAR(tanggal_lapor)=? AND id_ttd IS NULL")
                       ->execute([$idTtd, $bulan, $tahun]);
                } catch (PDOException $e) {}
            }

            $db->prepare("
                UPDATE ttd_link SET
                    status='signed', signed_at=NOW(), nama_ttd=?, jabatan=?, file_ttd=?,
                    ip_signed=?, user_agent_signed=?, ttd_bulanan_ids=?
                WHERE id=?
            ")->execute([
                $nama_ttd, $jabatan, $filename,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                implode(',', $createdIds),
                $link['id']
            ]);

            // refresh data link untuk ditampilkan
            $stmt = $db->prepare("SELECT * FROM ttd_link WHERE token = ?");
            $stmt->execute([$token]);
            $link = $stmt->fetch();
            $justSigned = true;
        }
    }
}

// ── Siapkan data tampilan ───────────────────────────────────
$daftarBulan = [];
$bulanLabelList = '';
if (!$errorPage) {
    $daftarBulan = json_decode($link['daftar_bulan'], true) ?: [];
    $labels = [];
    foreach ($daftarBulan as $item) {
        $b = (int)($item['bulan'] ?? 0);
        $t = (int)($item['tahun'] ?? 0);
        if ($b && $t) $labels[] = ($namaBulan[$b] ?? '') . ' ' . $t;
    }
    $bulanLabelList = implode(', ', $labels);
}

$ttdBulananRows = [];
if (!$errorPage && $link['status'] === 'signed' && !empty($link['ttd_bulanan_ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $link['ttd_bulanan_ids'])));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM ttd_bulanan WHERE id IN ($in) ORDER BY tahun, bulan");
        $stmt->execute($ids);
        $ttdBulananRows = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tanda Tangan Laporan Logbook Hardware</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
    body { background: #f4f6f9; }
    .ttd-card { max-width: 560px; margin: 32px auto; }
    #ttdCanvas { border: 1px dashed #999; border-radius: 8px; background: #fff; touch-action: none; cursor: crosshair; width: 100%; height: 200px; }
</style>
</head>
<body>
<div class="container">
    <div class="card shadow-sm ttd-card">
        <div class="card-body p-4">

        <?php if ($errorPage): ?>
            <div class="text-center py-4">
                <i class="bi bi-exclamation-triangle text-danger" style="font-size:2.5rem;"></i>
                <p class="mt-3 mb-0"><?= htmlspecialchars($errorPage) ?></p>
            </div>

        <?php elseif ($link['status'] === 'signed'): ?>
            <div class="text-center mb-3">
                <i class="bi bi-check-circle-fill text-success" style="font-size:2.5rem;"></i>
                <h5 class="mt-2 mb-0"><?= $justSigned ? 'Terima kasih, tanda tangan tersimpan' : 'Laporan ini sudah ditandatangani' ?></h5>
            </div>
            <p class="text-muted small mb-3">Laporan Logbook Hardware bulan <strong><?= htmlspecialchars($bulanLabelList) ?></strong></p>
            <table class="table table-sm">
                <tr><th style="width:40%;">Ditandatangani oleh</th><td><?= htmlspecialchars($link['nama_ttd']) ?></td></tr>
                <tr><th>Jabatan</th><td><?= htmlspecialchars($link['jabatan']) ?></td></tr>
                <tr><th>Tanggal TTD</th><td><?= date('d/m/Y H:i', strtotime($link['signed_at'])) ?></td></tr>
            </table>
            <?php if ($link['file_ttd']): ?>
            <div class="text-center border rounded p-2 bg-light">
                <img src="<?= APP_URL ?>/uploads/ttd/<?= htmlspecialchars($link['file_ttd']) ?>" style="max-height:120px;" alt="Tanda tangan">
            </div>
            <?php endif; ?>
            <p class="text-muted small mt-3 mb-0">Data ini sudah tersimpan di sistem dan akan didokumentasikan oleh bagian IT.</p>

        <?php else: ?>
            <h5 class="mb-1"><i class="bi bi-file-earmark-text text-primary me-1"></i>Laporan Logbook Hardware</h5>
            <p class="mb-3">
                Bulan <strong><?= htmlspecialchars($bulanLabelList) ?></strong><br>
                Mohon ijin Bapak/Ibu untuk menandatangani laporan di bawah ini.
            </p>

            <table class="table table-sm mb-3">
                <thead><tr><th>Bulan</th><th class="text-end">Jumlah Laporan</th></tr></thead>
                <tbody>
                <?php foreach ($daftarBulan as $item):
                    $b = (int)($item['bulan'] ?? 0); $t = (int)($item['tahun'] ?? 0);
                    if (!$b || !$t) continue;
                    $sisa = hitungBelum($db, $b, $t);
                    if ($sisa === 0) continue; // sudah ditandatangani via jalur lain sejak link dibuat
                ?>
                    <tr>
                        <td><?= ($namaBulan[$b] ?? '') . ' ' . $t ?></td>
                        <td class="text-end"><?= $sisa ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <form method="POST" id="formPublicTtd">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="public_sign">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="signature_data" id="signature_data">

                <div class="mb-3">
                    <label class="form-label">Nama Kainstal <span class="text-danger">*</span></label>
                    <input type="text" name="nama_ttd" class="form-control" required placeholder="Nama lengkap">
                </div>
                <div class="mb-3">
                    <label class="form-label">Jabatan</label>
                    <input type="text" name="jabatan" class="form-control" value="Kepala Instalasi">
                </div>
                <div class="mb-2">
                    <label class="form-label">Tanda Tangan <span class="text-danger">*</span></label>
                    <canvas id="ttdCanvas" width="520" height="200"></canvas>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary mb-3" onclick="clearCanvas()">
                    <i class="bi bi-eraser me-1"></i>Hapus &amp; Ulangi
                </button>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-save me-1"></i>Simpan Tanda Tangan
                </button>
            </form>
        <?php endif; ?>

        </div>
    </div>
</div>

<?php if (!$errorPage && $link['status'] !== 'signed'): ?>
<script>
let canvas, ctx, drawing = false;

function initCanvas() {
    canvas = document.getElementById('ttdCanvas');
    ctx = canvas.getContext('2d');
    ctx.lineWidth = 2.2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#111';
    clearCanvas();

    const pos = (e) => {
        const rect = canvas.getBoundingClientRect();
        const scaleX = canvas.width / rect.width;
        const scaleY = canvas.height / rect.height;
        const cx = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        const cy = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
        return { x: cx * scaleX, y: cy * scaleY };
    };
    const start = (e) => { drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); };
    const move  = (e) => { if (!drawing) return; const p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); };
    const end   = () => { drawing = false; };

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    canvas.addEventListener('mouseup', end);
    canvas.addEventListener('mouseleave', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);
}

function clearCanvas() {
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
}

function isCanvasEmpty() {
    const blank = document.createElement('canvas');
    blank.width = canvas.width; blank.height = canvas.height;
    const bctx = blank.getContext('2d');
    bctx.fillStyle = '#ffffff';
    bctx.fillRect(0, 0, blank.width, blank.height);
    return canvas.toDataURL() === blank.toDataURL();
}

initCanvas();

document.getElementById('formPublicTtd').addEventListener('submit', function (e) {
    if (isCanvasEmpty()) {
        e.preventDefault();
        alert('Tanda tangan belum diisi. Silakan gambar tanda tangan di kotak yang tersedia.');
        return;
    }
    document.getElementById('signature_data').value = canvas.toDataURL('image/png');
});
</script>
<?php endif; ?>
</body>
</html>