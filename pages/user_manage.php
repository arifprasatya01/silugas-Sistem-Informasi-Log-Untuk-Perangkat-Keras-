<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
define('PAGE_TITLE', 'Kelola User');
$db = getDB();

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name     = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'] ?? '', ['admin','member']) ? $_POST['role'] : 'member';

        if (!$name || !$username || !$password) {
            $msg = 'Nama, username, dan password wajib diisi.'; $msgType = 'danger';
        } elseif (strlen($password) < 6) {
            $msg = 'Password minimal 6 karakter.'; $msgType = 'danger';
        } else {
            $check = $db->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetch()) {
                $msg = 'Username sudah digunakan.'; $msgType = 'danger';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);
                $db->prepare("INSERT INTO users (name, username, password, role, kd_unit) VALUES (?,?,?,?,?)")
                   ->execute([$name, $username, $hash, $role, '1']);
                $msg = 'User berhasil ditambahkan.';
            }
        }
    }

    elseif ($action === 'force_logout') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id == currentUser()['id']) {
            $msg = 'Tidak bisa force logout diri sendiri.'; $msgType = 'danger';
        } else {
            $db->prepare("UPDATE users SET is_logged_in = 0, device_token = NULL WHERE id = ?")
               ->execute([$id]);
            $msg = 'User berhasil di-logout dari perangkatnya.';
        }
    }

    elseif ($action === 'reset_password') {
        $id  = cleanInt($_POST['id'] ?? 0);
        $pwd = $_POST['new_password'] ?? '';
        if (strlen($pwd) < 6) {
            $msg = 'Password minimal 6 karakter.'; $msgType = 'danger';
        } else {
            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("UPDATE users SET password = ?, is_logged_in = 0, device_token = NULL WHERE id = ?")
               ->execute([$hash, $id]);
            $msg = 'Password berhasil direset. User perlu login ulang.';
        }
    }

    elseif ($action === 'delete_user') {
        $id = cleanInt($_POST['id'] ?? 0);
        if ($id == currentUser()['id']) {
            $msg = 'Tidak bisa hapus akun sendiri.'; $msgType = 'danger';
        } else {
            $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $msg = 'User berhasil dihapus.';
        }
    }
}

$users = $db->query("SELECT id, name, username, role, is_logged_in, created_at FROM users ORDER BY role DESC, name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-people me-2 text-primary"></i>Kelola User</h4>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <i class="bi bi-<?= $msgType==='success'?'check-circle':'exclamation-triangle' ?> me-2"></i><?= clean($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-person-plus me-2"></i>Tambah User</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_user">
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-sm" required maxlength="100">
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control form-control-sm" required maxlength="50"
                               pattern="[a-zA-Z0-9_]+" title="Huruf, angka, dan underscore saja">
                    </div>
                    <div class="mb-2">
                        <label class="form-label form-label-sm">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control form-control-sm" required minlength="6">
                        <small class="text-muted">Minimal 6 karakter</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="member">Member</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus me-1"></i>Tambah User</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-light fw-semibold"><i class="bi bi-people me-2"></i>Daftar User</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th>Aksi</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= clean($u['name']) ?></td>
                            <td><code><?= clean($u['username']) ?></code></td>
                            <td><span class="badge bg-<?= $u['role']==='admin'?'warning text-dark':'secondary' ?>"><?= $u['role'] ?></span></td>
                            <td>
                                <?php if ($u['is_logged_in']): ?>
                                <span class="badge bg-success"><i class="bi bi-circle-fill me-1" style="font-size:.5rem"></i>Online</span>
                                <?php else: ?>
                                <span class="badge bg-light text-muted border">Offline</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($u['id'] != currentUser()['id']): ?>
                                    <!-- Force Logout -->
                                    <?php if ($u['is_logged_in']): ?>
                                    <form method="POST" onsubmit="return confirmDelete('Force logout <?= clean($u['name']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="force_logout">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning" title="Force Logout">
                                            <i class="bi bi-box-arrow-right"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Reset Password -->
                                    <button class="btn btn-sm btn-outline-secondary" onclick="showReset(<?= $u['id'] ?>, '<?= clean($u['name']) ?>')" title="Reset Password">
                                        <i class="bi bi-key"></i>
                                    </button>

                                    <!-- Delete -->
                                    <form method="POST" onsubmit="return confirmDelete('Hapus user <?= clean($u['name']) ?>?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-muted small fst-italic">Akun kamu</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h6 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" id="reset_user_id">
                <div class="modal-body">
                    <p class="mb-2">Reset password untuk: <strong id="reset_user_name"></strong></p>
                    <input type="password" name="new_password" class="form-control" placeholder="Password baru (min. 6 karakter)" required minlength="6">
                    <small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>User akan otomatis di-logout</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showReset(id, name) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_user_name').textContent = name;
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
