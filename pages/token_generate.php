<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Token Serah Terima Perangkat');

$db   = getDB();
$user = currentUser();

// Hanya admin yang boleh membuat token
if (($user['role'] ?? '') !== 'admin') {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

function formTypeLabel($type) {
    return $type === 'lapor' ? 'Lapor Kerusakan (User &rarr; SIMRS)' : 'Serah Perangkat (SIMRS &rarr; User)';
}

$generated_code = null;
$generated_type = null;

// ── Buat token baru ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $form_type = ($_POST['form_type'] ?? '') === 'serah' ? 'serah' : 'lapor';

    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa 0,O,1,I biar tidak rancu
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $check = $db->prepare("SELECT id FROM hardware_tokens WHERE code = ?");
        $check->execute([$code]);
        $exists = (bool) $check->fetchColumn();
    } while ($exists);

    $stmt = $db->prepare("INSERT INTO hardware_tokens (code, form_type, status, created_by, created_at) VALUES (?, ?, 'unused', ?, NOW())");
    $stmt->execute([$code, $form_type, $user['id'] ?? null]);

    $generated_code = $code;
    $generated_type = $form_type;
}

// ── Buat token Berita Acara (tabel terpisah, tidak bentrok) ─
$ba_generated_code = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_ba') {
    $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $ba_code = '';
        for ($i = 0; $i < 6; $i++) {
            $ba_code .= $charset[random_int(0, strlen($charset) - 1)];
        }
        $check = $db->prepare("SELECT id FROM berita_acara WHERE code = ?");
        $check->execute([$ba_code]);
        $exists = (bool) $check->fetchColumn();
    } while ($exists);

    $stmt = $db->prepare("INSERT INTO berita_acara (code, status, created_by, created_at, tanggal) VALUES (?, 'open', ?, NOW(), CURDATE())");
    $stmt->execute([$ba_code, $user['id'] ?? null]);

    $ba_generated_code = $ba_code;
}

// ── Lihat detail isian (?view=KODE) ───────────────────────
$view_code       = isset($_GET['view']) ? strtoupper(trim($_GET['view'])) : null;
$view_submission = null;
$view_items      = [];

