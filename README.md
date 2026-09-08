# Hardware Monitoring System
Sistem monitoring aset hardware berbasis PHP + MySQL + PWA

---

## 📋 Requirements
- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.3+
- Shared hosting dengan HTTPS/SSL
- Browser modern (Chrome, Firefox, Safari)

---

## 🚀 Cara Install

### 1. Upload File
Upload seluruh folder `hardware-monitoring/` ke root hosting kamu (misal `public_html/hardware-monitoring/`)

### 2. Buat Database
- Buat database baru di cPanel → MySQL Database (misal: `hardware_monitoring`)
- Import file `database.sql` via phpMyAdmin

### 3. Konfigurasi
Edit file `config/db.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'hardware_monitoring');   // nama database
define('DB_USER', 'username_db');           // username MySQL
define('DB_PASS', 'password_db');           // password MySQL
define('APP_URL', 'https://yourdomain.com/hardware-monitoring'); // URL kamu
```

### 4. Set Permissions
```
chmod 755 uploads/
chmod 755 uploads/qr/
```

### 5. Login Pertama
- URL: `https://yourdomain.com/hardware-monitoring/`
- Admin: username `admin`, password `password`
- Member: username `member1`, password `password`
- **⚠️ SEGERA GANTI PASSWORD setelah login pertama!**

---

## 📱 Install PWA di HP
1. Buka URL sistem di Chrome/Safari HP
2. Tap menu (⋮) → "Tambahkan ke layar utama" / "Add to Home Screen"
3. App akan terinstall seperti aplikasi native

---

## 🖨️ Print Label (Zebra ZD220)
1. Pastikan Zebra ZD220 sudah terinstall di komputer/laptop
2. Masuk ke halaman aset → klik "Print Label"
3. Browser akan membuka halaman print 50×30mm
4. Pilih printer Zebra ZD220, pastikan ukuran kertas 50×30mm
5. Print!

---

## 🔒 Security Notes
- Semua query menggunakan PDO Prepared Statements (anti SQL injection)
- CSRF token di setiap form
- Password di-hash dengan bcrypt cost 12
- Login dibatasi 5x percobaan → lockout 15 menit
- 1 akun = 1 perangkat aktif
- Force logout tersedia untuk admin

---

## 📁 Struktur File
```
hardware-monitoring/
├── config/db.php          → Konfigurasi database
├── includes/              → Header, footer, auth helper
├── pages/                 → Semua halaman
├── api/                   → Endpoint API (rooms, QR)
├── assets/css,js,icons    → Static assets
├── manifest.json          → PWA manifest
├── sw.js                  → Service Worker
├── index.php              → Halaman login
├── logout.php             → Logout
└── database.sql           → Schema database
```

---

## 🔧 Flow Penggunaan
1. Admin **generate QR bulk** → print label → tempel ke hardware
2. Tim keliling, temukan hardware → **scan QR** via PWA
3. Kalau belum terdaftar → **isi form** (nama, tipe, lokasi, kondisi)
4. Sudah terdaftar → langsung tampil **detail + history**
5. Tambah **maintenance** langsung dari halaman detail
6. Tarik **laporan** kapan saja

---

## ⚠️ Icons PWA
Ganti file icon di `assets/icons/` dengan PNG asli:
- `icon-192.png` (192×192 pixel)
- `icon-512.png` (512×512 pixel)

Generate gratis: https://realfavicongenerator.net/
