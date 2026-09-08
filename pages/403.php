<?php
require_once __DIR__ . '/../includes/auth.php';
define('PAGE_TITLE', 'Akses Ditolak');
include __DIR__ . '/../includes/header.php';
?>
<div class="text-center py-5">
    <i class="bi bi-shield-x text-danger" style="font-size:4rem"></i>
    <h3 class="mt-3">Akses Ditolak</h3>
    <p class="text-muted">Kamu tidak memiliki izin untuk mengakses halaman ini.</p>
    <a href="<?= APP_URL ?>/dashboard" class="btn btn-primary"><i class="bi bi-house me-2"></i>Kembali ke Dashboard</a>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
