<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$db   = getDB();
$user = currentUser();

if (($user['role'] ?? '') !== 'admin') {
    header('Location: ' . APP_URL . '/dashboard');
    exit;
}

function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$error = '';
$success = false;
$token = null;

if ($code !== '') {
    $stmt = $db->prepare("SELECT * FROM hardware_tokens WHERE code = ?");
    $stmt->execute([$code]);
    $token = $stmt->fetch();
}

if ($code === '') {
    $error = 'Token tidak ditemukan.';
} elseif (!$token) {
    $error = 'Token tidak ditemukan.';
} elseif ($token['form_type'] !== 'serah') {
    $error = 'Token ini bukan jenis Serah Perangkat.';
} elseif ($token['status'] === 'used') {
    $error = 'Token ini sudah digunakan sebelumnya.';
}

if (!$error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_form'])) {
    $unit            = trim($_POST['unit'] ?? '');
    $bagian          = trim($_POST['bagian'] ?? '');
    $diserahkan_oleh = trim($_POST['diserahkan_oleh'] ?? '');
    $diterima_oleh   = trim($_POST['diterima_oleh'] ?? '');
    $tanggal         = trim($_POST['tanggal'] ?? '');
    $hw_names = $_POST['hw_name'] ?? [];
    $hw_descs = $_POST['hw_desc'] ?? [];
    $signature_data = $_POST['signature_data'] ?? '';

    $errors = [];
    if ($unit === '') $errors[] = 'Unit wajib diisi.';
    if ($bagian === '') $errors[] = 'Bagian wajib diisi.';
    if ($diserahkan_oleh === '') $errors[] = 'Nama yang menyerahkan wajib diisi.';
    if ($diterima_oleh === '') $errors[] = 'Nama yang menerima wajib diisi.';
    if ($tanggal === '') $errors[] = 'Tanggal wajib diisi.';

    $items = [];
    foreach ($hw_names as $i => $n) {
        $n = trim($n);
        $d = trim($hw_descs[$i] ?? '');
        if ($n !== '' || $d !== '') $items[] = ['nama' => $n, 'keterangan' => $d];
    }
    $hasNamedItem = false;
    foreach ($items as $it) { if ($it['nama'] !== '') { $hasNamedItem = true; break; } }
    if (!$hasNamedItem) $errors[] = 'Mohon isi minimal satu nama perangkat.';

    if ($signature_data === '' || strpos($signature_data, 'base64,') === false) {
        $errors[] = 'Tanda tangan penerima wajib diisi.';
    }

    if (empty($errors)) {
        $check = $db->prepare("SELECT status FROM hardware_tokens WHERE code = ?");
        $check->execute([$code]);
        $row = $check->fetch();

        if (!$row || $row['status'] === 'used') {
            $error = 'Token ini sudah digunakan.';
        } else {
            $sig_dir = __DIR__ . '/../uploads/hardware_signatures';
            if (!is_dir($sig_dir)) { mkdir($sig_dir, 0775, true); }

            $base64   = substr($signature_data, strpos($signature_data, 'base64,') + 7);
            $binary   = base64_decode($base64);
            $sig_name = $code . '_' . time() . '.png';
            file_put_contents($sig_dir . '/' . $sig_name, $binary);
            $sig_path = 'uploads/hardware_signatures/' . $sig_name;

            try {
                $db->beginTransaction();

                $stmt = $db->prepare("INSERT INTO hardware_submissions
                    (token_code, form_type, unit, bagian, nama, diserahkan_oleh, tanggal, items, signature_path, submitted_at)
                    VALUES (?,?,?,?,?,?,?,?,?,NOW())");
                $stmt->execute([
                    $code, 'serah', $unit, $bagian, $diterima_oleh, $diserahkan_oleh, $tanggal,
                    json_encode($items, JSON_UNESCAPED_UNICODE), $sig_path
                ]);

                $upd = $db->prepare("UPDATE hardware_tokens SET status='used', used_at=NOW() WHERE code = ?");
                $upd->execute([$code]);

                $db->commit();
                $success = true;
            } catch (Throwable $ex) {
                $db->rollBack();
                error_log('sign_serah submit error: ' . $ex->getMessage());
                $error = 'Gagal menyimpan data. Silakan coba lagi.';
            }
        }
    } else {
        $error = implode(' ', $errors);
    }
}

define('PAGE_TITLE', 'TTD Penerima - Serah Perangkat');
include __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-2"></i><?= h($error) ?></div>

<?php elseif ($success): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <div class="d-inline-flex align-items-center justify-content-center bg-success-subtle text-success rounded-circle mb-3" style="width:56px;height:56px;">
                <i class="bi bi-check-lg fs-3"></i>
            </div>
            <h5 class="fw-bold">Serah terima berhasil disimpan</h5>
            <p class="text-muted">Token <strong><?= h($code) ?></strong> sudah terkunci dan tidak dapat dipakai lagi.</p>
            <div class="d-flex justify-content-center gap-2 mt-3">
                <a href="<?= APP_URL ?>/token_generate?view=<?= h($code) ?>" class="btn btn-primary"><i class="bi bi-eye me-1"></i>Lihat Detail</a>
                <a href="<?= APP_URL ?>/print_form?code=<?= h($code) ?>" target="_blank" class="btn btn-warning"><i class="bi bi-printer me-1"></i>Cetak</a>
            </div>
        </div>
    </div>

<?php else: ?>
    <div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i>Token <strong><?= h($code) ?></strong> &mdash; isi data, lihat preview, lalu minta penerima tanda tangan langsung di perangkat ini.</div>

    <form id="mainForm" method="post" autocomplete="off">
        <input type="hidden" name="code" value="<?= h($code) ?>">
        <input type="hidden" name="signature_data" id="signature_data" value="">

        <!-- ══════════ STEP 1: ISI DATA ══════════ -->
        <div id="formStep">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-building me-2"></i>Informasi Serah Terima</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Unit</label>
                            <input type="text" name="unit" id="f_unit" class="form-control" placeholder="Contoh: Rawat Inap Lt. 2" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bagian</label>
                            <input type="text" name="bagian" id="f_bagian" class="form-control" placeholder="Contoh: Administrasi" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Diserahkan oleh</label>
                            <input type="text" name="diserahkan_oleh" id="f_diserahkan" class="form-control" placeholder="Nama staf SIMRS/IT" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Diterima oleh</label>
                            <input type="text" name="diterima_oleh" id="f_diterima" class="form-control" placeholder="Nama penerima" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tanggal</label>
                            <input type="date" name="tanggal" id="f_tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-hdd-stack me-2"></i>Perangkat yang Diserahkan</div>
                <div class="card-body">
                    <p class="text-muted small">Sebutkan perangkat yang diserahkan dan kondisinya. Bisa lebih dari satu.</p>
                    <div id="hwRows" data-name-placeholder="Contoh: PC Unit Pendaftaran" data-desc-placeholder="Contoh: Baru, kondisi baik">
                        <div class="hw-row">
                            <input type="text" name="hw_name[]" class="form-control hw-name" placeholder="Contoh: PC Unit Pendaftaran">
                            <input type="text" name="hw_desc[]" class="form-control hw-desc" placeholder="Contoh: Baru, kondisi baik">
                            <button type="button" class="btn btn-outline-danger rm-btn" disabled><i class="bi bi-trash"></i></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="addHwBtn"><i class="bi bi-plus-lg me-1"></i>Tambah Perangkat</button>
                </div>
            </div>

            <div id="step1ErrSlot"></div>

            <button type="button" id="toPreviewBtn" class="btn btn-primary w-100 py-2">
                Lihat Preview <i class="bi bi-arrow-right ms-2"></i>
            </button>
        </div>

        <!-- ══════════ STEP 2: PREVIEW + TTD ══════════ -->
        <div id="previewStep" style="display:none;">
            <div class="card mb-3">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-eye me-2"></i>Preview Formulir</span>
                    <button type="button" class="btn btn-sm btn-light" id="backToEditBtn"><i class="bi bi-pencil me-1"></i>Edit</button>
                </div>
                <div class="card-body bg-white">
                    <div id="previewContent"></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-pen me-2"></i>Tanda Tangan Penerima</div>
                <div class="card-body">

                    <div id="signPromptArea">
                        <p class="text-muted small mb-2">Serahkan perangkat ini ke penerima, lalu klik tombol berikut untuk mulai menandatangani.</p>
                        <button type="button" class="btn btn-success" id="openSignBtn"><i class="bi bi-pen me-1"></i>TTD Penerima</button>
                    </div>

                    <div id="signPadArea" style="display:none;">
                        <div class="sig-wrap">
                            <canvas id="sigCanvas"></canvas>
                            <div class="sig-placeholder" id="sigPlaceholder">Tanda tangan di sini</div>
                        </div>
                        <div class="d-flex justify-content-end mt-2 gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger" id="clearSigBtn"><i class="bi bi-trash me-1"></i>Hapus</button>
                            <button type="button" class="btn btn-sm btn-primary" id="doneSignBtn"><i class="bi bi-check2 me-1"></i>Selesai TTD</button>
                        </div>
                    </div>

                    <div id="signDoneArea" style="display:none;">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="border rounded p-2 bg-white">
                                <img id="signPreviewImg" style="max-height:80px; max-width:220px;" alt="Tanda tangan">
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="redoSignBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Tanda Tangan Ulang</button>
                        </div>
                    </div>

                </div>
            </div>

            <div id="jsErrSlot"></div>

            <button type="submit" name="submit_form" value="1" id="finalSubmitBtn" class="btn btn-primary w-100 py-2" disabled>
                <i class="bi bi-check2-circle me-2"></i>Simpan Serah Terima
            </button>
        </div>
    </form>
<?php endif; ?>

<style>
  .sig-wrap{border:1px solid #dee2e6;border-radius:10px;background:#fff;position:relative;overflow:hidden;}
  .sig-wrap canvas{display:block;width:100%;height:160px;touch-action:none;cursor:crosshair;}
  .sig-placeholder{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#adb5bd;font-size:13px;pointer-events:none;}
  .hw-row{display:grid;grid-template-columns:1fr 1.4fr auto;gap:10px;align-items:start;margin-bottom:10px;}
  @media (max-width:576px){.hw-row{grid-template-columns:1fr;}}

  .pv-title{text-align:center;font-size:13px;font-weight:bold;margin:0 0 14px;}
  table.pv-info{width:100%;border-collapse:collapse;margin-bottom:14px;border:1px solid #000;}
  table.pv-info td{border:1px solid #000;padding:6px 10px;font-size:13px;}
  table.pv-info td.label{width:26%;}
  table.pv-info td.label2{width:18%;}
  table.pv-items{width:100%;border-collapse:collapse;margin-bottom:6px;border:1px solid #000;}
  table.pv-items th,table.pv-items td{border:1px solid #000;padding:6px 8px;font-size:12.5px;text-align:left;}
  table.pv-items th{text-align:center;font-weight:bold;background:#D9D9D9;}
  table.pv-items td.no{text-align:center;width:8%;}
</style>

<script>
(function () {
  let ctx = null, drawing = false, hasContent = false, last = null, sigCropped = null;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function tglIndo(ymd) {
    const bulan = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const p = (ymd || '').split('-');
    if (p.length !== 3) return esc(ymd);
    return parseInt(p[2], 10) + ' ' + bulan[parseInt(p[1], 10) - 1] + ' ' + p[0];
  }

  function buildPreview() {
    const unit = document.getElementById('f_unit').value.trim();
    const bagian = document.getElementById('f_bagian').value.trim();
    const diserahkan = document.getElementById('f_diserahkan').value.trim();
    const diterima = document.getElementById('f_diterima').value.trim();
    const tanggal = document.getElementById('f_tanggal').value;

    let rows = '';
    let no = 1;
    document.querySelectorAll('#hwRows .hw-row').forEach(function (row) {
      const n = row.querySelector('.hw-name').value.trim();
      const d = row.querySelector('.hw-desc').value.trim();
      if (n || d) {
        rows += '<tr><td class="no">' + (no++) + '</td><td>' + esc(n) + '</td><td>' + esc(d) + '</td></tr>';
      }
    });
    if (!rows) rows = '<tr><td class="no">1</td><td>&nbsp;</td><td>&nbsp;</td></tr>';

    const html =
      '<div class="pv-title">FORMULIR SERAH TERIMA PERANGKAT KERAS</div>' +
      '<table class="pv-info">' +
        '<tr><td class="label">Unit</td><td colspan="3">' + esc(unit) + '</td></tr>' +
        '<tr><td class="label">Tanggal</td><td>' + tglIndo(tanggal) + '</td><td class="label2">Bagian</td><td>' + esc(bagian) + '</td></tr>' +
      '</table>' +
      '<table class="pv-items">' +
        '<thead><tr><th style="width:8%;">No</th><th style="width:32%;">Nama Perangkat Keras</th><th>Kondisi / Keterangan</th></tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>' +
      '<div class="row mt-3 text-center small">' +
        '<div class="col-6">Tim IT<br><strong>(' + esc(diserahkan || '___________________') + ')</strong></div>' +
        '<div class="col-6">Unit / Bagian<br><strong>(' + esc(diterima || '___________________') + ')</strong></div>' +
      '</div>';

    document.getElementById('previewContent').innerHTML = html;
  }

  function validateStep1() {
    const errs = [];
    if (!document.getElementById('f_unit').value.trim()) errs.push('Unit wajib diisi.');
    if (!document.getElementById('f_bagian').value.trim()) errs.push('Bagian wajib diisi.');
    if (!document.getElementById('f_diserahkan').value.trim()) errs.push('Nama yang menyerahkan wajib diisi.');
    if (!document.getElementById('f_diterima').value.trim()) errs.push('Nama yang menerima wajib diisi.');
    if (!document.getElementById('f_tanggal').value) errs.push('Tanggal wajib diisi.');
    let hasItem = false;
    document.querySelectorAll('#hwRows .hw-row').forEach(function (row) {
      if (row.querySelector('.hw-name').value.trim()) hasItem = true;
    });
    if (!hasItem) errs.push('Mohon isi minimal satu nama perangkat.');
    return errs;
  }

  function getCroppedSignature(canvas) {
    const c = canvas.getContext('2d');
    const w = canvas.width, h = canvas.height;
    let data;
    try { data = c.getImageData(0, 0, w, h).data; } catch (e) { return canvas.toDataURL('image/png'); }
    let minX = w, minY = h, maxX = 0, maxY = 0, found = false;
    for (let y = 0; y < h; y++) {
      for (let x = 0; x < w; x++) {
        if (data[(y * w + x) * 4 + 3] > 10) {
          found = true;
          if (x < minX) minX = x;
          if (x > maxX) maxX = x;
          if (y < minY) minY = y;
          if (y > maxY) maxY = y;
        }
      }
    }
    if (!found) return null;
    const pad = 14;
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
    maxX = Math.min(w, maxX + pad); maxY = Math.min(h, maxY + pad);
    const cw = maxX - minX, ch = maxY - minY;
    const out = document.createElement('canvas');
    out.width = cw; out.height = ch;
    out.getContext('2d').drawImage(canvas, minX, minY, cw, ch, 0, 0, cw, ch);
    return out.toDataURL('image/png');
  }

  function setupSigPad() {
    const canvas = document.getElementById('sigCanvas');
    if (!canvas || ctx) return; // sudah disetup sebelumnya
    const placeholder = document.getElementById('sigPlaceholder');
    const ratio = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * ratio;
    canvas.height = 160 * ratio;
    ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#212529';

    function pos(e) { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function start(e) { e.preventDefault(); drawing = true; hasContent = true; if (placeholder) placeholder.style.display = 'none'; last = pos(e); }
    function move(e) { if (!drawing) return; e.preventDefault(); const p = pos(e); ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke(); last = p; }
    function end() { drawing = false; }

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);

    document.getElementById('clearSigBtn').addEventListener('click', function () {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasContent = false;
      if (placeholder) placeholder.style.display = 'block';
    });
  }

  function setupHw() {
    const wrap = document.getElementById('hwRows');
    const addBtn = document.getElementById('addHwBtn');
    function renumber() {
      const rows = wrap.querySelectorAll('.hw-row');
      wrap.querySelectorAll('.rm-btn').forEach(function (b) { b.disabled = (rows.length <= 1); });
    }
    addBtn.addEventListener('click', function () {
      const row = document.createElement('div');
      row.className = 'hw-row';
      row.innerHTML =
        '<input type="text" name="hw_name[]" class="form-control hw-name" placeholder="' + (wrap.dataset.namePlaceholder || '') + '">' +
        '<input type="text" name="hw_desc[]" class="form-control hw-desc" placeholder="' + (wrap.dataset.descPlaceholder || '') + '">' +
        '<button type="button" class="btn btn-outline-danger rm-btn"><i class="bi bi-trash"></i></button>';
      row.querySelector('.rm-btn').addEventListener('click', function () { row.remove(); renumber(); });
      wrap.appendChild(row);
      renumber();
    });
    renumber();
  }

  document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('mainForm');
    if (!form) return;

    setupHw();

    document.getElementById('toPreviewBtn').addEventListener('click', function () {
      const errs = validateStep1();
      const slot = document.getElementById('step1ErrSlot');
      if (errs.length) {
        slot.innerHTML = '<div class="alert alert-danger">' + errs.join(' ') + '</div>';
        slot.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      slot.innerHTML = '';
      buildPreview();
      document.getElementById('formStep').style.display = 'none';
      document.getElementById('previewStep').style.display = 'block';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    document.getElementById('backToEditBtn').addEventListener('click', function () {
      document.getElementById('previewStep').style.display = 'none';
      document.getElementById('formStep').style.display = 'block';
    });

    document.getElementById('openSignBtn').addEventListener('click', function () {
      document.getElementById('signPromptArea').style.display = 'none';
      document.getElementById('signPadArea').style.display = 'block';
      setupSigPad();
    });

    document.getElementById('doneSignBtn').addEventListener('click', function () {
      const canvas = document.getElementById('sigCanvas');
      const cropped = getCroppedSignature(canvas);
      const errSlot = document.getElementById('jsErrSlot');
      if (!cropped) {
        errSlot.innerHTML = '<div class="alert alert-danger">Mohon tanda tangan terlebih dahulu sebelum klik selesai.</div>';
        return;
      }
      errSlot.innerHTML = '';
      sigCropped = cropped;
      document.getElementById('signature_data').value = sigCropped;
      document.getElementById('signPreviewImg').src = sigCropped;
      document.getElementById('signPadArea').style.display = 'none';
      document.getElementById('signDoneArea').style.display = 'block';
      document.getElementById('finalSubmitBtn').disabled = false;
    });

    document.getElementById('redoSignBtn').addEventListener('click', function () {
      sigCropped = null;
      document.getElementById('signature_data').value = '';
      document.getElementById('finalSubmitBtn').disabled = true;
      document.getElementById('signDoneArea').style.display = 'none';
      document.getElementById('signPromptArea').style.display = 'block';
      const canvas = document.getElementById('sigCanvas');
      if (canvas && ctx) { ctx.clearRect(0, 0, canvas.width, canvas.height); hasContent = false; }
      document.getElementById('sigPlaceholder').style.display = 'block';
    });

    form.addEventListener('submit', function (e) {
      if (!sigCropped) {
        e.preventDefault();
        const errSlot = document.getElementById('jsErrSlot');
        errSlot.innerHTML = '<div class="alert alert-danger">Tanda tangan penerima wajib diisi.</div>';
        errSlot.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>