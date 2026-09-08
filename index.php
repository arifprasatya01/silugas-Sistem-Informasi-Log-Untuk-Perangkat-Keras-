<?php
require_once __DIR__ . '/includes/auth.php';

// Kalau sudah login, redirect dashboard
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}
// Cek device token
if (!empty($_COOKIE['device_token'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE device_token = ? AND is_logged_in = 1 LIMIT 1");
    $stmt->execute([$_COOKIE['device_token']]);
    if ($stmt->fetch()) {
        header('Location: ' . APP_URL . '/dashboard');
        exit;
    }
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = 'Username dan password wajib diisi.';
    } else {
        // Brute force check
        $check = checkLoginAttempts($username);
        if ($check !== true) {
            $error = $check;
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, name, username, password, role, is_logged_in FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Cek apakah akun sedang login di device lain
                if ($user['is_logged_in'] == 1) {
                    $error = 'Akun ini sedang aktif di perangkat lain. Hubungi admin untuk force logout.';
                } else {
                    clearLoginAttempts($user['id']);

                    // Generate device token
                    $token = bin2hex(random_bytes(32));

                    // Update DB
                    $db->prepare("UPDATE users SET is_logged_in = 1, device_token = ? WHERE id = ?")
                       ->execute([$token, $user['id']]);

                    // Session
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['user_role'] = $user['role'];

                    // Cookie permanen 1 tahun — supaya tetap login walau app/browser ditutup total
                    $cookieOptions = [
                        'expires'  => time() + (365 * 24 * 60 * 60), // 1 tahun
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ];
                    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                        $cookieOptions['secure'] = true;
                    }
                    setcookie('device_token', $token, $cookieOptions);

                    $redirect = $_GET['redirect'] ?? APP_URL . '/dashboard';
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                recordFailedLogin($username);
                $error = 'Username atau password salah.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a237e">
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icons/icon-192.png">
    <title>Login - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
    <style>
        body { background: linear-gradient(135deg, #1a237e 0%, #00acc1 100%); min-height: 100vh; display:flex; align-items:center; }
        .login-card { border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,.3); max-width: 400px; width: 100%; }
        .login-logo { width: 64px; height: 64px; background: #1a237e; border-radius: 16px; display:flex; align-items:center; justify-content:center; margin: 0 auto 1rem; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-11 col-sm-8 col-md-6 col-lg-5">
            <div class="card login-card p-4 p-md-5">
                <div class="text-center mb-4">
                    <div class="login-logo">
                        <i class="bi bi-cpu-fill text-white fs-3"></i>
                    </div>
                    <h4 class="fw-bold text-primary mb-0"><?= APP_NAME ?></h4>
                    <small class="text-muted">Masuk ke sistem monitoring</small>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= clean($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" name="username" class="form-control form-control-lg"
                                   value="<?= clean($_POST['username'] ?? '') ?>"
                                   placeholder="Masukkan username" required autofocus>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" name="password" id="passwordInput" class="form-control form-control-lg"
                                   placeholder="Masukkan password" required>
                            <button class="btn btn-outline-secondary" type="button"
                                    onclick="togglePwd()"><i class="bi bi-eye" id="eyeIcon"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-semibold">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Masuk
                    </button>
                </form>
                <p class="text-center text-muted mt-3 mb-0" style="font-size:.8rem;">
                    Hardware Monitoring System v<?= APP_VERSION ?>
                </p>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd() {
    const inp = document.getElementById('passwordInput');
    const icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= APP_URL ?>/sw.js');
}
</script>
</body>
</html>