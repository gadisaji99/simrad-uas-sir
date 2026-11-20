<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
// Angka desimal pada JSON tidak boleh bergantung pada php.ini (XAMPP bisa memakai serialize_precision tinggi): -1 = representasi terpendek yang akurat.
ini_set('serialize_precision', '-1');

/** Galat yang aman ditampilkan ke pengguna (kode HTTP + pesan). */
final class ApiError extends Exception {
    public function __construct(public int $status, public string $kode, string $pesan) { parent::__construct($pesan); }
}

/** Jam sistem. Pada pengujian, nilainya bisa dikunci agar perhitungan TAT dapat diverifikasi. */
final class Clock {
    public static ?string $fixed = null;
    public static function now(): string { return self::$fixed ?? date('Y-m-d H:i:s'); }
}

/** Koneksi basis data (PDO, prepared statement, galat sebagai exception). */
final class Db {
    private static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (self::$pdo === null) {
            $c = (require __DIR__ . '/../config.php')['db'];
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }
    public static function reset(): void { self::$pdo = null; }
}

require __DIR__ . '/Auth.php';
require __DIR__ . '/Workflow.php';
require __DIR__ . '/Metrics.php';
require __DIR__ . '/Pacs.php';
require __DIR__ . '/Integration.php';