if ($view_code) {
    $stmt = $db->prepare("SELECT * FROM hardware_submissions WHERE token_code = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$view_code]);
    $view_submission = $stmt->fetch();
    if ($view_submission) {
        $view_items = json_decode($view_submission['items'], true) ?: [];
    }
}

// ── Daftar token ───────────────────────────────────────────
$tokens = $db->query("SELECT * FROM hardware_tokens ORDER BY created_at DESC LIMIT 200")->fetchAll();
$ba_list = $db->query("SELECT * FROM berita_acara ORDER BY created_at DESC LIMIT 200")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-key-fill me-2 text-primary"></i>Token Serah Terima Perangkat</h4>
</div>

<?php if ($generated_code): ?>
<div class="alert alert-success d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <div class="fs-4 fw-bold" style="letter-spacing:2px;"><?= clean($generated_code) ?></div>
        <div class="small text-muted"><?= formTypeLabel($generated_type) ?></div>
        <div class="small mt-1"><code id="ticketLink"><?= APP_URL ?>/form.php?token=<?= $generated_code ?></code></div>
    </div>
    <button type="button" class="btn btn-sm btn-outline-success" onclick="copyTicketLink()">
        <i class="bi bi-clipboard me-1"></i>Salin Tautan
    </button>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Buat Token Baru</div>
    <div class="card-body">
        <p class="text-muted small mb-3">Satu token hanya berlaku untuk satu kali pengisian. User tidak bisa membuat token sendiri.</p>
        <form method="post" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="generate">
            <div class="col-md-8">
                <label class="form-label">Jenis Formulir</label>
                <div class="d-flex gap-3 flex-wrap">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="form_type" value="lapor" id="typeLapor" checked>
                        <label class="form-check-label" for="typeLapor">Lapor Kerusakan (User &rarr; SIMRS)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="form_type" value="serah" id="typeSerah">
                        <label class="form-check-label" for="typeSerah">Serah Perangkat (SIMRS &rarr; User)</label>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-key me-1"></i>Buat Token</button>
            </div>
        </form>
    </div>
</div>

<?php if ($view_code): ?>
<div class="card mb-4">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <span><i class="bi bi-eye me-2"></i>Detail Isian &mdash; <?= clean($view_code) ?></span>
        <div class="d-flex gap-2">
            <?php if ($view_submission): ?>
            <a href="<?= APP_URL ?>/print_form?code=<?= clean($view_code) ?>" target="_blank" class="btn btn-sm btn-warning">
                <i class="bi bi-printer me-1"></i>Cetak
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/token_generate" class="btn btn-sm btn-light">Tutup</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$view_submission): ?>
            <div class="text-muted">Data isian tidak ditemukan untuk token ini.</div>
        <?php else: ?>
            <?php
            $hw_label = $view_submission['form_type'] === 'lapor' ? 'Perangkat Bermasalah' : 'Perangkat Diserahkan';
            $hw_col2  = $view_submission['form_type'] === 'lapor' ? 'Keterangan Masalah' : 'Kondisi / Keterangan';
            ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="small text-muted">Unit</div><div class="fw-semibold"><?= clean($view_submission['unit']) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Bagian</div><div class="fw-semibold"><?= clean($view_submission['bagian']) ?></div></div>
                <div class="col-md-3"><div class="small text-muted"><?= $view_submission['form_type'] === 'lapor' ? 'Nama Pengisi' : 'Diterima oleh' ?></div><div class="fw-semibold"><?= clean($view_submission['nama']) ?></div></div>
                <div class="col-md-3"><div class="small text-muted">Tanggal</div><div class="fw-semibold"><?= clean($view_submission['tanggal']) ?></div></div>
                <?php if (!empty($view_submission['diserahkan_oleh'])): ?>
                <div class="col-md-3"><div class="small text-muted">Diserahkan oleh</div><div class="fw-semibold"><?= clean($view_submission['diserahkan_oleh']) ?></div></div>
                <?php endif; ?>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-hover">
                    <thead><tr><th>No</th><th><?= clean($hw_label) ?></th><th><?= clean($hw_col2) ?></th></tr></thead>
                    <tbody>
                    <?php foreach ($view_items as $i => $it): ?>
                        <tr><td><?= $i + 1 ?></td><td><?= clean($it['nama'] ?? '') ?></td><td><?= clean($it['keterangan'] ?? '') ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted mb-1">Tanda Tangan</div>
            <div class="d-flex gap-3 flex-wrap">
                <?php if (!empty($view_submission['tim_it_signature_path'])): ?>
                <div>
                    <div class="small text-muted mb-1">Tim IT</div>
                    <div class="border rounded p-2 bg-white d-inline-block">
                        <img src="<?= APP_URL ?>/<?= clean($view_submission['tim_it_signature_path']) ?>" alt="Tanda tangan Tim IT" style="max-width:280px;">
                    </div>
                </div>
                <?php endif; ?>
                <div>
                    <?php if (!empty($view_submission['tim_it_signature_path'])): ?>
                    <div class="small text-muted mb-1">Unit / Bagian</div>
                    <?php endif; ?>
                    <div class="border rounded p-2 bg-white d-inline-block">
                        <img src="<?= APP_URL ?>/<?= clean($view_submission['signature_path']) ?>" alt="Tanda tangan" style="max-width:280px;">
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-primary text-white"><i class="bi bi-list-ul me-2"></i>Daftar Token</div>
    <div class="card-body p-0">
        <?php if (!$tokens): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-2">Belum ada token. Buat token baru di atas.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Token</th><th>Jenis</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tokens as $t): $isUsed = $t['status'] === 'used'; $isPending = $t['status'] === 'pending'; ?>
                    <tr>
                        <td class="fw-semibold"><?= clean($t['code']) ?></td>
                        <td><?= $t['form_type'] === 'lapor' ? 'Lapor Kerusakan' : 'Serah Perangkat' ?></td>
                        <td>
                            <?php if ($isUsed): ?>
                                <span class="badge bg-secondary">Sudah digunakan</span>
                            <?php elseif ($isPending): ?>
                                <span class="badge bg-warning text-dark">Menunggu Penerima</span>
                            <?php else: ?>
                                <span class="badge bg-success">Belum digunakan</span>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= date('d/m/y H:i', strtotime($t['created_at'])) ?></small></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= APP_URL ?>/form.php?token=<?= $t['code'] ?>">
                                    <i class="bi bi-link-45deg"></i> Salin
                                </button>
                                <?php if (!$isUsed && !$isPending && $t['form_type'] === 'serah'): ?>
                                <a href="<?= APP_URL ?>/sign_tim_it?code=<?= $t['code'] ?>" class="btn btn-sm btn-success">
                                    <i class="bi bi-pen"></i> TTD Tim IT
                                </a>
                                <?php endif; ?>
                                <?php if ($isPending): ?>
                                <a href="<?= APP_URL ?>/sign_serah?code=<?= $t['code'] ?>" class="btn btn-sm btn-success">
                                    <i class="bi bi-pen"></i> TTD Penerima
                                </a>
                                <?php endif; ?>
                                <?php if ($isUsed): ?>
                                <a href="<?= APP_URL ?>/token_generate?view=<?= $t['code'] ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-eye"></i> Lihat
                                </a>
                                <a href="<?= APP_URL ?>/print_form?code=<?= $t['code'] ?>" target="_blank" class="btn btn-sm btn-warning">
                                    <i class="bi bi-printer"></i> Cetak
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<hr class="my-4">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Berita Acara Serah Terima</h4>
</div>

<div class="card mb-4">
    <div class="card-header bg-primary text-white"><i class="bi bi-plus-circle me-2"></i>Buat Token Berita Acara</div>
    <div class="card-body">
        <p class="text-muted small mb-3">Dokumen resmi: pihak Yang Menyerahkan dan Yang Menerima isi data &amp; TTD masing-masing secara terpisah.</p>
        <?php if ($ba_generated_code): ?>
        <div class="ticket">
            <div>
                <div class="mono code"><?= clean($ba_generated_code) ?></div>
                <div class="meta">Berita Acara Serah Terima Perangkat Keras</div>
                <div class="link-box mono"><?= APP_URL ?>/berita_acara?code=<?= $ba_generated_code ?></div>
            </div>
            <button class="btn btn-ghost btn-sm" data-copy="<?= APP_URL ?>/berita_acara?code=<?= $ba_generated_code ?>">Salin Tautan</button>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="generate_ba">
            <button type="submit" class="btn btn-primary"><i class="bi bi-key me-1"></i>Buat Token Berita Acara</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-primary text-white"><i class="bi bi-list-ul me-2"></i>Daftar Berita Acara</div>
    <div class="card-body p-0">
        <?php if (!$ba_list): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1"></i>
                <p class="mt-2">Belum ada Berita Acara. Buat token baru di atas.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Token</th><th>Status</th><th>Menyerahkan</th><th>Menerima</th><th>Dibuat</th><th>Aksi</th></tr>
                </thead>
                <tbody>
                <?php foreach ($ba_list as $b): $baComplete = $b['status'] === 'complete'; ?>
                    <tr>
                        <td class="fw-semibold"><?= clean($b['code']) ?></td>
                        <td>
                            <?php if ($baComplete): ?>
                                <span class="badge bg-secondary">Lengkap</span>
                            <?php else: ?>
                                <span class="badge bg-success">Belum Lengkap</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $b['menyerahkan_signature_path'] ? '<i class="bi bi-check-circle-fill text-success"></i> '.clean($b['menyerahkan_nama']) : '<span class="text-muted">Belum TTD</span>' ?></td>
                        <td><?= $b['menerima_signature_path'] ? '<i class="bi bi-check-circle-fill text-success"></i> '.clean($b['menerima_nama']) : '<span class="text-muted">Belum TTD</span>' ?></td>
                        <td><small class="text-muted"><?= date('d/m/y H:i', strtotime($b['created_at'])) ?></small></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-copy="<?= APP_URL ?>/berita_acara?code=<?= $b['code'] ?>">
                                    <i class="bi bi-link-45deg"></i> Salin
                                </button>
                                <a href="<?= APP_URL ?>/berita_acara?code=<?= $b['code'] ?>" class="btn btn-sm btn-primary">
                                    <i class="bi bi-pencil"></i> Buka
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyTicketLink() {
    const text = document.getElementById('ticketLink').textContent;
    navigator.clipboard.writeText(text).then(() => alert('Tautan disalin!'));
}
document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(btn.dataset.copy).then(function () {
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i> Tersalin';
            setTimeout(function () { btn.innerHTML = original; }, 1500);
        });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>