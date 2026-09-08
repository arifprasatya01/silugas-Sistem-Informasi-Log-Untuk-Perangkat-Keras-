<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Dashboard');
$db = getDB();

// Stats
$total_assets      = $db->query("SELECT COUNT(*) FROM assets WHERE is_registered=1")->fetchColumn();
$total_unregistered= $db->query("SELECT COUNT(*) FROM assets WHERE is_registered=0")->fetchColumn();
$perlu_perhatian   = $db->query("SELECT COUNT(*) FROM assets WHERE condition_status='perlu_perhatian'")->fetchColumn();
$rusak             = $db->query("SELECT COUNT(*) FROM assets WHERE condition_status='rusak'")->fetchColumn();

// Recent maintenance
$recent = $db->query("
    SELECT ml.*, a.asset_code, a.name as asset_name, u.name as user_name
    FROM maintenance_logs ml
    JOIN assets a ON a.id = ml.asset_id
    JOIN users u ON u.id = ml.performed_by
    ORDER BY ml.performed_at DESC LIMIT 8
")->fetchAll();

// Assets by type
$by_type = $db->query("
    SELECT type, COUNT(*) as total FROM assets WHERE is_registered=1 GROUP BY type ORDER BY total DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-speedometer2 me-2 text-primary"></i>Dashboard</h4>
    <small class="text-muted"><?= date('d M Y, H:i') ?></small>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-blue">
            <div class="stat-icon"><i class="bi bi-hdd-stack"></i></div>
            <div>
                <div class="stat-value"><?= $total_assets ?></div>
                <div class="stat-label">Total Aset Terdaftar</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-teal">
            <div class="stat-icon"><i class="bi bi-qr-code"></i></div>
            <div>
                <div class="stat-value"><?= $total_unregistered ?></div>
                <div class="stat-label">QR Belum Diisi</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-orange">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div>
                <div class="stat-value"><?= $perlu_perhatian ?></div>
                <div class="stat-label">Perlu Perhatian</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card bg-stat-red">
            <div class="stat-icon"><i class="bi bi-tools"></i></div>
            <div>
                <div class="stat-value"><?= $rusak ?></div>
                <div class="stat-label">Rusak</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent Maintenance -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-clock-history me-2"></i>Aktivitas Maintenance Terbaru
            </div>
            <div class="card-body p-0">
                <?php if ($recent): ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Aset</th>
                                <th>Kegiatan</th>
                                <th>Oleh</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td>
                                    <a href="<?= APP_URL ?>/asset_detail?code=<?= urlencode($r['asset_code']) ?>" class="fw-semibold text-decoration-none">
                                        <?= clean($r['asset_code']) ?>
                                    </a>
                                    <?php if ($r['asset_name']): ?>
                                    <br><small class="text-muted"><?= clean($r['asset_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= clean($r['action']) ?></td>
                                <td><?= clean($r['user_name']) ?></td>
                                <td><small class="text-muted"><?= date('d/m/y H:i', strtotime($r['performed_at'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1"></i>
                    <p class="mt-2">Belum ada aktivitas maintenance</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Aset by Type -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <i class="bi bi-pie-chart me-2"></i>Aset per Tipe
            </div>
            <div class="card-body">
                <?php
                $type_labels = [
                    'komputer'=>'Komputer','laptop'=>'Laptop','printer'=>'Printer',
                    'scanner'=>'Scanner','server'=>'Server','network'=>'Network','lainnya'=>'Lainnya'
                ];
                $type_colors = [
                    'komputer'=>'bg-stat-blue','laptop'=>'bg-stat-teal','printer'=>'bg-stat-orange',
                    'scanner'=>'bg-stat-red','server'=>'bg-primary','network'=>'bg-success','lainnya'=>'bg-secondary'
                ];
                if ($by_type): foreach ($by_type as $t): ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?= $type_colors[$t['type']] ?? 'bg-secondary' ?> px-2 py-1"><?= clean($type_labels[$t['type']] ?? $t['type']) ?></span>
                    </div>
                    <strong><?= $t['total'] ?></strong>
                </div>
                <?php endforeach; else: ?>
                <div class="text-center py-4 text-muted"><i class="bi bi-inbox"></i><p>Belum ada data</p></div>
                <?php endif; ?>
                <hr>
                <div class="d-grid gap-2">
                    <a href="<?= APP_URL ?>/asset_list" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-list-ul me-1"></i>Lihat Semua Aset
                    </a>
                    <a href="<?= APP_URL ?>/scan" class="btn btn-scan">
                        <i class="bi bi-qr-code-scan me-2"></i>Scan QR
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
