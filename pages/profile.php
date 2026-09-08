<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Profil Saya');
$db = getDB();

$user = currentUser();
$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $old_pwd = $_POST['old_password'] ?? '';
    $new_pwd = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$old_pwd || !$new_pwd || !$confirm) {
        $msg = 'Semua field wajib diisi.'; $msgType = 'danger';
    } elseif ($new_pwd !== $confirm) {
        $msg = 'Password baru dan konfirmasi tidak cocok.'; $msgType = 'danger';
    } elseif (strlen($new_pwd) < 6) {
        $msg = 'Password baru minimal 6 karakter.'; $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();
        if (!password_verify($old_pwd, $row['password'])) {
            $msg = 'Password lama tidak benar.'; $msgType = 'danger';
        } else {
            $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("UPDATE users SET password = ? WHERE id = ?")
               ->execute([$hash, $user['id']]);
            $msg = 'Password berhasil diubah!';
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-person-circle me-2"></i>Profil Saya</div>
            <div class="card-body">
                <div class="mb-4">
                    <table class="table table-sm">
                        <tr><td class="text-muted">Nama</td><td><strong><?= clean($user['name']) ?></strong></td></tr>
                        <tr><td class="text-muted">Username</td><td><code><?= clean($user['username']) ?></code></td></tr>
                        <tr><td class="text-muted">Role</td><td><span class="badge bg-<?= $user['role']==='admin'?'warning text-dark':'secondary' ?>"><?= $user['role'] ?></span></td></tr>
                    </table>
                </div>
                <hr>
                <h6 class="fw-bold mb-3"><i class="bi bi-key me-2"></i>Ganti Password</h6>
                <?php if ($msg): ?>
                <div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
                    <i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i><?= clean($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <div class="mb-3"><label class="form-label">Password Lama</label><input type="password" name="old_password" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Password Baru</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
                    <div class="mb-3"><label class="form-label">Konfirmasi Password Baru</label><input type="password" name="confirm_password" class="form-control" required minlength="6"></div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle me-2"></i>Simpan Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
