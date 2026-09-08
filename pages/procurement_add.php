<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
requireLogin();
if (currentUser()['role'] !== 'admin') { http_response_code(403); die('Akses ditolak.'); }

$db     = getDB();
$edit   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$errors = [];
$success = '';

// Ambil data existing kalau edit
$order = null;
$items = [];
if ($edit) {
    $stmt = $db->prepare("SELECT * FROM procurement_orders WHERE id = ?");
    $stmt->execute([$edit]);
    $order = $stmt->fetch();
    if (!$order) { header('Location: ' . APP_URL . '/procurement_list'); exit; }

    $stmt2 = $db->prepare("SELECT * FROM procurement_items WHERE procurement_id = ? ORDER BY id");
    $stmt2->execute([$edit]);
    $items = $stmt2->fetchAll();
}

define('PAGE_TITLE', $edit ? 'Edit Pengadaan' : 'Tambah Pengadaan');

// === HANDLE POST ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $nomor          = trim($_POST['nomor_pengadaan'] ?? '');
    $tanggal        = $_POST['tanggal_pengadaan'] ?? '';
    $vendor         = trim($_POST['vendor'] ?? '');
    $no_surat       = trim($_POST['no_surat'] ?? '');
    $sumber_dana    = $_POST['sumber_dana'] ?? 'blud';
    $status         = $_POST['status'] ?? 'draft';
    $catatan        = trim($_POST['catatan'] ?? '');

    // Validasi header
    if (!$nomor)   $errors[] = 'Nomor pengadaan wajib diisi.';
    if (!$tanggal) $errors[] = 'Tanggal pengadaan wajib diisi.';
    if (!$vendor)  $errors[] = 'Vendor wajib diisi.';

    // Items dari POST
    $item_nama       = $_POST['item_nama'] ?? [];
    $item_tipe       = $_POST['item_tipe'] ?? [];
    $item_merk       = $_POST['item_merk'] ?? [];
    $item_spek       = $_POST['item_spek'] ?? [];
    $item_jumlah     = $_POST['item_jumlah'] ?? [];
    $item_satuan     = $_POST['item_satuan'] ?? [];

    // Filter item kosong
    $valid_items = [];
    foreach ($item_nama as $i => $nm) {
        $nm = trim($nm);
        if (!$nm) continue;
        $jml = max(1, (int)($item_jumlah[$i] ?? 1));
        $sat = max(0, (float)str_replace(['.', ','], ['', '.'], $item_satuan[$i] ?? 0));
        $valid_items[] = [
            'nama'       => $nm,
            'tipe'       => $item_tipe[$i] ?? 'lainnya',
            'merk'       => trim($item_merk[$i] ?? ''),
            'spesifikasi'=> trim($item_spek[$i] ?? ''),
            'jumlah'     => $jml,
            'harga_satuan'=> $sat,
        ];
    }
    if (empty($valid_items)) $errors[] = 'Minimal harus ada 1 item pengadaan.';

    // Cek duplikat nomor (kecuali diri sendiri saat edit)
    if ($nomor && empty($errors)) {
        $chk = $db->prepare("SELECT id FROM procurement_orders WHERE nomor_pengadaan = ? AND id != ?");
        $chk->execute([$nomor, $edit]);
        if ($chk->fetch()) $errors[] = "Nomor pengadaan '$nomor' sudah digunakan.";
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            if ($edit) {
                // Update header
                $db->prepare("
                    UPDATE procurement_orders SET
                        nomor_pengadaan=?, tanggal_pengadaan=?, vendor=?, no_surat=?,
                        sumber_dana=?, status=?, catatan=?
                    WHERE id=?
                ")->execute([$nomor, $tanggal, $vendor, $no_surat ?: null, $sumber_dana, $status, $catatan ?: null, $edit]);

                // Hapus items lama, insert baru
                $db->prepare("DELETE FROM procurement_items WHERE procurement_id=?")->execute([$edit]);
                $procurement_id = $edit;
            } else {
                // Insert header
                $db->prepare("
                    INSERT INTO procurement_orders
                        (nomor_pengadaan, tanggal_pengadaan, vendor, no_surat, sumber_dana, status, catatan, created_by)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$nomor, $tanggal, $vendor, $no_surat ?: null, $sumber_dana, $status, $catatan ?: null, currentUser()['id']]);
                $procurement_id = $db->lastInsertId();
            }

            // Insert items
            $stmt_item = $db->prepare("
                INSERT INTO procurement_items (procurement_id, nama_barang, tipe, merk, spesifikasi, jumlah, harga_satuan)
                VALUES (?,?,?,?,?,?,?)
            ");
            foreach ($valid_items as $vi) {
                $stmt_item->execute([
                    $procurement_id,
                    $vi['nama'],
                    $vi['tipe'],
                    $vi['merk'] ?: null,
                    $vi['spesifikasi'] ?: null,
                    $vi['jumlah'],
                    $vi['harga_satuan'],
                ]);
            }

            $db->commit();
            header('Location: ' . APP_URL . '/procurement_detail?id=' . $procurement_id . '&saved=1');
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
        }
    }
}

