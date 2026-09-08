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

$sumber_labels = ['blud'=>'BLUD','apbd'=>'APBD','apbn'=>'APBN','bos'=>'BOS','lainnya'=>'Lainnya'];
$status_labels = ['draft'=>'Draft','disetujui'=>'Disetujui','diterima'=>'Diterima','selesai'=>'Selesai'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Pengadaan - <?= clean($order['nomor_pengadaan']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11pt; color: #000; background: #fff; }
        .container { width: 100%; max-width: 800px; margin: 0 auto; padding: 20px; }

        /* Header */
        .kop { display: flex; align-items: center; border-bottom: 3px solid #1a237e; padding-bottom: 10px; margin-bottom: 15px; gap: 15px; }
        .kop img { height: 70px; }
        .kop-text { flex: 1; text-align: center; }
        .kop-text h1 { font-size: 14pt; font-weight: bold; text-transform: uppercase; }
        .kop-text h2 { font-size: 11pt; }
        .kop-text p { font-size: 9pt; color: #555; }

        /* Judul */
        .judul { text-align: center; margin: 15px 0 10px; }
        .judul h3 { font-size: 13pt; font-weight: bold; text-decoration: underline; text-transform: uppercase; }

        /* Info box */
        .info-grid { width: 100%; margin-bottom: 15px; }
        .info-grid td { padding: 2px 5px; font-size: 10.5pt; vertical-align: top; }
        .info-grid td:first-child { width: 170px; font-weight: bold; }
        .info-grid td:nth-child(2) { width: 15px; }

        /* Tabel items */
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10pt; }
        table.items th { background: #1a237e; color: #fff; padding: 6px 8px; text-align: center; }
        table.items td { border: 1px solid #aaa; padding: 5px 8px; vertical-align: top; }
        table.items tr:nth-child(even) td { background: #f5f5f5; }
        table.items tfoot td { border-top: 2px solid #1a237e; font-weight: bold; background: #eee; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* TTD */
        .ttd-box { display: flex; justify-content: flex-end; margin-top: 30px; }
        .ttd { text-align: center; width: 220px; }
        .ttd .ttd-label { font-size: 10pt; }
        .ttd .ttd-space { height: 60px; }
        .ttd .ttd-name { font-weight: bold; border-top: 1px solid #000; padding-top: 3px; font-size: 10pt; }

        /* Print button */
        .no-print { margin-bottom: 15px; }
        @media print {
            .no-print { display: none; }
            body { font-size: 10pt; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="no-print">
        <button onclick="window.print()" style="padding:6px 16px;background:#1a237e;color:#fff;border:none;border-radius:4px;cursor:pointer;">
            🖨️ Cetak
        </button>
        <button onclick="window.close()" style="margin-left:8px;padding:6px 16px;border:1px solid #aaa;border-radius:4px;cursor:pointer;">
            ✕ Tutup
        </button>
    </div>

    <!-- KOP -->
    <div class="kop">
        <img src="<?= APP_URL ?>/assets/icons/logo-rsud.png" alt="Logo RSUD" onerror="this.style.display='none'">
        <div class="kop-text">
            <h1>RSUD Dr. Hafiz Karawang</h1>
            <h2>Dinas Kesehatan Kabupaten Karawang</h2>
            <p>Jl. Galuh Mas Raya, Karawang 41361 &bull; Telp. (0267) 402882</p>
        </div>
        <img src="<?= APP_URL ?>/assets/icons/logo-kabupaten.png" alt="Logo Kabupaten" onerror="this.style.display='none'">
    </div>

    <!-- Judul -->
    <div class="judul">
        <h3>Daftar Pengadaan Hardware</h3>
    </div>

    <!-- Info -->
    <table class="info-grid">
        <tr>
            <td>Nomor Pengadaan</td><td>:</td><td><?= clean($order['nomor_pengadaan']) ?></td>
        </tr>
        <tr>
            <td>Tanggal Pengadaan</td><td>:</td><td><?= date('d F Y', strtotime($order['tanggal_pengadaan'])) ?></td>
        </tr>
        <tr>
            <td>Vendor / Supplier</td><td>:</td><td><?= clean($order['vendor']) ?></td>
        </tr>
        <tr>
            <td>Nomor Surat / PO</td><td>:</td><td><?= clean($order['no_surat']) ?: '-' ?></td>
        </tr>
        <tr>
            <td>Sumber Dana</td><td>:</td><td><?= $sumber_labels[$order['sumber_dana']] ?? strtoupper($order['sumber_dana']) ?></td>
        </tr>
        <tr>
            <td>Status</td><td>:</td><td><?= $status_labels[$order['status']] ?? $order['status'] ?></td>
        </tr>
        <?php if ($order['catatan']): ?>
        <tr>
            <td>Catatan</td><td>:</td><td><?= nl2br(clean($order['catatan'])) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- Items -->
    <table class="items">
        <thead>
            <tr>
                <th style="width:35px;">No</th>
                <th>Nama Barang</th>
                <th style="width:80px;">Tipe</th>
                <th style="width:80px;">Merk</th>
                <th>Spesifikasi</th>
                <th style="width:40px;">Qty</th>
                <th style="width:120px;">Harga Satuan</th>
                <th style="width:120px;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
        <?php $no = 1; foreach ($items as $it): ?>
        <tr>
            <td class="text-center"><?= $no++ ?></td>
            <td><?= clean($it['nama_barang']) ?></td>
            <td class="text-center"><?= ucfirst($it['tipe']) ?></td>
            <td><?= clean($it['merk']) ?: '-' ?></td>
            <td><small><?= clean($it['spesifikasi']) ?: '-' ?></small></td>
            <td class="text-center"><?= $it['jumlah'] ?></td>
            <td class="text-right">Rp <?= number_format($it['harga_satuan'], 0, ',', '.') ?></td>
            <td class="text-right">Rp <?= number_format($it['total_harga'], 0, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7" class="text-right">Total Nilai Pengadaan</td>
                <td class="text-right">Rp <?= number_format($order['total_harga'], 0, ',', '.') ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- TTD -->
    <p style="font-size:10pt;">Karawang, <?= date('d F Y') ?></p>
    <div class="ttd-box">
        <div class="ttd">
            <div class="ttd-label">Kepala Instalasi IT</div>
            <div class="ttd-space"></div>
            <div class="ttd-name">_______________________</div>
            <div style="font-size:9pt;color:#555;">NIP. ................................</div>
        </div>
    </div>

    <p style="font-size:8pt;color:#888;margin-top:30px;text-align:center;">
        Dicetak oleh: <?= clean($order['created_by_name']) ?> &bull; <?= date('d/m/Y H:i') ?> &bull; <?= APP_NAME ?>
    </p>
</div>
</body>
</html>
