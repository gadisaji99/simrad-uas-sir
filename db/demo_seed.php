<?php
declare(strict_types=1);
/**
 * Data DEMO FIKTIF untuk dashboard dan video. Bukan data pasien nyata.
 * Menghapus data transaksi (order, studi, laporan, log audit), lalu membuat skenario yang realistis
 * relatif terhadap jam sekarang, lewat alur kerja aplikasi yang sama (jejak audit ikut terisi).
 * Pakai:  php db/demo_seed.php     (pasien, pengguna, alat, dan jenis pemeriksaan tidak diubah)
 */
require __DIR__ . '/../src/bootstrap.php';
$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['workflow_event', 'report', 'instance', 'series', 'study', 'imaging_order'] as $t) $pdo->exec("TRUNCATE TABLE $t");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("UPDATE modality SET status='ONLINE'"); $pdo->exec("UPDATE modality SET status='PERLU_CEK' WHERE kode_alat='US-1'");

function aktor(string $u): array { $s = Db::pdo()->prepare('SELECT u.user_id, u.username, u.nama, r.nama_role AS role FROM app_user u JOIN role r USING (role_id) WHERE username=?'); $s->execute([$u]); $r = $s->fetch(); $r['user_id'] = (int)$r['user_id']; return $r; }
function satu(string $sql, array $p = []) { $s = Db::pdo()->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
$dok = [aktor('d001'), aktor('d002'), aktor('d003')]; $rg = [aktor('r001'), aktor('r002')]; $rd = [aktor('rad01'), aktor('rad02')];
$pasien = array_map('intval', Db::pdo()->query('SELECT patient_id FROM patient ORDER BY patient_id LIMIT 20')->fetchAll(PDO::FETCH_COLUMN));
$proc = fn(string $j, int $n = 0) => satu("SELECT procedure_id FROM `procedure` WHERE jenis_modalitas=? ORDER BY kode LIMIT 1 OFFSET $n", [$j]);
$alat = fn(string $j) => satu('SELECT modality_id FROM modality WHERE jenis=? LIMIT 1', [$j]);
$now = time();
$at = fn(int $menitLalu) => date('Y-m-d H:i:s', $now - $menitLalu * 60);

/**
 * Satu order: umur (menit sejak order dibuat), lalu menit setelah order untuk tiap tahap yang sudah terjadi.
 * Tahap: jadwal, mulai, selesai, final (null = belum terjadi).
 */
function skenario(int $i, string $prio, string $jenis, int $n, int $umur, array $tahap): void {
    global $dok, $rg, $rd, $pasien, $proc, $alat, $at;
    $d = $dok[$i % 3]; $g = $rg[$i % 2]; $r = $rd[$i % 2];
    Clock::$fixed = $at($umur);                       $o = Workflow::createOrder($d, $pasien[$i % count($pasien)], $proc($jenis, $n), $prio);
    [$j, $m, $s, $f] = $tahap;
    if ($j !== null) { Clock::$fixed = $at($umur - $j); Workflow::schedule($g, $o['order_id']); }
    if ($m !== null) { Clock::$fixed = $at($umur - $m); $st = Workflow::start($g, $o['order_id'], $alat($jenis)); }
    if ($s !== null) { Clock::$fixed = $at($umur - $s); Workflow::complete($g, $st['study_id'], rand(1, 3) * 40, true, strtoupper($jenis)); }
    if ($f !== null) { Clock::$fixed = $at($umur - $f); Workflow::saveReport($r, $st['study_id'], 'Temuan contoh (data demo fiktif).', 'Kesimpulan contoh (data demo fiktif).', true); }
}
// --- selesai (REPORTED): TAT CITO 35, 48, 52, 71 menit; TAT reguler 75, 90, 105, 118, 140 menit ---
skenario(0,  'CITO',    'CT', 0, 300, [5, 12, 30, 35]);
skenario(1,  'CITO',    'DX', 0, 280, [4, 10, 28, 48]);
skenario(2,  'CITO',    'MR', 0, 250, [8, 18, 40, 52]);
skenario(3,  'CITO',    'CT', 1, 230, [10, 20, 55, 71]);
skenario(4,  'REGULER', 'DX', 1, 270, [20, 30, 50, 75]);
skenario(5,  'REGULER', 'CT', 2, 260, [25, 40, 70, 90]);
skenario(6,  'REGULER', 'US', 0, 200, [30, 45, 75, 105]);
skenario(7,  'REGULER', 'MR', 1, 190, [35, 55, 90, 118]);
skenario(8,  'REGULER', 'DX', 2, 180, [30, 50, 100, 140]);
// --- masih berjalan ---
skenario(9,  'CITO',    'CT', 2, 40,  [5, 15, null, null]);            // CITO sedang diperiksa
skenario(10, 'CITO',    'DX', 3, 25,  [null, null, null, null]);       // CITO baru masuk
skenario(11, 'REGULER', 'US', 1, 95,  [20, null, null, null]);         // sudah dijadwalkan
skenario(12, 'REGULER', 'CT', 3, 80,  [15, 30, 60, null]);             // selesai, menunggu laporan
skenario(13, 'REGULER', 'MR', 2, 20,  [null, null, null, null]);       // baru masuk
Clock::$fixed = null;
$m = Metrics::summary();
echo "Data demo dibuat. Order per status: ", json_encode($m['per_status']), "\n";
echo "TAT CITO: ", json_encode($m['tat']['CITO']), "\nTAT reguler: ", json_encode($m['tat']['REGULER']), "\n";
