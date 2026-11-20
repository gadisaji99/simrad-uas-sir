<?php
declare(strict_types=1);
/**
 * Uji black box (HTTP, TC01-TC24) dan uji integrasi/TAT (in-process dengan jam terkunci, IT01-IT04).
 * Cara pakai: nyalakan server (tests/serve.sh), lalu: php tests/run_tests.php
 * Hasil ditulis ke docs/hasil_uji.md (tabel: ID, skenario, hasil diharapkan, hasil aktual, status).
 */
require __DIR__ . '/../src/bootstrap.php';
$BASE = getenv('SIMRAD_URL') ?: 'http://127.0.0.1:8099/api.php';
$hasil = [];
$cookies = [];

function http(string $m, string $r, ?array $body, string $sess, array $q = [], ?string $rawBody = null): array {
    global $BASE, $cookies;
    $url = $BASE . '?r=' . urlencode($r) . ($q ? '&' . http_build_query($q) : '');
    $hdr = "Content-Type: application/json\r\n" . (isset($cookies[$sess]) ? 'Cookie: ' . $cookies[$sess] . "\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $m, 'header' => $hdr, 'content' => $rawBody ?? ($body !== null ? json_encode($body) : ''), 'ignore_errors' => true, 'timeout' => 10]]);
    $res = file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $mm)) $status = (int)$mm[1];
        if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/PHPSESSID=[^;]+/', $h, $mm)) $cookies[$sess] = $mm[0];
    }
    return [$status, json_decode((string)$res, true) ?? []];
}
function masuk(string $username): string { [$s] = http('POST', 'login', ['username' => $username, 'password' => 'simrad2026'], $username); if ($s !== 200) throw new RuntimeException("login $username gagal ($s)"); return $username; }
function tc(string $id, string $deskripsi, string $harapan, callable $fn): void {
    global $hasil;
    try { [$ok, $aktual] = $fn(); } catch (Throwable $e) { $ok = false; $aktual = 'EXCEPTION: ' . $e->getMessage(); }
    $hasil[] = [$id, $deskripsi, $harapan, $aktual, $ok ? 'LULUS' : 'GAGAL'];
    printf("%-5s %-6s %s\n", $id, $ok ? 'LULUS' : 'GAGAL', $deskripsi . ($ok ? '' : "  -> $aktual"));
}
function q1(string $sql, array $p = []) { $st = Db::pdo()->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function aktor(string $username): array { $st = Db::pdo()->prepare('SELECT u.user_id, u.username, u.nama, r.nama_role AS role FROM app_user u JOIN role r USING (role_id) WHERE username = ?'); $st->execute([$username]); $r = $st->fetch(); $r['user_id'] = (int)$r['user_id']; return $r; }

// ---------- Persiapan: bersihkan data transaksi, pulihkan status alat ----------
$pdo = Db::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['workflow_event', 'report', 'instance', 'series', 'study', 'imaging_order'] as $t) $pdo->exec("TRUNCATE TABLE $t");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec('DELETE FROM patient WHERE patient_id > 100'); // pasien tambahan hasil uji sebelumnya (seed berisi 100 pasien)
Pacs::pastikanTabel(); $pdo->exec('TRUNCATE TABLE pacs_sim_instance'); $pdo->exec('UPDATE pacs_sim_status SET online = 1');
$pdo->exec("UPDATE modality SET status='ONLINE'"); $pdo->exec("UPDATE modality SET status='PERLU_CEK' WHERE kode_alat='US-1'");
$pt = (int)q1('SELECT patient_id FROM patient ORDER BY patient_id LIMIT 1');
$pt2 = (int)q1('SELECT patient_id FROM patient ORDER BY patient_id LIMIT 1 OFFSET 1');
$prCT = (int)q1("SELECT procedure_id FROM `procedure` WHERE jenis_modalitas='CT' ORDER BY kode LIMIT 1");
$prUS = (int)q1("SELECT procedure_id FROM `procedure` WHERE jenis_modalitas='US' ORDER BY kode LIMIT 1");
$mCT = (int)q1("SELECT modality_id FROM modality WHERE kode_alat='CT-1'");
$mUS = (int)q1("SELECT modality_id FROM modality WHERE kode_alat='US-1'");
$mDX = (int)q1("SELECT modality_id FROM modality WHERE kode_alat='DX-1'");
$D1 = masuk('d001'); $D2 = masuk('d002'); $R1 = masuk('r001'); $RAD = masuk('rad01'); $ADM = masuk('adm01');

// alur penuh dipakai beberapa kasus
function buatOrder(string $sess, int $pt, int $pr, string $prio = 'REGULER'): int { [, $j] = http('POST', 'orders.create', ['patient_id' => $pt, 'procedure_id' => $pr, 'prioritas' => $prio], $sess); return (int)$j['order_id']; }

