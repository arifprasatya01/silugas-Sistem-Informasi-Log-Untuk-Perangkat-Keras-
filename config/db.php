<?php
// config/db.php

// ── Polyfill untuk PHP < 8.0 ────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        $length = strlen($needle);
        return $length === 0 || substr($haystack, -$length) === $needle;
    }
}

// Set timezone PHP ke WIB (Asia/Jakarta) — supaya date(), time(), dll sesuai jam Indonesia
date_default_timezone_set('Asia/Jakarta');

define('DB_HOST', 'localhost');
define('DB_NAME', 'rsud_simrs');
define('DB_USER', 'rsud_simrs');         // Ganti dengan user MySQL kamu
define('DB_PASS', 'Rsudk4r4w4ng');             // Ganti dengan password MySQL kamu
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'SILUGAS ');
define('APP_URL', 'https://simrs.rsudkarawang.com/hardware'); // Ganti dengan URL kamu
define('APP_VERSION', '1.0.0');
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Samakan collation koneksi dengan collation tabel (utf8mb4_unicode_ci)
            // supaya tidak error "Illegal mix of collations" saat WHERE/JOIN
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            // Set timezone session MySQL ke WIB (+07:00) supaya NOW() ikut jam Indonesia
            $pdo->exec("SET time_zone = '+07:00'");
        } catch (PDOException $e) {
            error_log("DB Connection Error: " . $e->getMessage());
            die(json_encode(['error' => 'Koneksi database gagal.']));
        }
    }
    return $pdo;
}