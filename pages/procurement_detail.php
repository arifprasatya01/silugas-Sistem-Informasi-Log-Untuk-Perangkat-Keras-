<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . APP_URL . '/procurement_list'); exit; }

$stmt = $db->prepare("
    SELECT po.*, u.name AS created_by_name
    FROM procurement_orders po
    JOIN users u ON u.id = po.created_by
    WHERE po.id = ?
");
$stmt->execute([$id]);
$order = $stmt->fetch();
if (!$order) { header('Location: ' . APP_URL . '/procurement_list'); exit; }

$items = $db->prepare("SELECT * FROM procurement_items WHERE procurement_id = ? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

define('PAGE_TITLE', 'Detail Pengadaan - ' . $order['nomor_pengadaan']);

$status_labels = [
    'draft'     => ['label' => 'Draft',     'class' => 'secondary'],
    'disetujui' => ['label' => 'Disetujui', 'class' => 'primary'],
    'diterima'  => ['label' => 'Diterima',  'class' => 'info'],
    'selesai'   => ['label' => 'Selesai',   'class' => 'success'],
];
$sumber_labels = ['blud'=>'BLUD','apbd'=>'APBD','apbn'=>'APBN','bos'=>'BOS','lainnya'=>'Lainnya'];

// Handle update status cepat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_status'])) {
    csrf_verify();
    requireAdmin();
    $new_status = $_POST['quick_status'];
    $allowed    = array_keys($status_labels);
    if (in_array($new_status, $allowed)) {
        $db->prepare("UPDATE procurement_orders SET status=? WHERE id=?")->execute([$new_status, $id]);
        $order['status'] = $new_status;
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    csrf_verify();
    requireAdmin();
    $db->prepare("DELETE FROM procurement_orders WHERE id=?")->execute([$id]);
    header('Location: ' . APP_URL . '/procurement_list?deleted=1');
    exit;
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>Data pengadaan berhasil disimpan.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0"><i class="bi bi-receipt me-2 text-primary"></i><?= clean($order['nomor_pengadaan']) ?></h4>
        <small class="text-muted"><?= clean($order['vendor']) ?> &bull; <?= date('d F Y', strtotime($order['tanggal_pengadaan'])) ?></small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= APP_URL ?>/procurement_print?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer me-1"></i>Cetak
        </a>
        <?php if (isAdmin()): ?>
        <a href="<?= APP_URL ?>/procurement_add?edit=<?= $id ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalHapus">
            <i class="bi bi-trash me-1"></i>Hapus
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/procurement_list" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- Info Header -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header bg-primary text-white"><i class="bi bi-info-circle me-1"></i>Informasi Pengadaan</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6 col-sm-4"><div class="text-muted small">Nomor Surat/PO</div><div><?= clean($order['no_surat']) ?: '-' ?></div></div>
                    <div class="col-6 col-sm-4"><div class="text-muted small">Sumber Dana</div><div><span class="badge bg-secondary"><?= $sumber_labels[$order['sumber_dana']] ?? $order['sumber_dana'] ?></span></div></div>
                    <div class="col-6 col-sm-4"><div class="text-muted small">Status</div>
                        <?php $s = $status_labels[$order['status']]; ?>
                        <span class="badge bg-<?= $s['class'] ?>"><?= $s['label'] ?></span>
                    </div>
                    <div class="col-6 col-sm-4"><div class="text-muted small">Total Nilai</div><div class="fw-bold text-primary">Rp <?= number_format($order['total_harga'], 0, ',', '.') ?></div></div>
                    <div class="col-6 col-sm-4"><div class="text-muted small">Dibuat Oleh</div><div><?= clean($order['created_by_name']) ?></div></div>
                    <div class="col-6 col-sm-4"><div class="text-muted small">Tanggal Input</div><div><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></div></div>
                    <?php if ($order['catatan']): ?>
                    <div class="col-12"><div class="text-muted small">Catatan</div><div><?= nl2br(clean($order['catatan'])) ?></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Status -->
    <?php if (isAdmin()): ?>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white"><i class="bi bi-arrow-repeat me-1"></i>Update Status</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <?php foreach ($status_labels as $k => $sv): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="quick_status" value="<?= $k ?>"
                               id="st_<?= $k ?>" <?= $order['status'] === $k ? 'checked' : '' ?>>
                        <label class="form-check-label" for="st_<?= $k ?>">
                            <span class="badge bg-<?= $sv['class'] ?>"><?= $sv['label'] ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-sm btn-primary mt-2 w-100">
                        <i class="bi bi-save me-1"></i>Simpan Status
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Tabel Items -->
<div class="card mb-3">
    <div class="card-header bg-primary text-white"><i class="bi bi-list-ul me-1"></i>Rincian Barang (<?= count($items) ?> item)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Nama Barang</th>
                        <th>Tipe</th>
                        <th>Merk</th>
                        <th>Spesifikasi</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Harga Satuan</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                <?php $no = 1; foreach ($items as $it): ?>
                <tr>
                    <td><?= $no++ ?></td>
                    <td class="fw-semibold"><?= clean($it['nama_barang']) ?></td>
                    <td><span class="badge bg-secondary"><?= ucfirst($it['tipe']) ?></span></td>
                    <td><?= clean($it['merk']) ?: '-' ?></td>
                    <td><small><?= clean($it['spesifikasi']) ?: '-' ?></small></td>
                    <td class="text-center"><?= $it['jumlah'] ?></td>
                    <td class="text-end">Rp <?= number_format($it['harga_satuan'], 0, ',', '.') ?></td>
                    <td class="text-end fw-bold">Rp <?= number_format($it['total_harga'], 0, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="7" class="text-end fw-bold">Total Nilai Pengadaan</td>
                        <td class="text-end fw-bold text-primary">Rp <?= number_format($order['total_harga'], 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Modal Hapus -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="modalHapus" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Konfirmasi Hapus</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Yakin ingin menghapus pengadaan <strong><?= clean($order['nomor_pengadaan']) ?></strong>?
                <br><small class="text-muted">Semua item rincian juga akan ikut terhapus.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="delete_order" value="1">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Ya, Hapus</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
