<?php
declare(strict_types=1);
/**
 * Uji integrasi 50 studi: alur penuh HIS -> RIS -> PACS (simulasi) untuk 50 studi dari 4 jenis modalitas.
 * Per studi: ADT^A04 (registrasi) -> ORM^O01 (order; tiap studi kelima CITO) -> jadwal -> cek Modality Worklist -> mulai -> selesai
 * (kirim ke PACS simulasi) -> laporan final -> ORU^R01. Setelah itu SETIAP UID instance dan atribut pasien di RIS dibandingkan dengan PACS.
 * Cara pakai (dari folder simrad):  SIMRAD_URL=http://localhost/simrad/public/api.php /Applications/XAMPP/xamppfiles/bin/php tests/uji_50_studi.php [jumlah=50]
 * PERHATIAN: seperti run_tests.php, uji ini mengosongkan data order dan pasien tambahan; jalankan db/demo_seed.php sesudahnya untuk data demo.
 * Hasil ditulis ke docs/hasil_50_studi.md (angka asli dari komputer yang menjalankan).
 */
require __DIR__ . '/../src/bootstrap.php';
$BASE = getenv('SIMRAD_URL') ?: 'http://127.0.0.1:8099/api.php';
$N = max(1, (int)($argv[1] ?? 50)); $cookies = []; $waktu = [];

