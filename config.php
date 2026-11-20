<?php
// Konfigurasi SIMRAD. Nilai bawaan cocok untuk XAMPP lokal (root tanpa kata sandi).
// Untuk lingkungan lain, atur lewat variabel lingkungan: SIMRAD_DB_HOST, SIMRAD_DB_NAME, dst.
return [
    'db' => [
        'host' => getenv('SIMRAD_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('SIMRAD_DB_PORT') ?: '3306',
        'name' => getenv('SIMRAD_DB_NAME') ?: 'simrad',
        'user' => getenv('SIMRAD_DB_USER') ?: 'root',
        'pass' => getenv('SIMRAD_DB_PASS') ?: '',
    ],
    // Target TAT (menit) dari skenario: CITO < 1 jam, reguler < 2 jam
    'tat_target_menit' => ['CITO' => 60, 'REGULER' => 120],
];
