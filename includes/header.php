<?php
// includes/header.php
if (!defined('PAGE_TITLE')) define('PAGE_TITLE', APP_NAME);
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1a237e">
    <meta name="description" content="Hardware Asset Monitoring System">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="HW Monitor">
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icons/icon-192.png">
    <title><?= clean(PAGE_TITLE) ?> - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/app.css">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary navbar-expand-lg sticky-top no-print">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= APP_URL ?>/dashboard">
            <i class="bi bi-cpu-fill me-2"></i><?= APP_NAME ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/dashboard"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                </li>
              <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/token_generate"><i class="bi bi-shield-lock me-1"></i></i>Generate Token</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/asset_list"><i class="bi bi-hdd-stack me-1"></i>Aset</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/scan"><i class="bi bi-qr-code-scan me-1"></i>Scan QR</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/procurement_list">
                        <i class="bi bi-cart-check me-1"></i>Pengadaan
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/report"><i class="bi bi-file-earmark-bar-graph me-1"></i>Laporan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= APP_URL ?>/logbook"><i class="bi bi-journal-text me-1"></i>Logbook Hardware</a>
                </li>
                <?php if ($user['role'] === 'admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-gear me-1"></i>Master</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/location_manage"><i class="bi bi-building me-2"></i>Gedung & Ruangan</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/qr_bulk"><i class="bi bi-qr-code me-2"></i>Generate QR Bulk</a></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/print_by_location"><i class="bi bi-printer me-2"></i>Print Label per Lokasi</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/user_manage"><i class="bi bi-people me-2"></i>Kelola User</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= clean($user['name']) ?>
                        <span class="badge bg-<?= $user['role']==='admin'?'warning':'secondary' ?> ms-1"><?= $user['role'] ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/profile"><i class="bi bi-person me-2"></i>Profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid py-3 px-3 px-md-4">