// ================= UJI BLACK BOX =================
tc('TC01', 'Login dengan kredensial benar', 'HTTP 200, peran DOKTER', function () { [$s, $j] = http('POST', 'login', ['username' => 'd001', 'password' => 'simrad2026'], 'tc01'); return [$s === 200 && ($j['pengguna']['role'] ?? '') === 'DOKTER', "HTTP $s, peran " . ($j['pengguna']['role'] ?? '-')]; });
tc('TC02', 'Login dengan kata sandi salah', 'HTTP 401, kode LOGIN_GAGAL', function () { [$s, $j] = http('POST', 'login', ['username' => 'd001', 'password' => 'salah'], 'tc02'); return [$s === 401 && $j['error']['kode'] === 'LOGIN_GAGAL', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC03', 'Akses worklist tanpa login', 'HTTP 401, kode BELUM_LOGIN', function () { [$s, $j] = http('GET', 'orders', null, 'anonim'); return [$s === 401 && $j['error']['kode'] === 'BELUM_LOGIN', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC04', 'Dokter membuat order valid', 'HTTP 201, status ORDERED, nomor akses format ACCYYYYMMDDnnnnn', function () use ($D1, $pt, $prCT) { [$s, $j] = http('POST', 'orders.create', ['patient_id' => $pt, 'procedure_id' => $prCT, 'prioritas' => 'REGULER'], $D1); return [$s === 201 && $j['status'] === 'ORDERED' && preg_match('/^ACC\d{8}\d{5}$/', $j['accession_no']) === 1, "HTTP $s, {$j['status']}, {$j['accession_no']}"]; });
tc('TC05', 'Order dengan prioritas tidak valid', 'HTTP 422, PRIORITAS_TIDAK_VALID', function () use ($D1, $pt, $prCT) { [$s, $j] = http('POST', 'orders.create', ['patient_id' => $pt, 'procedure_id' => $prCT, 'prioritas' => 'SEGERA'], $D1); return [$s === 422 && $j['error']['kode'] === 'PRIORITAS_TIDAK_VALID', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC06', 'Order untuk pasien yang tidak ada', 'HTTP 404, PASIEN_TIDAK_ADA', function () use ($D1, $prCT) { [$s, $j] = http('POST', 'orders.create', ['patient_id' => 999999, 'procedure_id' => $prCT, 'prioritas' => 'REGULER'], $D1); return [$s === 404 && $j['error']['kode'] === 'PASIEN_TIDAK_ADA', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC07', 'Radiografer mencoba membuat order (hak akses)', 'HTTP 403, DILARANG', function () use ($R1, $pt, $prCT) { [$s, $j] = http('POST', 'orders.create', ['patient_id' => $pt, 'procedure_id' => $prCT, 'prioritas' => 'REGULER'], $R1); return [$s === 403 && $j['error']['kode'] === 'DILARANG', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC08', 'Memulai pemeriksaan sebelum dijadwalkan', 'HTTP 409, STATUS_TIDAK_VALID', function () use ($D1, $R1, $pt, $prCT, $mCT) { $o = buatOrder($D1, $pt, $prCT); [$s, $j] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mCT], $R1); return [$s === 409 && $j['error']['kode'] === 'STATUS_TIDAK_VALID', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });

$alurOrder = 0; $alurStudy = 0; $alurUid = '';
tc('TC09', 'Alur penuh: order, jadwal, mulai, selesai, laporan draft, laporan final', 'Status akhir REPORTED; UID DICOM berformat 2.25.* dan <= 64 karakter', function () use ($D1, $R1, $RAD, $pt, $prCT, $mCT, &$alurOrder, &$alurStudy, &$alurUid) {
    $alurOrder = buatOrder($D1, $pt, $prCT, 'CITO');
    http('POST', 'orders.schedule', ['order_id' => $alurOrder], $R1);
    [, $st] = http('POST', 'orders.start', ['order_id' => $alurOrder, 'modality_id' => $mCT], $R1);
    $alurStudy = (int)$st['study_id']; $alurUid = $st['study_instance_uid'];
    http('POST', 'studies.complete', ['study_id' => $alurStudy, 'jumlah_instance' => 5, 'kualitas_baik' => true, 'body_part' => 'KEPALA'], $R1);
    http('POST', 'reports.save', ['study_id' => $alurStudy, 'isi_temuan' => 'Tidak tampak lesi.', 'kesimpulan' => '', 'finalize' => false], $RAD);
    [$s, $j] = http('POST', 'reports.save', ['study_id' => $alurStudy, 'isi_temuan' => 'Tidak tampak lesi.', 'kesimpulan' => 'Normal.', 'finalize' => true], $RAD);
    [, $d] = http('GET', 'orders.detail', null, $RAD, ['id' => $alurOrder]);
    $uidOk = preg_match('/^2\.25\.\d+$/', $alurUid) === 1 && strlen($alurUid) <= 64;
    return [$s === 200 && $d['status'] === 'REPORTED' && $uidOk, "laporan HTTP $s, status {$d['status']}, UID $alurUid (" . strlen($alurUid) . ' karakter)'];
});
tc('TC10', 'Alat tidak sesuai jenis pemeriksaan (CT dikerjakan di alat USG)', 'HTTP 422, ALAT_TIDAK_SESUAI', function () use ($D1, $R1, $pt, $prCT, $mUS) { $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [$s, $j] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mUS], $R1); return [$s === 422 && $j['error']['kode'] === 'ALAT_TIDAK_SESUAI', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC11', 'Alat berstatus OFFLINE', 'HTTP 409, ALAT_OFFLINE', function () use ($D1, $R1, $pt, $prCT, $mCT, $pdo) { $pdo->exec("UPDATE modality SET status='OFFLINE' WHERE kode_alat='CT-1'"); $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [$s, $j] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mCT], $R1); $pdo->exec("UPDATE modality SET status='ONLINE' WHERE kode_alat='CT-1'"); return [$s === 409 && $j['error']['kode'] === 'ALAT_OFFLINE', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC12', 'Alat berstatus PERLU_CEK (USG)', 'HTTP 200 disertai peringatan', function () use ($D1, $R1, $pt, $prUS, $mUS) { $o = buatOrder($D1, $pt, $prUS); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [$s, $j] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mUS], $R1); return [$s === 200 && !empty($j['peringatan']), "HTTP $s, peringatan: " . ($j['peringatan'] ?? '-')]; });
tc('TC13', 'Citra tidak layak (decision D3): ulangi pengambilan', 'ulangi_pengambilan=true, order tetap IN_PROGRESS, tercatat IMAGE_REJECTED', function () use ($D1, $R1, $pt, $prCT, $mCT) { $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [, $st] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mCT], $R1); [$s, $j] = http('POST', 'studies.complete', ['study_id' => $st['study_id'], 'jumlah_instance' => 3, 'kualitas_baik' => false], $R1); [, $d] = http('GET', 'orders.detail', null, $R1, ['id' => $o]); $ev = array_column($d['jejak_audit'], 'jenis_event'); return [$s === 200 && !empty($j['ulangi_pengambilan']) && $d['status'] === 'IN_PROGRESS' && in_array('IMAGE_REJECTED', $ev, true), "status {$d['status']}, event " . implode(',', $ev)]; });
tc('TC14', 'Dokter mencoba menulis laporan (hak akses)', 'HTTP 403, DILARANG', function () use ($D1, &$alurStudy) { [$s, $j] = http('POST', 'reports.save', ['study_id' => $alurStudy, 'isi_temuan' => 'x', 'kesimpulan' => 'x', 'finalize' => true], $D1); return [$s === 403 && $j['error']['kode'] === 'DILARANG', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC15', 'Laporan final tanpa kesimpulan', 'HTTP 422, KESIMPULAN_WAJIB', function () use ($D1, $R1, $RAD, $pt, $prCT, $mCT) { $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [, $st] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mCT], $R1); http('POST', 'studies.complete', ['study_id' => $st['study_id'], 'jumlah_instance' => 2, 'kualitas_baik' => true], $R1); [$s, $j] = http('POST', 'reports.save', ['study_id' => $st['study_id'], 'isi_temuan' => 'x', 'kesimpulan' => '  ', 'finalize' => true], $RAD); return [$s === 422 && $j['error']['kode'] === 'KESIMPULAN_WAJIB', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC16', 'Laporan final tidak diubah; koreksi lewat addendum', 'Laporan tambahan berjenis ADDENDUM, laporan AWAL tetap FINAL, studi tetap REPORTED', function () use ($RAD, &$alurOrder, &$alurStudy) { [$s, $j] = http('POST', 'reports.save', ['study_id' => $alurStudy, 'isi_temuan' => 'Koreksi.', 'kesimpulan' => 'Addendum: normal.', 'finalize' => true], $RAD); [, $d] = http('GET', 'orders.detail', null, $RAD, ['id' => $alurOrder]); $jenis = array_column($d['laporan'], 'jenis'); $awalFinal = array_values(array_filter($d['laporan'], fn($l) => $l['jenis'] === 'AWAL'))[0]['status'] ?? ''; return [$s === 200 && $j['jenis'] === 'ADDENDUM' && $awalFinal === 'FINAL' && $d['study']['status'] === 'REPORTED', "jenis " . implode(',', $jenis) . ", AWAL $awalFinal, studi {$d['study']['status']}"]; });
tc('TC17', 'Tandai CITO: sekali berhasil, kedua kali ditolak, setelah REPORTED ditolak', 'HTTP 200, lalu 409 SUDAH_CITO, lalu 409 STATUS_TIDAK_VALID', function () use ($D1, $RAD, $pt, $prCT, &$alurOrder) { $o = buatOrder($D1, $pt, $prCT, 'REGULER'); [$s1] = http('POST', 'orders.cito', ['order_id' => $o], $RAD); [$s2, $j2] = http('POST', 'orders.cito', ['order_id' => $o], $RAD); [$s3, $j3] = http('POST', 'orders.cito', ['order_id' => $alurOrder], $RAD); return [$s1 === 200 && $s2 === 409 && $j2['error']['kode'] === 'SUDAH_CITO' && $s3 === 409, "HTTP $s1, $s2 ({$j2['error']['kode']}), $s3 ({$j3['error']['kode']})"]; });
tc('TC18', 'Worklist: order terbuka di atas order REPORTED; di antara order terbuka, CITO lebih dulu', 'Semua order terbuka sebelum REPORTED, dan semua CITO terbuka sebelum REGULER terbuka', function () use ($RAD) { [, $j] = http('GET', 'orders', null, $RAD); $d = $j['data']; $terbuka = array_values(array_filter(array_keys($d), fn($i) => $d[$i]['status'] !== 'REPORTED')); $rep = array_values(array_filter(array_keys($d), fn($i) => $d[$i]['status'] === 'REPORTED')); $okRep = !$terbuka || !$rep || max($terbuka) < min($rep); $citoT = array_values(array_filter($terbuka, fn($i) => $d[$i]['prioritas'] === 'CITO')); $regT = array_values(array_filter($terbuka, fn($i) => $d[$i]['prioritas'] === 'REGULER')); $okPri = !$citoT || !$regT || max($citoT) < min($regT); return [$okRep && $okPri && count($terbuka) > 0, 'urutan: ' . implode(', ', array_map(fn($x) => $x['prioritas'] . '/' . $x['status'], array_slice($d, 0, 6))) . '...']; });
tc('TC19', 'Dokter hanya melihat order miliknya', 'Dokter 2 tidak melihat order Dokter 1; detail order dokter lain HTTP 403', function () use ($D2, &$alurOrder) { [, $j] = http('GET', 'orders', null, $D2); [$s] = http('GET', 'orders.detail', null, $D2, ['id' => $alurOrder]); return [count($j['data']) === 0 && $s === 403, 'order terlihat: ' . count($j['data']) . ", detail HTTP $s"]; });
tc('TC20', 'Admin TI mencoba membuka worklist klinis (B4)', 'HTTP 403, DILARANG', function () use ($ADM) { [$s, $j] = http('GET', 'orders', null, $ADM); return [$s === 403 && $j['error']['kode'] === 'DILARANG', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC21', 'Injeksi SQL pada kolom pencarian pasien', 'Tidak ada kebocoran data atau tabel terhapus', function () use ($D1) { $awal = (int)q1('SELECT COUNT(*) FROM patient'); [$s1, $j1] = http('GET', 'patients', null, $D1, ['q' => "' OR '1'='1"]); [$s2] = http('GET', 'patients', null, $D1, ['q' => "x'; DROP TABLE patient;--"]); $n = (int)q1('SELECT COUNT(*) FROM patient'); return [$s1 === 200 && count($j1['data']) === 0 && $s2 === 200 && $n === $awal, "hasil injeksi " . count($j1['data']) . " baris, tabel patient tetap $n baris (sebelum: $awal)"]; });
tc('TC22', 'Jejak audit alur penuh lengkap dan berurutan', 'ORDER_CREATED, SCHEDULED, STUDY_STARTED, STUDY_COMPLETED, SENT_TO_PACS, REPORT_DRAFT_SAVED, REPORT_FINALIZED, ADDENDUM_ADDED', function () use ($RAD, &$alurOrder) { [, $d] = http('GET', 'orders.detail', null, $RAD, ['id' => $alurOrder]); $ev = array_column($d['jejak_audit'], 'jenis_event'); $ex = ['ORDER_CREATED', 'SCHEDULED', 'STUDY_STARTED', 'STUDY_COMPLETED', 'SENT_TO_PACS', 'REPORT_DRAFT_SAVED', 'REPORT_DRAFT_SAVED', 'REPORT_FINALIZED', 'ADDENDUM_ADDED']; return [$ev === $ex, implode(',', $ev)]; });
tc('TC23', 'Isi permintaan bukan JSON valid', 'HTTP 400, JSON_TIDAK_VALID', function () use ($D1) { [$s, $j] = http('POST', 'orders.create', null, $D1, [], '{bukan json'); return [$s === 400 && $j['error']['kode'] === 'JSON_TIDAK_VALID', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC24', 'ID bukan angka pada detail order', 'HTTP 422, INPUT_TIDAK_VALID', function () use ($RAD) { [$s, $j] = http('GET', 'orders.detail', null, $RAD, ['id' => 'abc']); return [$s === 422 && $j['error']['kode'] === 'INPUT_TIDAK_VALID', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });

// ================= UJI MODUL INTEGRASI (HL7 v2 dan FHIR) =================
/** Pecah pesan ER7 menjadi segmen; $f(segmen, n) mengambil field ke-n (MSH: field ke-1 adalah pemisah itu sendiri). */
function er7(string $raw): array { $o = []; foreach (explode("\r", rtrim($raw, "\r")) as $l) { $p = explode('|', $l); $o[] = $p; } return $o; }
function fld(array $seg, int $n): string { return $seg[0] === 'MSH' ? ($seg[$n - 1] ?? '') : ($seg[$n] ?? ''); }
$ordReg = buatOrder($D1, $pt, $prCT, 'REGULER');
$ordUS = buatOrder($D1, $pt2, $prUS, 'REGULER');

tc('TC25', 'HL7 ORM^O01: struktur segmen dan field kunci (order reguler CT)', 'Segmen MSH,PID,ORC,OBR; ORM^O01^ORM_O01, versi 2.5.1; ORC-1 NW, prioritas R; nomor akses di ORC-2/OBR-3; OBR-24 CT', function () use ($RAD, $ordReg, $pt) {
    [$s, $j] = http('GET', 'hl7.order', null, $RAD, ['order_id' => $ordReg]); if ($s !== 200) return [false, "HTTP $s"];
    $m = er7($j['er7']); $nama = array_column($m, 0); $acc = q1('SELECT accession_no FROM imaging_order WHERE order_id = ?', [$ordReg]); $rm = q1('SELECT no_rm FROM patient WHERE patient_id = ?', [$pt]);
    $ok = $nama === ['MSH', 'PID', 'ORC', 'OBR'] && fld($m[0], 9) === 'ORM^O01^ORM_O01' && fld($m[0], 12) === '2.5.1' && str_starts_with(fld($m[1], 3), $rm . '^^^SIMRAD^MR')
        && fld($m[2], 1) === 'NW' && fld($m[2], 7) === '^^^^^R' && fld($m[2], 2) === $acc . '^SIMRAD' && fld($m[3], 3) === $acc . '^SIMRAD' && fld($m[3], 24) === 'CT';
    return [$ok, 'segmen ' . implode(',', $nama) . '; MSH-9 ' . fld($m[0], 9) . '; ORC-7 ' . fld($m[2], 7) . '; OBR-24 ' . fld($m[3], 24)];
});
tc('TC26', 'HL7 ORM^O01: order CITO memakai prioritas S (STAT)', 'ORC-7 = ^^^^^S', function () use ($RAD, &$alurOrder) { [$s, $j] = http('GET', 'hl7.order', null, $RAD, ['order_id' => $alurOrder]); $m = er7($j['er7'] ?? ''); return [$s === 200 && fld($m[2], 7) === '^^^^^S', "HTTP $s, ORC-7 " . ($s === 200 ? fld($m[2], 7) : '-')]; });
tc('TC27', 'HL7: karakter khusus pada nama pasien di-escape dan tidak menggeser field', 'Nama berisi & | ^ ~ \\ tampil sebagai \\T\\ \\F\\ \\S\\ \\R\\ \\E\\; PID-8 tetap jenis kelamin', function () use ($D1, $RAD, $prCT, $pdo) {
    $asli = q1('SELECT nama FROM patient WHERE patient_id = 5'); $pdo->prepare('UPDATE patient SET nama = ? WHERE patient_id = 5')->execute(['O&Brien|Tes^X~Y\\Z Budi']);
    try { $o = buatOrder($D1, 5, $prCT, 'REGULER'); [$s, $j] = http('GET', 'hl7.order', null, $RAD, ['order_id' => $o]); }
    finally { $pdo->prepare('UPDATE patient SET nama = ? WHERE patient_id = 5')->execute([$asli]); }
    $m = er7($j['er7'] ?? ''); $pid = $m[1] ?? []; $sex = q1('SELECT jenis_kelamin FROM patient WHERE patient_id = 5'); $exp = 'Budi^O\\T\\Brien\\F\\Tes\\S\\X\\R\\Y\\E\\Z';
    return [$s === 200 && fld($pid, 5) === $exp && fld($pid, 8) === ($sex === 'L' ? 'M' : 'F'), 'PID-5 = ' . fld($pid, 5) . '; PID-8 = ' . fld($pid, 8)];
});
tc('TC28', 'HL7 ORU^R01 untuk order REPORTED (laporan awal + addendum)', 'ORU^R01^ORU_R01; OBR-25 = F; 4 segmen OBX bernilai F berisi temuan dan kesimpulan', function () use ($RAD, &$alurOrder) {
    [$s, $j] = http('GET', 'hl7.hasil', null, $RAD, ['order_id' => $alurOrder]); if ($s !== 200) return [false, "HTTP $s"];
    $m = er7($j['er7']); $obx = array_values(array_filter($m, fn($x) => $x[0] === 'OBX')); $obr = array_values(array_filter($m, fn($x) => $x[0] === 'OBR'))[0];
    $nilai = array_map(fn($x) => fld($x, 5), $obx); $stat = array_unique(array_map(fn($x) => fld($x, 11), $obx));
    $ok = fld($m[0], 9) === 'ORU^R01^ORU_R01' && fld($obr, 25) === 'F' && count($obx) === 4 && $stat === ['F'] && $nilai === ['Tidak tampak lesi.', 'Normal.', 'Koreksi.', 'Addendum: normal.'];
    return [$ok, 'OBX=' . count($obx) . '; nilai: ' . implode(' / ', $nilai)];
});
tc('TC29', 'HL7 ORU^R01 untuk order yang belum punya laporan final', 'HTTP 409, LAPORAN_BELUM_FINAL', function () use ($RAD, $ordReg) { [$s, $j] = http('GET', 'hl7.hasil', null, $RAD, ['order_id' => $ordReg]); return [$s === 409 && $j['error']['kode'] === 'LAPORAN_BELUM_FINAL', "HTTP $s, " . ($j['error']['kode'] ?? '-')]; });
tc('TC30', 'FHIR Bundle order REPORTED: sumber daya, prioritas, UID DICOM, status laporan', 'transaction; Patient, ServiceRequest(stat,completed), ImagingStudy (urn:oid, 5 instance), DiagnosticReport final dan appended', function () use ($RAD, &$alurOrder, &$alurUid) {
    [$s, $b] = http('GET', 'fhir.bundle', null, $RAD, ['order_id' => $alurOrder]); if ($s !== 200) return [false, "HTTP $s"];
    $r = array_column($b['entry'], 'resource'); $tipe = array_column($r, 'resourceType'); $sr = $r[1]; $is = $r[2]; $dr = array_values(array_filter($r, fn($x) => $x['resourceType'] === 'DiagnosticReport'));
    $ok = $b['type'] === 'transaction' && $tipe === ['Patient', 'ServiceRequest', 'ImagingStudy', 'DiagnosticReport', 'DiagnosticReport'] && $sr['priority'] === 'stat' && $sr['status'] === 'completed'
        && $is['identifier'][0]['value'] === 'urn:oid:' . $alurUid && $is['numberOfInstances'] === 5 && array_column($dr, 'status') === ['final', 'appended'];
    return [$ok, implode(',', $tipe) . '; SR ' . $sr['priority'] . '/' . $sr['status'] . '; DR ' . implode(',', array_column($dr, 'status'))];
});
tc('TC31', 'FHIR Bundle: semua referensi urn:uuid menunjuk entri yang ada; pengiriman ulang aman (ifNoneExist)', 'Tidak ada referensi menggantung; setiap entri memiliki request.ifNoneExist', function () use ($RAD, &$alurOrder) {
    [, $b] = http('GET', 'fhir.bundle', null, $RAD, ['order_id' => $alurOrder]); $full = array_column($b['entry'], 'fullUrl'); $ref = [];
    $jalan = function ($x) use (&$jalan, &$ref) { foreach ($x as $k => $v) { if (is_array($v)) $jalan($v); elseif ($k === 'reference') $ref[] = $v; } }; $jalan(array_column($b['entry'], 'resource'));
    $hilang = array_diff($ref, $full); $tanpaCond = array_filter($b['entry'], fn($e) => empty($e['request']['ifNoneExist']));
    return [count($ref) > 0 && !$hilang && !$tanpaCond, count($ref) . ' referensi, ' . count($hilang) . ' menggantung; entri tanpa ifNoneExist: ' . count($tanpaCond)];
});
tc('TC32', 'FHIR Bundle order yang baru dibuat (belum diperiksa)', 'Hanya Patient dan ServiceRequest (active, routine); tanpa ImagingStudy dan DiagnosticReport', function () use ($RAD, $ordReg) { [$s, $b] = http('GET', 'fhir.bundle', null, $RAD, ['order_id' => $ordReg]); $r = array_column($b['entry'] ?? [], 'resource'); $tipe = array_column($r, 'resourceType'); return [$s === 200 && $tipe === ['Patient', 'ServiceRequest'] && $r[1]['status'] === 'active' && $r[1]['priority'] === 'routine', implode(',', $tipe) . '; ' . ($r[1]['status'] ?? '-') . '/' . ($r[1]['priority'] ?? '-')]; });
tc('TC33', 'Hak akses pesan integrasi: Admin TI dan dokter lain ditolak (B4)', 'HTTP 403 untuk Admin TI (HL7 dan FHIR) dan Dokter 2 pada order Dokter 1', function () use ($ADM, $D2, &$alurOrder) { [$a] = http('GET', 'hl7.order', null, $ADM, ['order_id' => $alurOrder]); [$b] = http('GET', 'fhir.bundle', null, $ADM, ['order_id' => $alurOrder]); [$c] = http('GET', 'hl7.hasil', null, $D2, ['order_id' => $alurOrder]); [$d] = http('GET', 'fhir.bundle', null, $D2, ['order_id' => $alurOrder]); return [$a === 403 && $b === 403 && $c === 403 && $d === 403, "Admin: $a/$b; Dokter 2: $c/$d"]; });
tc('TC34', 'Pesan integrasi: order tidak ada dan ID bukan angka', 'HTTP 404 (ORDER_TIDAK_ADA) dan HTTP 422 (INPUT_TIDAK_VALID)', function () use ($RAD) { [$a, $ja] = http('GET', 'hl7.order', null, $RAD, ['order_id' => 999999]); [$b, $jb] = http('GET', 'fhir.bundle', null, $RAD, ['order_id' => 'x']); return [$a === 404 && $ja['error']['kode'] === 'ORDER_TIDAK_ADA' && $b === 422, "HTTP $a, $b"]; });

// ================= REGISTRASI PASIEN, HL7 MASUK, MWL, PACS SIMULASI =================
function qa(string $sql, array $p = []): array { $st = Db::pdo()->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function adt(string $rm, ?string $nik, string $fam, string $giv, string $dob, string $sex, string $ctrl): string {
    return "MSH|^~\\&|HIS|RSUD-BINA-SEHAT|SIMRAD|RSUD-BINA-SEHAT|20261120120000||ADT^A04^ADT_A01|$ctrl|P|2.5.1\rPID|1||$rm^^^SIMRAD^MR" . ($nik ? "~$nik^^^NIK^NI" : '') . "||$fam^$giv||$dob|$sex\r";
}
function orm(string $rm, string $kode, string $prio, string $ctrl): string {
    return "MSH|^~\\&|HIS|RSUD-BINA-SEHAT|SIMRAD|RSUD-BINA-SEHAT|20261120120100||ORM^O01^ORM_O01|$ctrl|P|2.5.1\rPID|1||$rm^^^SIMRAD^MR||X^Y||19900101|M\rORC|NW|HIS-$ctrl|||||^^^^^$prio\rOBR|1|HIS-$ctrl||$kode^Pemeriksaan^SIMRAD\r";
}
$sufiks = (string)random_int(100000, 999999);   // agar uji dapat diulang tanpa bentrok data sisa
tc('TC35', 'Registrasi pasien lewat API: data valid, nomor RM otomatis', 'HTTP 201; nomor RM berformat P + angka; baris di basis data sama dengan masukan', function () use ($D1, $sufiks) {
    $nik = '3578' . $sufiks . '000001'; [$s, $j] = http('POST', 'patients.create', ['nama' => 'Rina Wulandari', 'tanggal_lahir' => '1992-07-21', 'jenis_kelamin' => 'P', 'nik' => $nik], $D1);
    $r = $s === 201 ? qa('SELECT * FROM patient WHERE patient_id = ?', [$j['patient_id']])[0] : [];
    $ok = $s === 201 && preg_match('/^P\d{4,}$/', $j['no_rm']) === 1 && ($r['nama'] ?? '') === 'Rina Wulandari' && ($r['tanggal_lahir'] ?? '') === '1992-07-21' && ($r['jenis_kelamin'] ?? '') === 'P' && ($r['nik'] ?? '') === $nik;
    return [$ok, "HTTP $s, no. RM " . ($j['no_rm'] ?? '-')];
});
tc('TC36', 'Registrasi pasien: validasi masukan', '422 untuk tanggal masa depan, tanggal tidak ada (30 Feb), jenis kelamin salah, NIK 15 digit, nama kosong', function () use ($D1) {
    $dasar = ['nama' => 'Uji', 'tanggal_lahir' => '1990-01-01', 'jenis_kelamin' => 'L']; $kode = [];
    foreach ([['tanggal_lahir' => '2999-01-01'], ['tanggal_lahir' => '2026-02-30'], ['jenis_kelamin' => 'X'], ['nik' => '123456789012345'], ['nama' => '   ']] as $u) { [$s, $j] = http('POST', 'patients.create', array_merge($dasar, $u), $D1); $kode[] = $s . ' ' . ($j['error']['kode'] ?? '-'); }
    return [count(array_filter($kode, fn($k) => str_starts_with($k, '422 '))) === 5, implode('; ', $kode)];
});
tc('TC37', 'Registrasi pasien: duplikat NIK dan duplikat nomor RM', 'HTTP 409 NIK_SUDAH_TERDAFTAR dan HTTP 409 NO_RM_SUDAH_ADA', function () use ($D1, $sufiks) {
    $nik = '3578' . $sufiks . '000001'; $b = ['nama' => 'Dobel', 'tanggal_lahir' => '1990-01-01', 'jenis_kelamin' => 'L'];
    [$a, $ja] = http('POST', 'patients.create', $b + ['nik' => $nik], $D1); [$c, $jc] = http('POST', 'patients.create', $b + ['no_rm' => 'P0001'], $D1);
    return [$a === 409 && $ja['error']['kode'] === 'NIK_SUDAH_TERDAFTAR' && $c === 409 && $jc['error']['kode'] === 'NO_RM_SUDAH_ADA', "HTTP $a " . ($ja['error']['kode'] ?? '-') . "; HTTP $c " . ($jc['error']['kode'] ?? '-')];
});
tc('TC38', 'Registrasi pasien: hak akses (dokter dan radiografer boleh; radiolog dan Admin TI ditolak)', 'Radiografer HTTP 201; Radiolog dan Admin TI HTTP 403', function () use ($R1, $RAD, $ADM, $sufiks) {
    $b = ['nama' => 'Hak Akses', 'tanggal_lahir' => '1990-01-01', 'jenis_kelamin' => 'L']; [$a, $ja] = http('POST', 'patients.create', $b + ['nik' => '3579' . $sufiks . '000002'], $R1); [$c] = http('POST', 'patients.create', $b, $RAD); [$d] = http('POST', 'patients.create', $b, $ADM);
    return [$a === 201 && $c === 403 && $d === 403, "Radiografer $a, Radiolog $c, Admin TI $d"];
});
$rmHis = 'P9' . substr($sufiks, 0, 3); $nikHis = '3580' . $sufiks . '000003';
tc('TC39', 'HL7 ADT^A04 masuk: registrasi pasien dari HIS dan ACK AA; pengiriman ulang tidak menggandakan', 'ACK AA; data pasien sama dengan isi pesan; kirim ulang tetap AA dan jumlah baris tidak bertambah', function () use ($D1, $rmHis, $nikHis) {
    [$s, $j] = http('POST', 'hl7.terima', ['er7' => adt($rmHis, $nikHis, 'Nurhaliza', 'Siti', '19850303', 'F', 'MSG-A1')], $D1);
    $r = qa('SELECT * FROM patient WHERE no_rm = ?', [$rmHis])[0] ?? [];
    $msa = explode('|', $j['segmen'][1] ?? '');
    [$s2, $j2] = http('POST', 'hl7.terima', ['er7' => adt($rmHis, $nikHis, 'Nurhaliza', 'Siti', '19850303', 'F', 'MSG-A1')], $D1); $n = (int)q1('SELECT COUNT(*) FROM patient WHERE no_rm = ?', [$rmHis]);
    $ok = ($j['hasil'] ?? '') === 'AA' && ($msa[1] ?? '') === 'AA' && ($msa[2] ?? '') === 'MSG-A1' && ($r['nama'] ?? '') === 'Siti Nurhaliza' && ($r['tanggal_lahir'] ?? '') === '1985-03-03' && ($r['jenis_kelamin'] ?? '') === 'P' && ($r['nik'] ?? '') === $nikHis && ($j2['hasil'] ?? '') === 'AA' && $n === 1;
    return [$ok, 'ACK ' . ($j['segmen'][1] ?? '-') . '; kirim ulang ' . ($j2['hasil'] ?? '-') . ", baris=$n"];
});
tc('TC40', 'HL7 ORM^O01 masuk: order dari HIS (CITO lewat ORC-7) dan ACK AA', 'ACK AA berisi nomor akses; order berprioritas CITO, pasien dan pemeriksaan sesuai pesan', function () use ($D1, $rmHis) {
    [$s, $j] = http('POST', 'hl7.terima', ['er7' => orm($rmHis, 'CT-ABD', 'S', 'MSG-O1')], $D1);
    $o = qa('SELECT o.*, p.no_rm, pr.kode FROM imaging_order o JOIN patient p USING (patient_id) JOIN `procedure` pr USING (procedure_id) WHERE o.accession_no = ?', [$j['data']['accession_no'] ?? ''])[0] ?? [];
    $ok = ($j['hasil'] ?? '') === 'AA' && str_contains($j['segmen'][1] ?? '', $j['data']['accession_no'] ?? '#') && ($o['prioritas'] ?? '') === 'CITO' && ($o['no_rm'] ?? '') === $rmHis && ($o['kode'] ?? '') === 'CT-ABD' && ($o['status'] ?? '') === 'ORDERED';
    return [$ok, ($j['segmen'][1] ?? '-') . '; prioritas ' . ($o['prioritas'] ?? '-')];
});
tc('TC41', 'HL7 masuk: pesan salah dibalas ACK AE/AR; hak akses dijaga', 'Pasien tak dikenal AE; kode pemeriksaan tak dikenal AE; ORU AR; ORC-1 bukan NW AR; tanpa MSH HTTP 422; Radiolog HTTP 403', function () use ($D1, $RAD, $rmHis) {
    $h = fn($er7) => http('POST', 'hl7.terima', ['er7' => $er7], $D1)[1]['hasil'] ?? '-';
    $a = $h(orm('P0000X', 'CT-ABD', 'R', 'MSG-E1')); $b = $h(orm($rmHis, 'TIDAK-ADA', 'R', 'MSG-E2'));
    $c = $h("MSH|^~\\&|HIS|X|SIMRAD|X|20261120||ORU^R01^ORU_R01|MSG-E3|P|2.5.1\rPID|1||$rmHis^^^SIMRAD^MR\r");
    $d = $h(str_replace('ORC|NW', 'ORC|CA', orm($rmHis, 'CT-ABD', 'R', 'MSG-E4')));
    [$e] = http('POST', 'hl7.terima', ['er7' => 'bukan pesan hl7'], $D1); [$f] = http('POST', 'hl7.terima', ['er7' => orm($rmHis, 'CT-ABD', 'R', 'MSG-E5')], $RAD);
    return [$a === 'AE' && $b === 'AE' && $c === 'AR' && $d === 'AR' && $e === 422 && $f === 403, "$a, $b, $c, $d, HTTP $e, HTTP $f"];
});
$jalur = [];   // dipakai bersama TC42 dan TC43
tc('TC42', 'Simulasi Modality Worklist (C-FIND): hanya order SCHEDULED, atribut DICOM sesuai data order', 'Order ORDERED tidak muncul; setelah dijadwalkan muncul dengan PatientID, PatientName (Keluarga^Nama), AccessionNumber, Modality CT, AET CT_ROOM1; filter modality dan hak akses benar', function () use ($D1, $R1, $RAD, $rmHis, &$jalur) {
    $acc = qa("SELECT o.accession_no, o.order_id FROM imaging_order o JOIN patient p USING (patient_id) WHERE p.no_rm = ? AND o.prioritas = 'CITO' ORDER BY o.order_id DESC LIMIT 1", [$rmHis])[0];
    [, $sebelum] = http('GET', 'mwl.daftar', null, $R1); $adaSebelum = in_array($acc['accession_no'], array_column($sebelum['data'] ?? [], 'accession_no'), true);
    http('POST', 'orders.schedule', ['order_id' => (int)$acc['order_id']], $R1);
    [, $m] = http('GET', 'mwl.daftar', null, $R1); $e = null; foreach ($m['data'] as $x) if ($x['accession_no'] === $acc['accession_no']) $e = $x['dicom'];
    $sps = $e['(0040,0100) ScheduledProcedureStepSequence'][0] ?? [];
    [, $mr] = http('GET', 'mwl.daftar', null, $R1, ['modality' => 'MR']); $adaMR = in_array($acc['accession_no'], array_column($mr['data'] ?? [], 'accession_no'), true);
    [$sRad] = http('GET', 'mwl.daftar', null, $RAD); [$sBad] = http('GET', 'mwl.daftar', null, $R1, ['modality' => 'XX']);
    $ok = !$adaSebelum && $e !== null && ($e['(0010,0020) PatientID'] ?? '') === $rmHis && ($e['(0010,0010) PatientName'] ?? '') === 'Nurhaliza^Siti' && ($e['(0008,0050) AccessionNumber'] ?? '') === $acc['accession_no']
        && ($e['(0040,1001) RequestedProcedureID'] ?? '') === 'CT-ABD' && ($sps['(0008,0060) Modality'] ?? '') === 'CT' && ($sps['(0040,0001) ScheduledStationAETitle'] ?? '') === 'CT_ROOM1' && !$adaMR && $sRad === 403 && $sBad === 422;
    $jalur = $acc;
    return [$ok, 'sebelum dijadwalkan ada=' . var_export($adaSebelum, true) . '; PatientName ' . ($e['(0010,0010) PatientName'] ?? '-') . '; AET ' . ($sps['(0040,0001) ScheduledStationAETitle'] ?? '-') . "; filter MR ada=" . var_export($adaMR, true) . "; Radiolog $sRad; modality XX $sBad"];
});
tc('TC43', 'INTEGRASI HIS -> RIS -> PACS tanpa data hilang (ADT, ORM, MWL, akuisisi 7 citra, C-STORE simulasi, laporan, ORU)', 'Setiap atribut pasien dan setiap UID instance di PACS sama dengan di RIS (7 dari 7, tanpa selisih); ORU memuat nomor akses, nomor RM, dan kesimpulan', function () use ($R1, $RAD, $rmHis, $nikHis, &$jalur) {
    $oid = (int)$jalur['order_id']; $acc = $jalur['accession_no'];
    [, $st] = http('POST', 'orders.start', ['order_id' => $oid, 'modality_id' => (int)q1("SELECT modality_id FROM modality WHERE kode_alat = 'CT-1'")], $R1);
    [$s, $c] = http('POST', 'studies.complete', ['study_id' => $st['study_id'], 'jumlah_instance' => 7, 'kualitas_baik' => true, 'body_part' => 'ABDOMEN'], $R1);
    $ris = array_column(qa('SELECT i.sop_instance_uid FROM instance i JOIN series se USING (series_id) WHERE se.study_id = ?', [$st['study_id']]), 'sop_instance_uid'); sort($ris);
    $pacs = qa('SELECT * FROM pacs_sim_instance WHERE study_instance_uid = ?', [$st['study_instance_uid']]); $uidPacs = array_column($pacs, 'sop_instance_uid'); sort($uidPacs);
    $pasien = qa('SELECT nama, no_rm FROM patient WHERE no_rm = ?', [$rmHis])[0];
    $atributOk = count($pacs) === 7 && count(array_unique(array_column($pacs, 'accession_no'))) === 1 && $pacs[0]['accession_no'] === $acc && $pacs[0]['patient_id'] === $rmHis && $pacs[0]['patient_name'] === $pasien['nama'] && $pacs[0]['modality'] === 'CT';
    http('POST', 'reports.save', ['study_id' => $st['study_id'], 'isi_temuan' => 'Tidak tampak kelainan.', 'kesimpulan' => 'Abdomen normal.', 'finalize' => true], $RAD);
    [, $oru] = http('GET', 'hl7.hasil', null, $RAD, ['order_id' => $oid]); $teks = $oru['er7'] ?? '';
    $evt = array_column(qa('SELECT jenis_event FROM workflow_event WHERE order_id = ? ORDER BY event_id', [$oid]), 'jenis_event');
    $ok = $s === 200 && ($c['pacs'] ?? '') === 'terkirim' && $ris === $uidPacs && count($ris) === 7 && $atributOk && in_array('SENT_TO_PACS', $evt, true) && str_contains($teks, $acc) && str_contains($teks, $rmHis) && str_contains($teks, 'Abdomen normal.');
    return [$ok, 'instance RIS=' . count($ris) . ', PACS=' . count($uidPacs) . ', selisih=' . count(array_diff($ris, $uidPacs)) . '; atribut sama=' . var_export($atributOk, true) . '; ORU memuat acc/RM/kesimpulan=' . var_export(str_contains($teks, $acc) && str_contains($teks, $rmHis) && str_contains($teks, 'Abdomen normal.'), true)];
});
tc('TC44', 'PACS offline: studi tidak hilang, kegagalan dicatat, kirim ulang berhasil dan idempoten', 'complete tetap berhasil (pacs=gagal), PACS 0 instance, event PACS_SEND_FAILED; setelah online kirim ulang menghasilkan 4 dari 4 instance; kirim ulang kedua tetap 4', function () use ($D1, $R1, $ADM, $pt, $prCT, $mCT) {
    http('POST', 'pacs.set', ['online' => false], $ADM);
    try {
        $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [, $st] = http('POST', 'orders.start', ['order_id' => $o, 'modality_id' => $mCT], $R1);
        [$s, $c] = http('POST', 'studies.complete', ['study_id' => $st['study_id'], 'jumlah_instance' => 4, 'kualitas_baik' => true], $R1);
        $nPacsOff = (int)q1('SELECT COUNT(*) FROM pacs_sim_instance WHERE study_instance_uid = ?', [$st['study_instance_uid']]); $stat = q1('SELECT status FROM study WHERE study_id = ?', [$st['study_id']]);
        $evOff = array_column(qa('SELECT jenis_event FROM workflow_event WHERE order_id = ? ORDER BY event_id', [$o]), 'jenis_event');
    } finally { http('POST', 'pacs.set', ['online' => true], $ADM); }
    [$s2, $k] = http('POST', 'studies.kirim_ulang', ['study_id' => $st['study_id']], $R1); $n1 = (int)q1('SELECT COUNT(*) FROM pacs_sim_instance WHERE study_instance_uid = ?', [$st['study_instance_uid']]);
    [$s3, $k2] = http('POST', 'studies.kirim_ulang', ['study_id' => $st['study_id']], $R1); $n2 = (int)q1('SELECT COUNT(*) FROM pacs_sim_instance WHERE study_instance_uid = ?', [$st['study_instance_uid']]);
    $ok = $s === 200 && ($c['pacs'] ?? '') === 'gagal' && $stat === 'COMPLETED' && $nPacsOff === 0 && in_array('PACS_SEND_FAILED', $evOff, true) && $s2 === 200 && ($k['pacs'] ?? '') === 'terkirim' && $n1 === 4 && $n2 === 4 && ($k2['pacs'] ?? '') === 'terkirim';
    return [$ok, "saat offline: pacs=" . ($c['pacs'] ?? '-') . ", studi=$stat, PACS=$nPacsOff; sesudah kirim ulang: PACS=$n1; kirim ulang kedua: PACS=$n2"];
});
tc('TC45', 'Hak akses dan status pada PACS simulasi', 'Dokter dan Radiolog tidak boleh mengubah status PACS (403); kirim ulang oleh dokter 403; studi belum selesai ditolak 409', function () use ($D1, $RAD, $R1, $pt, $prCT, $mCT) {
    [$a] = http('POST', 'pacs.set', ['online' => false], $D1); [$b] = http('POST', 'pacs.set', ['online' => false], $RAD); [, $on] = http('GET', 'pacs.status', null, $RAD);
    $o = buatOrder($D1, $pt, $prCT); http('POST', 'orders.schedule', ['order_id' => $o], $R1); [, $st] = http('POST', 'orders.start', ['order_id' => $mCT ? $o : $o, 'modality_id' => $mCT], $R1);
    [$c] = http('POST', 'studies.kirim_ulang', ['study_id' => $st['study_id']], $D1); [$d, $jd] = http('POST', 'studies.kirim_ulang', ['study_id' => $st['study_id']], $R1);
    return [$a === 403 && $b === 403 && ($on['online'] ?? null) === true && $c === 403 && $d === 409 && $jd['error']['kode'] === 'STATUS_TIDAK_VALID', "Dokter $a, Radiolog $b, status online=" . var_export($on['online'] ?? null, true) . ", kirim ulang dokter $c, studi belum selesai $d"];
});
tc('TC46', 'Detail order memuat perbandingan instance RIS dan PACS', 'di_ris = di_pacs = 7 dan lengkap = true untuk alur TC43', function () use ($RAD, &$jalur) { [$s, $d] = http('GET', 'orders.detail', null, $RAD, ['id' => (int)$jalur['order_id']]); $p = $d['pacs'] ?? []; return [$s === 200 && ($p['di_ris'] ?? 0) === 7 && ($p['di_pacs'] ?? 0) === 7 && ($p['lengkap'] ?? false) === true, 'RIS=' . ($p['di_ris'] ?? '-') . ', PACS=' . ($p['di_pacs'] ?? '-') . ', lengkap=' . var_export($p['lengkap'] ?? null, true)]; });

// ================= UJI INTEGRASI / TAT (in-process, jam terkunci) =================
$pdo->exec('SET FOREIGN_KEY_CHECKS=0'); foreach (['workflow_event', 'report', 'instance', 'series', 'study', 'imaging_order'] as $t) $pdo->exec("TRUNCATE TABLE $t"); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$aD = aktor('d001'); $aR = aktor('r001'); $aL = aktor('rad01');
function jalankan(array $aD, array $aR, array $aL, int $pt, int $pr, int $m, string $prio, array $menit): int {
    // $menit = [order, jadwal, mulai, selesai, final] sebagai jam "HH:MM" pada 2026-11-18
    $t = fn(string $x) => "2026-11-18 $x:00";
    Clock::$fixed = $t($menit[0]); $o = Workflow::createOrder($aD, $pt, $pr, $prio);
    Clock::$fixed = $t($menit[1]); Workflow::schedule($aR, $o['order_id']);
    Clock::$fixed = $t($menit[2]); $s = Workflow::start($aR, $o['order_id'], $m);
    Clock::$fixed = $t($menit[3]); Workflow::complete($aR, $s['study_id'], 4, true, 'KEPALA');
    Clock::$fixed = $t($menit[4]); Workflow::saveReport($aL, $s['study_id'], 'temuan', 'kesimpulan', true);
    return $o['order_id'];
}
jalankan($aD, $aR, $aL, $pt, $prCT, $mCT, 'CITO', ['10:00', '10:05', '10:15', '10:35', '10:50']);      // TAT 50 menit (dalam target)
jalankan($aD, $aR, $aL, $pt2, $prCT, $mCT, 'CITO', ['11:00', '11:10', '11:20', '11:50', '12:10']);    // TAT 70 menit (melewati target)
jalankan($aD, $aR, $aL, $pt, $prCT, $mCT, 'REGULER', ['09:00', '09:30', '09:45', '10:15', '11:00']);  // TAT 120 menit (tepat di batas: tidak dihitung dalam target)
jalankan($aD, $aR, $aL, $pt2, $prCT, $mCT, 'REGULER', ['09:00', '09:10', '09:20', '09:50', '10:30']); // TAT 90 menit (dalam target)
Clock::$fixed = '2026-11-18 10:00:00'; Workflow::createOrder($aD, $pt, $prCT, 'CITO');                  // terbuka sejak 10:00
Clock::$fixed = '2026-11-18 12:30:00';
$m = Metrics::summary();
tc('IT01', 'TAT CITO dari 2 order selesai (50 dan 70 menit)', 'rata-rata 60,0 menit; maks 70,0; dalam target 50%', fn() => [$m['tat']['CITO']['rata_menit'] == 60.0 && $m['tat']['CITO']['maks_menit'] == 70.0 && $m['tat']['CITO']['persen_dalam_target'] == 50.0, json_encode($m['tat']['CITO'])]);
tc('IT02', 'TAT reguler dari 2 order selesai (120 dan 90 menit); batas 120 tidak dihitung dalam target', 'rata-rata 105,0 menit; dalam target 50%', fn() => [$m['tat']['REGULER']['rata_menit'] == 105.0 && $m['tat']['REGULER']['persen_dalam_target'] == 50.0, json_encode($m['tat']['REGULER'])]);
tc('IT03', 'Rata-rata per tahap (tunggu, pemeriksaan, pembacaan) dari 4 order', 'tunggu 25,0; pemeriksaan 27,5; pembacaan 30,0 menit (hitung manual: 100/4, 110/4, 120/4)', function () use ($m) { $t = $m['tahap_rata_rata']; return [$t['rata_tunggu_menit'] == 25.0 && $t['rata_pemeriksaan_menit'] == 27.5 && $t['rata_pembacaan_menit'] == 30.0, json_encode($t)]; });
tc('IT04', 'Order CITO terbuka sejak 10:00 dihitung melewati target pada 12:30; status per tahap benar', '1 CITO terbuka lewat target; REPORTED=4, ORDERED=1', fn() => [$m['terbuka_lewat_target']['CITO'] === 1 && $m['per_status']['REPORTED'] === 4 && $m['per_status']['ORDERED'] === 1, json_encode(['lewat' => $m['terbuka_lewat_target'], 'status' => $m['per_status']])]);
tc('IT05', 'Angka desimal JSON tidak bergantung php.ini (serialize_precision tinggi seperti di XAMPP)', 'json_encode([105.6]) = [105.6] walau PHP dijalankan dengan serialize_precision=50', function () {
    $kode = 'require ' . var_export(__DIR__ . '/../src/bootstrap.php', true) . '; echo json_encode([105.6]);';
    $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d serialize_precision=50 -r ' . escapeshellarg($kode)));
    return [$out === '[105.6]', 'keluaran: ' . $out];
});
Clock::$fixed = null;

// ---------- Ringkasan + berkas hasil ----------
$lulus = count(array_filter($hasil, fn($h) => $h[4] === 'LULUS')); $total = count($hasil);
echo "\nRINGKASAN: $lulus dari $total kasus uji lulus\n";
$md = "# Hasil uji SIMRAD\n\nDijalankan: " . date('Y-m-d H:i:s') . " (WIB) oleh tests/run_tests.php. Ringkasan: **$lulus dari $total lulus**.\n\n| ID | Skenario | Hasil diharapkan | Hasil aktual | Status |\n|---|---|---|---|---|\n";
foreach ($hasil as $h) $md .= '| ' . implode(' | ', array_map(fn($c) => str_replace('|', '/', (string)$c), $h)) . " |\n";
@mkdir(__DIR__ . '/../docs', 0777, true);
file_put_contents(__DIR__ . '/../docs/hasil_uji.md', $md);
exit($lulus === $total ? 0 : 1);