// Generate nomor otomatis
$auto_nomor = '';
if (!$edit) {
    $last = $db->query("SELECT nomor_pengadaan FROM procurement_orders WHERE YEAR(tanggal_pengadaan)=YEAR(NOW()) ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($last && preg_match('/(\d+)$/', $last, $m)) {
        $auto_nomor = 'PBL-' . date('Y') . '-' . str_pad((int)$m[1] + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $auto_nomor = 'PBL-' . date('Y') . '-001';
    }
}

$tipe_options = ['komputer','laptop','printer','scanner','server','network','lainnya'];
$sumber_dana_options = ['blud'=>'BLUD','apbd'=>'APBD','apbn'=>'APBN','bos'=>'BOS','lainnya'=>'Lainnya'];
$status_options = ['draft'=>'Draft','disetujui'=>'Disetujui','diterima'=>'Diterima','selesai'=>'Selesai'];

// Populate form values (dari POST atau dari DB saat edit)
$v = [
    'nomor'       => $_POST['nomor_pengadaan'] ?? ($order['nomor_pengadaan'] ?? $auto_nomor),
    'tanggal'     => $_POST['tanggal_pengadaan'] ?? ($order['tanggal_pengadaan'] ?? date('Y-m-d')),
    'vendor'      => $_POST['vendor'] ?? ($order['vendor'] ?? ''),
    'no_surat'    => $_POST['no_surat'] ?? ($order['no_surat'] ?? ''),
    'sumber_dana' => $_POST['sumber_dana'] ?? ($order['sumber_dana'] ?? 'blud'),
    'status'      => $_POST['status'] ?? ($order['status'] ?? 'draft'),
    'catatan'     => $_POST['catatan'] ?? ($order['catatan'] ?? ''),
];

// Rebuild items dari POST (saat error validasi) atau dari DB (edit)
$form_items = [];
if (!empty($_POST['item_nama'])) {
    foreach ($_POST['item_nama'] as $i => $nm) {
        $form_items[] = [
            'nama'       => $nm,
            'tipe'       => $_POST['item_tipe'][$i] ?? 'lainnya',
            'merk'       => $_POST['item_merk'][$i] ?? '',
            'spesifikasi'=> $_POST['item_spek'][$i] ?? '',
            'jumlah'     => $_POST['item_jumlah'][$i] ?? 1,
            'harga_satuan'=> $_POST['item_satuan'][$i] ?? 0,
        ];
    }
} elseif ($items) {
    foreach ($items as $it) {
        $form_items[] = [
            'nama'        => $it['nama_barang'],
            'tipe'        => $it['tipe'],
            'merk'        => $it['merk'],
            'spesifikasi' => $it['spesifikasi'],
            'jumlah'      => $it['jumlah'],
            'harga_satuan'=> $it['harga_satuan'],
        ];
    }
}
if (empty($form_items)) {
    $form_items = [['nama'=>'','tipe'=>'lainnya','merk'=>'','spesifikasi'=>'','jumlah'=>1,'harga_satuan'=>0]];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="fw-bold mb-0">
        <i class="bi bi-<?= $edit ? 'pencil' : 'plus-circle' ?> me-2 text-primary"></i>
        <?= $edit ? 'Edit Pengadaan' : 'Tambah Pengadaan' ?>
    </h4>
    <a href="<?= APP_URL ?>/procurement_list" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="post" id="formPengadaan">
    <?= csrf_field() ?>

    <!-- Header Pengadaan -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white fw-semibold">
            <i class="bi bi-info-circle me-1"></i>Informasi Pengadaan
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nomor Pengadaan <span class="text-danger">*</span></label>
                    <input type="text" name="nomor_pengadaan" class="form-control" value="<?= clean($v['nomor']) ?>" required placeholder="PBL-2026-001">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_pengadaan" class="form-control" value="<?= clean($v['tanggal']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sumber Dana</label>
                    <select name="sumber_dana" class="form-select">
                        <?php foreach ($sumber_dana_options as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $v['sumber_dana'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Vendor / Supplier <span class="text-danger">*</span></label>
                    <input type="text" name="vendor" class="form-control" value="<?= clean($v['vendor']) ?>" required placeholder="Nama perusahaan vendor">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nomor Surat / PO</label>
                    <input type="text" name="no_surat" class="form-control" value="<?= clean($v['no_surat']) ?>" placeholder="SPK/IT/2026/001">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($status_options as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Catatan</label>
                    <textarea name="catatan" class="form-control" rows="2" placeholder="Catatan tambahan (opsional)"><?= clean($v['catatan']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Items -->
    <div class="card mb-3">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-1"></i>Rincian Barang</span>
            <button type="button" class="btn btn-sm btn-light" id="btnTambahItem">
                <i class="bi bi-plus-lg me-1"></i>Tambah Baris
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered mb-0" id="tblItems">
                    <thead class="table-light">
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>Nama Barang <span class="text-danger">*</span></th>
                            <th style="width:120px;">Tipe</th>
                            <th style="width:120px;">Merk</th>
                            <th>Spesifikasi</th>
                            <th style="width:80px;">Qty</th>
                            <th style="width:140px;">Harga Satuan</th>
                            <th style="width:140px;">Subtotal</th>
                            <th style="width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                    <?php foreach ($form_items as $idx => $fi): ?>
                        <tr class="item-row">
                            <td class="text-center row-num"><?= $idx + 1 ?></td>
                            <td><input type="text" name="item_nama[]" class="form-control form-control-sm" value="<?= clean($fi['nama']) ?>" placeholder="Nama barang" required></td>
                            <td>
                                <select name="item_tipe[]" class="form-select form-select-sm">
                                    <?php foreach ($tipe_options as $t): ?>
                                    <option value="<?= $t ?>" <?= ($fi['tipe'] ?? '') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="item_merk[]" class="form-control form-control-sm" value="<?= clean($fi['merk'] ?? '') ?>" placeholder="Merk"></td>
                            <td><input type="text" name="item_spek[]" class="form-control form-control-sm" value="<?= clean($fi['spesifikasi'] ?? '') ?>" placeholder="Spesifikasi singkat"></td>
                            <td><input type="number" name="item_jumlah[]" class="form-control form-control-sm qty" value="<?= (int)($fi['jumlah'] ?? 1) ?>" min="1" required></td>
                            <td><input type="text" name="item_satuan[]" class="form-control form-control-sm satuan" value="<?= number_format((float)($fi['harga_satuan'] ?? 0), 0, ',', '.') ?>" placeholder="0" required></td>
                            <td class="text-end fw-semibold subtotal">Rp 0</td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger btn-hapus-item" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td colspan="7" class="text-end">Total</td>
                            <td class="text-end" id="grandTotal">Rp 0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end mb-4">
        <a href="<?= APP_URL ?>/procurement_list" class="btn btn-outline-secondary">Batal</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i><?= $edit ? 'Simpan Perubahan' : 'Simpan Pengadaan' ?>
        </button>
    </div>
</form>

<template id="tmplRow">
    <tr class="item-row">
        <td class="text-center row-num">-</td>
        <td><input type="text" name="item_nama[]" class="form-control form-control-sm" placeholder="Nama barang" required></td>
        <td>
            <select name="item_tipe[]" class="form-select form-select-sm">
                <?php foreach ($tipe_options as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="item_merk[]" class="form-control form-control-sm" placeholder="Merk"></td>
        <td><input type="text" name="item_spek[]" class="form-control form-control-sm" placeholder="Spesifikasi singkat"></td>
        <td><input type="number" name="item_jumlah[]" class="form-control form-control-sm qty" value="1" min="1" required></td>
        <td><input type="text" name="item_satuan[]" class="form-control form-control-sm satuan" placeholder="0" required></td>
        <td class="text-end fw-semibold subtotal">Rp 0</td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger btn-hapus-item" title="Hapus baris">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</template>

<script>
// Parse angka dengan format Rupiah (titik sebagai ribuan)
function parseRp(str) {
    return parseFloat(String(str).replace(/\./g, '').replace(',', '.')) || 0;
}
function formatRp(num) {
    return num.toLocaleString('id-ID', { minimumFractionDigits: 0 });
}

function hitungSubtotal(row) {
    const qty = parseInt(row.querySelector('.qty').value) || 0;
    const sat = parseRp(row.querySelector('.satuan').value);
    const sub = qty * sat;
    row.querySelector('.subtotal').textContent = 'Rp ' + formatRp(sub);
    return sub;
}

function hitungGrandTotal() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(r => { total += hitungSubtotal(r); });
    document.getElementById('grandTotal').textContent = 'Rp ' + formatRp(total);
}

function renumberRows() {
    document.querySelectorAll('.row-num').forEach((el, i) => { el.textContent = i + 1; });
}

// Format input harga saat blur
document.addEventListener('blur', function(e) {
    if (e.target.classList.contains('satuan')) {
        const num = parseRp(e.target.value);
        e.target.value = formatRp(num);
        hitungGrandTotal();
    }
}, true);

document.addEventListener('input', function(e) {
    if (e.target.classList.contains('qty') || e.target.classList.contains('satuan')) {
        hitungGrandTotal();
    }
});

document.getElementById('btnTambahItem').addEventListener('click', function() {
    const tmpl = document.getElementById('tmplRow');
    const clone = tmpl.content.cloneNode(true);
    document.getElementById('itemsBody').appendChild(clone);
    renumberRows();
    hitungGrandTotal();
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.btn-hapus-item')) {
        const rows = document.querySelectorAll('.item-row');
        if (rows.length <= 1) { alert('Minimal harus ada 1 item.'); return; }
        e.target.closest('tr').remove();
        renumberRows();
        hitungGrandTotal();
    }
});

// Hitung saat load
document.querySelectorAll('.satuan').forEach(el => {
    const num = parseRp(el.value);
    if (num > 0) el.value = formatRp(num);
});
hitungGrandTotal();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
