<?php
declare(strict_types=1);
/**
 * Uji beban ringan: 20 PENGGUNA BERBEDA (sesi login masing-masing) mengakses sistem SERENTAK (syarat D2: minimal 20 pengguna simultan).
 * Setiap putaran, ke-20 pengguna mengirim satu permintaan baca pada saat yang sama (bergantian worklist dan metrik); dicatat response time tiap permintaan.
 *   SIMRAD_URL=http://localhost/simrad/public/api.php /Applications/XAMPP/xamppfiles/bin/php tests/load_multiuser.php [putaran=20]
 * Membutuhkan ekstensi PHP curl (ada di XAMPP). Hanya membaca data. Hasil ditambahkan ke docs/hasil_beban_multiuser.txt.
 */
date_default_timezone_set('Asia/Jakarta'); // stempel waktu hasil harus WIB (php.ini XAMPP memakai zona waktu lain)
$base = getenv('SIMRAD_URL') ?: 'http://localhost/simrad/public/api.php';
$putaran = max(1, (int)($argv[1] ?? 20)); $jumlahUser = 20;
if (!function_exists('curl_init')) { fwrite(STDERR, "Ekstensi PHP curl tidak tersedia.\n"); exit(1); }

// 1) login 20 dokter berbeda, masing-masing dengan cookie sendiri
$h = [];
for ($i = 1; $i <= $jumlahUser; $i++) {
    $u = sprintf('d%03d', $i); $c = curl_init($base . '?r=login');
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => '', CURLOPT_POSTFIELDS => json_encode(['username' => $u, 'password' => 'simrad2026']), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 20]);
    curl_exec($c);
    if (curl_getinfo($c, CURLINFO_HTTP_CODE) !== 200) { fwrite(STDERR, "Login $u gagal (HTTP " . curl_getinfo($c, CURLINFO_HTTP_CODE) . "). Periksa SIMRAD_URL dan data seed.\n"); exit(1); }
    curl_setopt($c, CURLOPT_POST, false); curl_setopt($c, CURLOPT_HTTPGET, true);
    $h[$u] = $c;
}
// 2) putaran serentak
$waktu = ['orders' => [], 'metrics' => []]; $galat = ['orders' => 0, 'metrics' => 0]; $mulai = microtime(true);
for ($r = 0; $r < $putaran; $r++) {
    $mh = curl_multi_init(); $peta = [];
    foreach (array_values($h) as $k => $c) { $ep = ($k + $r) % 2 === 0 ? 'orders' : 'metrics'; curl_setopt($c, CURLOPT_URL, $base . '?r=' . $ep); curl_multi_add_handle($mh, $c); $peta[spl_object_id($c)] = $ep; }
    do { $st = curl_multi_exec($mh, $aktif); if ($aktif) curl_multi_select($mh, 0.2); } while ($aktif && $st === CURLM_OK);
    foreach (array_values($h) as $c) {
        $ep = $peta[spl_object_id($c)]; $kode = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_multi_getcontent($c);
        if ($kode === 200) $waktu[$ep][] = curl_getinfo($c, CURLINFO_TOTAL_TIME) * 1000; else $galat[$ep]++;
        curl_multi_remove_handle($mh, $c);
    }
    curl_multi_close($mh);
}
$total = microtime(true) - $mulai;
$pct = function (array $a, float $p): float { sort($a); return $a ? $a[(int)min(count($a) - 1, ceil($p / 100 * count($a)) - 1)] : 0.0; };
$baris = []; $semua = [];
foreach (['orders', 'metrics'] as $ep) { $w = $waktu[$ep]; $semua = array_merge($semua, $w); $baris[] = sprintf("%-12s %7d %7d %10.1f %9.1f %9.1f %9.1f", $ep, count($w) + $galat[$ep], $galat[$ep], $w ? array_sum($w) / count($w) : 0, $pct($w, 50), $pct($w, 95), $w ? max($w) : 0); }
$baris[] = sprintf("%-12s %7d %7d %10.1f %9.1f %9.1f %9.1f", 'semua', count($semua) + array_sum($galat), array_sum($galat), $semua ? array_sum($semua) / count($semua) : 0, $pct($semua, 50), $pct($semua, 95), $semua ? max($semua) : 0);
$out = "=== Uji beban multi-pengguna SIMRAD: " . date('Y-m-d H:i:s') . " ===\n"
    . "Lingkungan: " . PHP_OS . "; PHP " . PHP_VERSION . "; target $base\n"
    . "Pengguna serentak: $jumlahUser (sesi berbeda, dokter d001-d" . sprintf('%03d', $jumlahUser) . "); putaran: $putaran; permintaan per pengguna: $putaran; total: " . ($jumlahUser * $putaran) . "; durasi total: " . round($total, 2) . " detik\n"
    . sprintf("%-12s %7s %7s %10s %9s %9s %9s\n", 'endpoint', 'jumlah', 'gagal', 'rerata(ms)', 'p50(ms)', 'p95(ms)', 'maks(ms)') . implode("\n", $baris) . "\n\n";
echo $out; @mkdir(__DIR__ . '/../docs', 0777, true); file_put_contents(__DIR__ . '/../docs/hasil_beban_multiuser.txt', $out, FILE_APPEND);
