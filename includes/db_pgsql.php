<?php
/**
 * Konfigurasi koneksi ke server Postgres WA Bailey (10.100.1.55)
 * Sumber data tiket WhatsApp yang mau di-sync ke tabel lokal "tiket".
 *
 * PENTING:
 *  - Isi username & password di bawah sesuai kredensial Postgres kamu.
 *  - Pastikan extension pdo_pgsql aktif di php.ini (extension=pdo_pgsql).
 *  - Idealnya jangan hardcode password di file ini kalau repo bakal di-commit ke git.
 *    Lebih aman pakai environment variable (getenv('PG_PASSWORD') dst).
 */

if (!defined('PG_CONFIG')) {
    define('PG_CONFIG', [
        'host'   => '10.100.1.55',
        'port'   => 5432,
        'dbname' => 'wa_bailey',
        'user'   => 'postgres',
        'pass'   => 'simrs',
    ]);
}

/**
 * Buka koneksi PDO ke Postgres.
 * @throws PDOException kalau koneksi gagal (host mati, kredensial salah, dll)
 */
function getPgDB(): PDO
{
    static $pgDb = null;
    if ($pgDb === null) {
        $cfg = PG_CONFIG;
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $cfg['host'], $cfg['port'], $cfg['dbname']);
        $pgDb = new PDO($dsn, $cfg['user'], $cfg['pass']);
        $pgDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pgDb;
}
