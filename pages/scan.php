<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
define('PAGE_TITLE', 'Scan QR Code');
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="text-center mb-3">
            <h4 class="fw-bold"><i class="bi bi-qr-code-scan me-2 text-primary"></i>Scan QR Code Aset</h4>
            <p class="text-muted small">Arahkan kamera ke QR Code pada label aset</p>
        </div>

        <div class="card">
            <div class="card-body p-2">
                <!-- Camera view -->
                <div id="camera-container" style="position:relative; width:100%; background:#000; border-radius:8px; overflow:hidden; min-height:280px;">
                    <video id="preview" style="width:100%; display:block;" playsinline autoplay muted></video>
                    <!-- Scan overlay -->
                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;">
                        <div style="width:200px;height:200px;border:3px solid #00acc1;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.4);">
                            <div id="scan-line" style="width:100%;height:3px;background:#00acc1;position:absolute;top:0;animation:scan 2s linear infinite;"></div>
                        </div>
                    </div>
                </div>

                <div id="status-msg" class="text-center mt-2 small text-muted">Memulai kamera...</div>

                <!-- Manual input fallback -->
                <div class="mt-3">
                    <div class="text-center text-muted small mb-2">— atau masukkan kode manual —</div>
                    <div class="input-group">
                        <input type="text" id="manual-code" class="form-control" placeholder="Contoh: HW-0001"
                               style="text-transform:uppercase" maxlength="20">
                        <button class="btn btn-primary" onclick="goToAsset(document.getElementById('manual-code').value)">
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Camera selector -->
        <div class="mt-2 text-center">
            <select id="camera-select" class="form-select form-select-sm w-auto d-inline-block" onchange="switchCamera(this.value)">
                <option value="">Kamera default</option>
            </select>
        </div>
    </div>
</div>

<style>
@keyframes scan {
    0% { top: 0; }
    50% { top: calc(100% - 3px); }
    100% { top: 0; }
}
</style>

<!-- jsQR library for QR decoding -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
const APP_URL = '<?= APP_URL ?>';
let currentStream = null;
let scanning = true;
let animFrame = null;
const video = document.getElementById('preview');
const canvas = document.createElement('canvas');
const ctx = canvas.getContext('2d');

async function startCamera(deviceId) {
    if (currentStream) {
        currentStream.getTracks().forEach(t => t.stop());
    }
    const constraints = {
        video: deviceId
            ? { deviceId: { exact: deviceId }, facingMode: 'environment' }
            : { facingMode: { ideal: 'environment' } }
    };
    try {
        currentStream = await navigator.mediaDevices.getUserMedia(constraints);
        video.srcObject = currentStream;
        video.play();
        setStatus('Kamera aktif — arahkan ke QR Code', '#2e7d32');

        // Populate camera list
        const devices = await navigator.mediaDevices.enumerateDevices();
        const sel = document.getElementById('camera-select');
        if (sel.options.length <= 1) {
            devices.filter(d => d.kind === 'videoinput').forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.deviceId;
                opt.textContent = d.label || 'Kamera ' + (sel.options.length);
                sel.appendChild(opt);
            });
        }

        scanning = true;
        scanLoop();
    } catch(e) {
        setStatus('⚠️ Kamera tidak bisa diakses. Gunakan input manual di bawah.', '#c62828');
    }
}

function scanLoop() {
    if (!scanning) return;
    animFrame = requestAnimationFrame(scanLoop);
    if (video.readyState !== video.HAVE_ENOUGH_DATA) return;
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
    if (code) {
        scanning = false;
        setStatus('✅ QR Code terdeteksi! Membuka aset...', '#2e7d32');
        goToAsset(code.data);
    }
}

function goToAsset(val) {
    val = val.trim();
    if (!val) return;

    // Jika QR berisi URL lengkap, extract parameter 'code' DULU sebelum diproses lebih lanjut
    if (/^https?:\/\//i.test(val)) {
        try {
            const u = new URL(val);
            const extracted = u.searchParams.get('code');
            if (extracted) {
                val = extracted;
            }
        } catch(e) {
            // URL tidak valid, lanjut pakai val asli
        }
    }

    val = val.trim().toUpperCase();
    if (!val) return;

    // Stop camera
    if (currentStream) currentStream.getTracks().forEach(t => t.stop());
    cancelAnimationFrame(animFrame);

    window.location.href = APP_URL + '/asset_detail?code=' + encodeURIComponent(val);
}

function switchCamera(deviceId) {
    scanning = false;
    cancelAnimationFrame(animFrame);
    startCamera(deviceId || null);
}

function setStatus(msg, color) {
    const el = document.getElementById('status-msg');
    el.textContent = msg;
    el.style.color = color || '#666';
}

// Manual input enter key
document.getElementById('manual-code').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') goToAsset(this.value);
});

// Start on load
if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    startCamera();
} else {
    setStatus('⚠️ Browser tidak mendukung kamera. Gunakan input manual.', '#c62828');
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>