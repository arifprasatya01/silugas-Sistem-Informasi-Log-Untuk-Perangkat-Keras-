<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function tgl_indo($ymd) {
    if (!$ymd) return '-';
    $bulan = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
              '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $parts = explode('-', $ymd);
    if (count($parts) !== 3) return h($ymd);
    $y = $parts[0]; $m = $parts[1]; $d = $parts[2];
    return ((int)$d) . ' ' . (isset($bulan[$m]) ? $bulan[$m] : $m) . ' ' . $y;
}

try {
    $db   = getDB();
    $user = currentUser();

    $code = strtoupper(trim($_GET['code'] ?? ''));
    $submission = null;
    $items = [];

    if ($code !== '') {
        $stmt = $db->prepare("SELECT * FROM hardware_submissions WHERE token_code = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$code]);
        $submission = $stmt->fetch();
        if ($submission) {
            $items = json_decode($submission['items'], true);
            if (!is_array($items)) { $items = []; }
        }
    }
} catch (Throwable $ex) {
    http_response_code(500);
    echo '<!doctype html><html><body style="font-family:sans-serif;padding:40px;">';
    echo '<h3>Terjadi error saat memuat halaman cetak</h3>';
    echo '<p>' . h($ex->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Cetak Formulir &mdash; <?= h($code) ?></title>
<style>
  @page { size: A4; margin: 14mm 16mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; color:#000; margin:0; }
  .wrap { max-width: 760px; margin: 0 auto; }

  /* ── KOP SURAT ───────────────────────────────── */
  table.kop { width:100%; border-collapse:collapse; margin-bottom:0; }
  table.kop td { vertical-align:middle; padding:0 8px; }
  table.kop td.logo-left  { width:110px; text-align:left; }
  table.kop td.logo-right { width:120px; text-align:right; }
  table.kop td.logo-left img, table.kop td.logo-right img { max-width:100%; max-height:110px; }
  table.kop td.kop-text { text-align:center; line-height:1.35; }
  .kop-text .l1 { font-size:13.5px; }
  .kop-text .l2 { font-size:13.5px; }
  .kop-text .l3 { font-size:15px; font-weight:bold; }
  .kop-text .l4 { font-size:16px; font-weight:bold; }
  .kop-text .l5 { font-size:12px; margin-top:4px; }
  .kop-text .l6 { font-size:11.5px; }
  .kop-rule { border-bottom:4px double #000; margin-bottom:14px; padding-top:6px; }

  h1 { text-align:center; font-size: 13px; font-weight:bold; margin: 14px 0 10px; letter-spacing:.3px; }

  table.info { width:100%; border-collapse:collapse; margin-bottom:16px; border:1px solid #000; }
  table.info td { border:1px solid #000; padding:6px 10px; vertical-align:middle; font-size:13px; }
  table.info td.label { width:26%; }
  table.info td.label2 { width:18%; }

  table.items { width:100%; border-collapse:collapse; margin-bottom:10px; border:1px solid #000; }
  table.items th { border:1px solid #000; padding:7px 9px; font-size:12.5px; text-align:center; font-weight:bold; background:#D9D9D9; }
  table.items td { border-left:1px solid #000; border-right:1px solid #000; border-top:none; border-bottom:none; padding:4px 9px; font-size:12.5px; text-align:left; vertical-align:top; }
  table.items tbody tr:last-child td { border-bottom:1px solid #000; }
  table.items td.no { text-align:center; width:8%; }
  table.items td.empty-row { height:26px; }

  table.sign { width:100%; border-collapse:collapse; margin-top:6px; }
  table.sign td { width:50%; vertical-align:top; padding:0 10px; font-size:13px; text-align:center; }
  .sign-box { height:75px; display:flex; align-items:center; justify-content:center; padding:4px 0; }
  .sign-box img { max-height:70px; max-width:180px; object-fit:contain; }
  .sign-line { margin-top:2px; }

  .mengetahui { text-align:center; margin-top:26px; font-size:13px; line-height:1.7; }
  .mengetahui .gap { height:55px; }

  /* ── Toolbar & TTD inline (no-print) ──────────── */
  .toolbar { text-align:center; margin: 16px 0 24px; }
  .toolbar button {
    background:#1a237e; color:#fff; border:none; border-radius:6px; padding:9px 18px;
    font-size:14px; cursor:pointer; font-family: 'Segoe UI', sans-serif;
  }

  /* Panel TTD Tim IT inline (tidak ikut cetak) */
  .ttd-panel {
    border: 1px dashed #aaa;
    border-radius: 6px;
    padding: 8px 10px 6px;
    background: #f8f9ff;
    margin-top: 4px;
  }
  .ttd-panel canvas {
    display: block;
    width: 100%;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: #fff;
    touch-action: none;
    cursor: crosshair;
  }
  .ttd-panel input[type=text] {
    width: 100%;
    border: none;
    border-bottom: 1px solid #999;
    text-align: center;
    font-size: 13px;
    outline: none;
    padding: 2px 0;
    background: transparent;
    margin-top: 4px;
  }
  .ttd-panel .btn-row {
    display: flex;
    gap: 6px;
    justify-content: center;
    margin-top: 6px;
  }
  .ttd-panel .btn-row button {
    font-size: 11px;
    padding: 3px 10px;
    border-radius: 4px;
    border: 1px solid #999;
    cursor: pointer;
    background: #fff;
  }
  .ttd-panel .btn-row .btn-save {
    background: #1a237e;
    color: #fff;
    border-color: #1a237e;
  }
  .ttd-panel .label-hint {
    font-size: 11px;
    color: #666;
    text-align: center;
    margin-bottom: 4px;
  }

  @media print {
    .toolbar, .ttd-panel, .no-print { display: none !important; }
  }
</style>
</head>
<body>

<div class="toolbar no-print">
  <button onclick="window.print()">&#128424; Cetak Formulir</button>
</div>

<div class="wrap">
<?php if (!$submission): ?>
  <p style="text-align:center;">Data isian tidak ditemukan untuk token <strong><?= h($code) ?></strong>.</p>
<?php else: ?>

  <table class="kop">
    <tr>
      <td class="logo-left">
        <img src="<?= APP_URL ?>/assets/icons/logo-kabupaten.png" alt="Logo Kabupaten Karawang">
      </td>
      <td class="kop-text">
        <div class="l1">PEMERINTAH KABUPATEN KARAWANG</div>
        <div class="l2">DINAS KESEHATAN</div>
        <div class="l3">UNIT ORGANISASI BERSIFAT KHUSUS</div>
        <div class="l4">RUMAH SAKIT UMUM DAERAH KARAWANG</div>
        <div class="l5">Jalan Galuh Mas Raya No. 1 Karawang</div>
        <div class="l6">Telepon : (0267) 640444, 640555 Pos-el : rsudkrw@yahoo.co.id Kode Pos 41361</div>
      </td>
      <td class="logo-right">
        <img src="<?= APP_URL ?>/assets/icons/logo-rsud.png" alt="Logo RSUD Karawang">
      </td>
    </tr>
  </table>
  <div class="kop-rule"></div>

  <h1>FORMULIR SERAH TERIMA PERANGKAT KERAS</h1>

  <table class="info">
    <tr>
      <td class="label">Unit</td>
      <td colspan="3"><?= h($submission['unit']) ?></td>
    </tr>
    <tr>
      <td class="label">Tanggal</td>
      <td><?= tgl_indo($submission['tanggal']) ?></td>
      <td class="label2">Bagian</td>
      <td><?= h($submission['bagian']) ?></td>
    </tr>
  </table>

  <table class="items">
    <thead>
      <tr><th style="width:8%;">No</th><th style="width:32%;">Nama Perangkat Keras</th><th>Keterangan</th></tr>
    </thead>
    <tbody>
      <?php if ($items): foreach ($items as $i => $it): ?>
      <tr>
        <td class="no"><?= $i + 1 ?></td>
        <td><?= h(isset($it['nama']) ? $it['nama'] : '') ?></td>
        <td><?= h(isset($it['keterangan']) ? $it['keterangan'] : '') ?></td>
      </tr>
      <?php endforeach; else: ?>
      <tr><td class="no empty-row">1</td><td class="empty-row">&nbsp;</td><td class="empty-row">&nbsp;</td></tr>
      <?php endif; ?>
      <?php for ($k = count($items); $k < 15; $k++): ?>
      <tr><td class="no empty-row">&nbsp;</td><td class="empty-row">&nbsp;</td><td class="empty-row">&nbsp;</td></tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <table class="sign">
    <tr>
      <!-- ── Kolom Tim IT ── -->
      <td>
        Tim IT
        <div class="sign-box">
          <?php if (!empty($submission['tim_it_signature_path'])): ?>
            <!-- TTD sudah tersimpan → tampil gambar -->
            <img src="<?= APP_URL ?>/<?= h($submission['tim_it_signature_path']) ?>" alt="TTD Tim IT">
          <?php else: ?>
            <!-- TTD belum ada → tampil signature pad (no-print) -->
            <div class="ttd-panel no-print" id="ttdPanel" style="width:100%;">
              <div class="label-hint">Tanda tangan Tim IT (tidak ikut cetak sebelum disimpan)</div>
              <canvas id="sigCanvas" width="320" height="70"></canvas>
              <input type="text" id="inputNamaTim" placeholder="Nama Tim IT">
              <div class="btn-row">
                <button type="button" onclick="clearSig()">Hapus</button>
                <button type="button" class="btn-save" onclick="saveSig()">Simpan TTD</button>
              </div>
            </div>
            <!-- Saat cetak sebelum disimpan: garis kosong -->
            <span class="no-print" style="display:none;"></span>
          <?php endif; ?>
        </div>
        <div class="sign-line">
          <?php if (!empty($submission['tim_it_nama'])): ?>
            (<?= h($submission['tim_it_nama']) ?>)
          <?php else: ?>
            <span id="namaPreview" class="no-print">(___________________)</span>
            <span class="print-only" style="display:none;">(___________________)</span>
          <?php endif; ?>
        </div>
      </td>

      <!-- ── Kolom Unit/Bagian ── -->
      <td>
        Unit / Bagian
        <div class="sign-box">
          <img src="<?= APP_URL ?>/<?= h($submission['signature_path']) ?>" alt="Tanda tangan">
        </div>
        <div class="sign-line">(<?= h($submission['nama']) ?>)</div>
      </td>
    </tr>
  </table>

  <div class="mengetahui">
    Mengetahui,<br>
    Kepala Instalasi SIMRS
    <div class="gap"></div>
    dr. Ucu Nurhadiat, SpAn<br>
    Nip. 19771012 201001 1 007
  </div>

  <p style="margin-top:24px; font-size:10.5px; color:#666;">
    Token: <?= h($code) ?> &middot; Dikirim <?= date('d/m/Y H:i', strtotime($submission['submitted_at'])) ?>
  </p>

<?php endif; ?>
</div>

<?php if (empty($submission['tim_it_signature_path']) && $submission): ?>
<script>
(function () {
    const canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    let drawing = false, lastX = 0, lastY = 0;

    function getPos(e) {
        const r = canvas.getBoundingClientRect();
        const scaleX = canvas.width / r.width;
        const scaleY = canvas.height / r.height;
        const src = e.touches ? e.touches[0] : e;
        return [(src.clientX - r.left) * scaleX, (src.clientY - r.top) * scaleY];
    }

    canvas.addEventListener('mousedown',  e => { drawing = true; [lastX, lastY] = getPos(e); });
    canvas.addEventListener('mousemove',  e => { if (!drawing) return; const [x, y] = getPos(e); draw(lastX, lastY, x, y); lastX = x; lastY = y; });
    canvas.addEventListener('mouseup',    () => drawing = false);
    canvas.addEventListener('mouseleave', () => drawing = false);
    canvas.addEventListener('touchstart', e => { e.preventDefault(); drawing = true; [lastX, lastY] = getPos(e); }, { passive: false });
    canvas.addEventListener('touchmove',  e => { e.preventDefault(); if (!drawing) return; const [x, y] = getPos(e); draw(lastX, lastY, x, y); lastX = x; lastY = y; }, { passive: false });
    canvas.addEventListener('touchend',   () => drawing = false);

    function draw(x1, y1, x2, y2) {
        ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2);
        ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.stroke();
    }

    window.clearSig = function () {
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
    };

    window.saveSig = async function () {
        const nama = document.getElementById('inputNamaTim').value.trim();
        if (!nama) { alert('Isi nama Tim IT dulu.'); return; }

        const sig = canvas.toDataURL('image/png');
        try {
            const resp = await fetch('<?= APP_URL ?>/save_tim_it_sig', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: '<?= h($code) ?>', nama, signature: sig })
            });
            const res = await resp.json();
            if (res.ok) {
                location.reload();
            } else {
                alert(res.error || 'Gagal menyimpan.');
            }
        } catch (err) {
            alert('Error: ' + err.message);
        }
    };

    // Update preview nama di bawah
    document.getElementById('inputNamaTim').addEventListener('input', function () {
        const preview = document.getElementById('namaPreview');
        if (preview) {
            preview.textContent = this.value ? '(' + this.value + ')' : '(___________________)';
        }
    });
})();
</script>
<?php endif; ?>

</body>
</html>