function http(string $m, string $r, ?array $body, string $sess, array $q = [], ?string $tag = null): array {
    global $BASE, $cookies, $waktu;
    $url = $BASE . '?r=' . urlencode($r) . ($q ? '&' . http_build_query($q) : '');
    $hdr = "Content-Type: application/json\r\n" . (isset($cookies[$sess]) ? 'Cookie: ' . $cookies[$sess] . "\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $m, 'header' => $hdr, 'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true, 'timeout' => 20]]);
    $t0 = microtime(true); $res = file_get_contents($url, false, $ctx); $ms = (microtime(true) - $t0) * 1000;
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $mm)) $status = (int)$mm[1];
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/PHPSESSID=[^;]+/', $h, $mm)) $cookies[$sess] = $mm[0];
    }
    if ($tag !== null) $waktu[$tag][] = $ms;
    return [$status, json_decode((string)$res, true) ?? []];
}
function masuk(string $u): string { [$s] = http('POST', 'login', ['username' => $u, 'password' => 'simrad2026'], $u); if ($s !== 200) throw new RuntimeException("login $u gagal ($s)"); return $u; }
function q1(string $sql, array $p = []) { $st = Db::pdo()->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function qa(string $sql, array $p = []): array { $st = Db::pdo()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function adt(string $rm, string $nik, string $fam, string $giv, string $dob, string $sex, string $ctrl): string { return "MSH|^~\\&|HIS|RSUD-BINA-SEHAT|SIMRAD|RSUD-BINA-SEHAT|20261121120000||ADT^A04^ADT_A01|$ctrl|P|2.5.1\rPID|1||$rm^^^SIMRAD^MR~$nik^^^NIK^NI||$fam^$giv||$dob|$sex\r"; }
function orm(string $rm, string $kode, string $prio, string $ctrl): string { return "MSH|^~\\&|HIS|RSUD-BINA-SEHAT|SIMRAD|RSUD-BINA-SEHAT|20261121120100||ORM^O01^ORM_O01|$ctrl|P|2.5.1\rPID|1||$rm^^^SIMRAD^MR||X^Y||19900101|M\rORC|NW|HIS-$ctrl|||||^^^^^$prio\rOBR|1|HIS-$ctrl||$kode^Pemeriksaan^SIMRAD\r"; }
function pct(array $a, float $p): float { if (!$a) return 0.0; sort($a); return $a[(int)min(count($a) - 1, ceil($p / 100 * count($a)) - 1)]; }

// ---------- persiapan (sama dengan run_tests.php) ----------
$pdo = Db::pdo(); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['workflow_event', 'report', 'instance', 'series', 'study', 'imaging_order'] as $t) $pdo->exec("TRUNCATE TABLE $t");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1'); $pdo->exec('DELETE FROM patient WHERE patient_id > 100');
Pacs::pastikanTabel(); $pdo->exec('TRUNCATE TABLE pacs_sim_instance'); $pdo->exec('UPDATE pacs_sim_status SET online = 1');
$pdo->exec("UPDATE modality SET status='ONLINE'"); $pdo->exec("UPDATE modality SET status='PERLU_CEK' WHERE kode_alat='US-1'");
$D = masuk('d001'); $R = masuk('r001'); $RAD = masuk('rad01');
$alat = ['CT' => (int)q1("SELECT modality_id FROM modality WHERE kode_alat='CT-1'"), 'MR' => (int)q1("SELECT modality_id FROM modality WHERE kode_alat='MR-1'"), 'DX' => (int)q1("SELECT modality_id FROM modality WHERE kode_alat='DX-1'"), 'US' => (int)q1("SELECT modality_id FROM modality WHERE kode_alat='US-1'")];
$proc = []; foreach (['CT', 'MR', 'DX', 'US'] as $j) $proc[$j] = array_column(qa("SELECT kode FROM `procedure` WHERE jenis_modalitas = ? ORDER BY kode", [$j]), 'kode');
mt_srand(2026);   // benih tetap: uji dapat diulang dengan data yang sama
$depan = ['Ayu', 'Bima', 'Citra', 'Dimas', 'Eka', 'Fitri', 'Galih', 'Hana', 'Indra', 'Jelita']; $belakang = ['Pratama', 'Wijaya', 'Lestari', 'Santoso', 'Kusuma'];

$gagal = []; $ok = 0; $totalInstRis = 0; $totalInstPacs = 0; $selisihUid = 0; $atributSalah = 0; $mwlOk = 0; $oruOk = 0; $cito = 0; $mulai = microtime(true);
for ($i = 1; $i <= $N; $i++) {
    $jenis = ['CT', 'MR', 'DX', 'US'][($i - 1) % 4]; $kode = $proc[$jenis][intdiv($i - 1, 4) % count($proc[$jenis])]; $prio = $i % 5 === 0 ? 'CITO' : 'REGULER'; if ($prio === 'CITO') $cito++;
    $rm = sprintf('P8%03d', $i); $nik = sprintf('3590%012d', $i); $giv = $depan[($i - 1) % 10]; $fam = $belakang[($i - 1) % 5]; $dob = sprintf('%04d%02d%02d', mt_rand(1950, 2010), mt_rand(1, 12), mt_rand(1, 28)); $sex = $i % 2 ? 'F' : 'M';
    $inst = mt_rand(5, 60); $masalah = [];
    try {
        [, $a] = http('POST', 'hl7.terima', ['er7' => adt($rm, $nik, $fam, $giv, $dob, $sex, "A$i")], $D, [], 'ADT^A04 (registrasi pasien)'); if (($a['hasil'] ?? '') !== 'AA') $masalah[] = 'ADT bukan AA';
        [, $o] = http('POST', 'hl7.terima', ['er7' => orm($rm, $kode, $prio === 'CITO' ? 'S' : 'R', "O$i")], $D, [], 'ORM^O01 (order)'); if (($o['hasil'] ?? '') !== 'AA') { $masalah[] = 'ORM bukan AA'; throw new RuntimeException('order gagal'); }
        $acc = $o['data']['accession_no']; $oid = (int)$o['data']['order_id'];
        [, $sc] = http('POST', 'orders.schedule', ['order_id' => $oid], $R, [], 'Jadwalkan order');
        [, $m] = http('GET', 'mwl.daftar', null, $R, ['modality' => $jenis], 'Modality Worklist (MWL)'); $adaMwl = in_array($acc, array_column($m['data'] ?? [], 'accession_no'), true); if ($adaMwl) $mwlOk++; else $masalah[] = 'tidak tampil di MWL';
        [, $st] = http('POST', 'orders.start', ['order_id' => $oid, 'modality_id' => $alat[$jenis]], $R, [], 'Mulai pemeriksaan'); if (empty($st['study_id'])) throw new RuntimeException('studi tidak terbentuk');
        [, $c] = http('POST', 'studies.complete', ['study_id' => $st['study_id'], 'jumlah_instance' => $inst, 'kualitas_baik' => true, 'body_part' => $jenis], $R, [], 'Selesai dan kirim ke PACS'); if (($c['pacs'] ?? '') !== 'terkirim') $masalah[] = 'PACS tidak terkirim';
        // rekonsiliasi RIS vs PACS
        $ris = array_column(qa('SELECT i.sop_instance_uid FROM instance i JOIN series se USING (series_id) WHERE se.study_id = ?', [$st['study_id']]), 'sop_instance_uid'); sort($ris);
        $pacs = qa('SELECT * FROM pacs_sim_instance WHERE study_instance_uid = ?', [$st['study_instance_uid']]); $uid = array_column($pacs, 'sop_instance_uid'); sort($uid);
        $totalInstRis += count($ris); $totalInstPacs += count($uid); $beda = count(array_diff($ris, $uid)) + count(array_diff($uid, $ris)); $selisihUid += $beda; if ($beda || count($ris) !== $inst) $masalah[] = "UID tidak sama ($beda selisih; RIS " . count($ris) . ' dari ' . $inst . ')';
        $nama = (string)q1('SELECT nama FROM patient WHERE no_rm = ?', [$rm]); $salah = 0;
        foreach ($pacs as $r) if ($r['accession_no'] !== $acc || $r['patient_id'] !== $rm || $r['patient_name'] !== $nama || $r['modality'] !== $jenis) $salah++;
        $atributSalah += $salah; if ($salah) $masalah[] = "$salah baris atribut berbeda";
        http('POST', 'reports.save', ['study_id' => $st['study_id'], 'isi_temuan' => 'Temuan contoh (data fiktif).', 'kesimpulan' => "Kesimpulan contoh studi $i.", 'finalize' => true], $RAD, [], 'Laporan final');
        [, $oru] = http('GET', 'hl7.hasil', null, $RAD, ['order_id' => $oid], 'ORU^R01 (hasil ke HIS)'); $t = $oru['er7'] ?? ''; if (str_contains($t, $acc) && str_contains($t, $rm) && str_contains($t, "Kesimpulan contoh studi $i.")) $oruOk++; else $masalah[] = 'ORU tidak lengkap';
    } catch (Throwable $e) { $masalah[] = 'EXCEPTION: ' . $e->getMessage(); }
    if (!$masalah) $ok++; else $gagal[] = "studi $i ($rm, $jenis): " . implode('; ', $masalah);
    if ($i % 10 === 0) printf("  %d dari %d studi diproses, %d lolos sejauh ini\n", $i, $N, $ok);
}
$durasi = microtime(true) - $mulai; $tz = new DateTimeZone('Asia/Jakarta'); $stamp = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
$ringkas = sprintf("%d dari %d studi lolos rekonsiliasi penuh | instance RIS %d, PACS %d, selisih UID %d | atribut salah %d | MWL %d/%d | ORU %d/%d | CITO %d | durasi %.1f detik", $ok, $N, $totalInstRis, $totalInstPacs, $selisihUid, $atributSalah, $mwlOk, $N, $oruOk, $N, $cito, $durasi);
echo "\nRINGKASAN: $ringkas\n"; foreach ($gagal as $g) echo "  GAGAL: $g\n";

$md = "# Hasil uji integrasi 50 studi (HIS -> RIS -> PACS simulasi)\n\nDijalankan: $stamp (WIB) oleh tests/uji_50_studi.php. Lingkungan: " . PHP_OS . ", PHP " . PHP_VERSION . ", server $BASE. Benih acak tetap 2026; jumlah studi $N; data seluruhnya fiktif.\n\n"
    . "**Ringkasan: $ok dari $N studi lolos rekonsiliasi penuh.**\n\n| Ukuran | Hasil |\n|---|---|\n"
    . "| Studi lolos rekonsiliasi penuh (semua langkah dan perbandingan RIS-PACS) | $ok dari $N |\n| Jenis modalitas | CT, MR, DX, US (bergilir), dengan " . $cito . " studi CITO |\n"
    . "| Total instance DICOM simulasi di RIS | $totalInstRis |\n| Total instance di PACS simulasi | $totalInstPacs |\n| Selisih UID instance (RIS dibanding PACS, dua arah) | $selisihUid |\n| Baris PACS dengan atribut berbeda (nomor akses, nomor RM, nama, modalitas) | $atributSalah |\n"
    . "| Studi tampil di Modality Worklist setelah dijadwalkan | $mwlOk dari $N |\n| Pesan ORU memuat nomor akses, nomor RM, dan kesimpulan | $oruOk dari $N |\n| Durasi total | " . sprintf('%.1f', $durasi) . " detik |\n\n"
    . "## Waktu respons per langkah (milidetik)\n\n| Langkah | Jumlah panggilan | Rerata | Median | p95 | Maks |\n|---|---|---|---|---|---|\n";
foreach ($waktu as $k => $a) $md .= sprintf("| %s | %d | %.1f | %.1f | %.1f | %.1f |\n", $k, count($a), array_sum($a) / count($a), pct($a, 50), pct($a, 95), max($a));
$md .= "\n" . ($gagal ? "## Studi yang gagal\n\n- " . implode("\n- ", $gagal) . "\n" : "Tidak ada studi yang gagal.\n");
@mkdir(__DIR__ . '/../docs', 0777, true); file_put_contents(__DIR__ . '/../docs/hasil_50_studi.md', $md); echo "Hasil ditulis ke docs/hasil_50_studi.md\n";
exit($ok === $N ? 0 : 1);
