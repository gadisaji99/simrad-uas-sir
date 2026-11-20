<?php
declare(strict_types=1);

/**
 * Modul integrasi SIMRAD (Deliverable A: HL7 v2, DICOM, FHIR; Deliverable D).
 *  - Hl7: pesan HL7 v2.5.1 ORM^O01 (order, RIS ke PACS/modalitas) dan ORU^R01 (hasil, RIS ke HIS).
 *  - Fhir: Bundle transaksi FHIR R4 (Patient, ServiceRequest, ImagingStudy, DiagnosticReport) berbasis
 *    conditional create (ifNoneExist) agar pengiriman ulang tidak membuat duplikat. Pelajaran dari A4:
 *    permintaan identik yang dikirim ulang ke server sempat ditolak (HTTP 412).
 * Catatan batas: sistem identitas memakai URN lokal (urn:simrad:...), belum dipetakan ke SATUSEHAT.
 * Citra pada prototipe disimulasikan; SOP Class pada ImagingStudy bersifat nominal menurut jenis alat.
 */
final class IntegrationData {
    public static function order(int $id): array {
        $st = Db::pdo()->prepare("SELECT o.*, p.no_rm, p.nik, p.nama AS pasien, p.tanggal_lahir, p.jenis_kelamin,
                pr.kode AS kode_pemeriksaan, pr.nama_pemeriksaan, pr.jenis_modalitas, u.username AS pengirim_username, u.nama AS pengirim
            FROM imaging_order o JOIN patient p ON p.patient_id = o.patient_id
            JOIN `procedure` pr ON pr.procedure_id = o.procedure_id JOIN app_user u ON u.user_id = o.requester_id WHERE o.order_id = ?");
        $st->execute([$id]); $o = $st->fetch();
        if (!$o) throw new ApiError(404, 'ORDER_TIDAK_ADA', 'Order tidak ditemukan.');
        return $o;
    }
    public static function study(int $orderId): ?array {
        $st = Db::pdo()->prepare('SELECT * FROM study WHERE order_id = ?'); $st->execute([$orderId]); return $st->fetch() ?: null;
    }
    public static function series(int $studyId): array {
        $st = Db::pdo()->prepare('SELECT * FROM series WHERE study_id = ? ORDER BY series_number'); $st->execute([$studyId]); $rows = $st->fetchAll();
        foreach ($rows as &$s) { $i = Db::pdo()->prepare('SELECT sop_instance_uid, instance_number FROM instance WHERE series_id = ? ORDER BY instance_number'); $i->execute([$s['series_id']]); $s['instances'] = $i->fetchAll(); }
        return $rows;
    }
    public static function reports(int $studyId): array {
        $st = Db::pdo()->prepare('SELECT r.*, a.nama AS penulis FROM report r JOIN app_user a ON a.user_id = r.author_id WHERE r.study_id = ? ORDER BY r.report_id'); $st->execute([$studyId]); return $st->fetchAll();
    }
}

final class Hl7 {
    /** Escape karakter khusus HL7 v2 (| ^ ~ \ &) dan ganti baris baru dengan \.br\ (urutan penting: backslash dahulu). */
    public static function esc(?string $s): string {
        if ($s === null) return '';
        $s = str_replace(['\\', '|', '^', '&', '~'], ['\E\\', '\F\\', '\S\\', '\T\\', '\R\\'], $s);
        return str_replace(["\r\n", "\n", "\r"], '\.br\\', $s);
    }
    private static function ts(string $dt): string { return date('YmdHis', strtotime($dt)); }
    /** Nama bebas ke XPN sederhana: kata terakhir = nama keluarga, sisanya = nama depan (penyederhanaan). */
    private static function xpn(string $nama): string {
        $p = preg_split('/\s+/', trim($nama)); $fam = array_pop($p);
        return self::esc($fam) . ($p ? '^' . self::esc(implode(' ', $p)) : '');
    }
    /** Susun segmen dari peta posisi=>nilai; posisi kosong diisi string kosong. Nilai sudah di-escape pemanggil. */
    private static function seg(string $nama, array $f): string {
        $max = max(array_keys($f)); $out = [$nama];
        for ($i = 1; $i <= $max; $i++) $out[] = $f[$i] ?? '';
        return implode('|', $out);
    }
    private static function msh(string $tipe, string $ctrl, string $dari, string $ke): string {
        return 'MSH|^~\&|' . $dari . '|RSUD-BINA-SEHAT|' . $ke . '|RSUD-BINA-SEHAT|' . date('YmdHis', strtotime(Clock::now())) . '||' . $tipe . '|' . $ctrl . '|P|2.5.1';
    }
    private static function pid(array $o): string {
        $id = self::esc($o['no_rm']) . '^^^SIMRAD^MR';
        if (!empty($o['nik'])) $id .= '~' . self::esc($o['nik']) . '^^^NIK^NI';
        return self::seg('PID', [1 => '1', 3 => $id, 5 => self::xpn($o['pasien']), 7 => date('Ymd', strtotime($o['tanggal_lahir'])), 8 => $o['jenis_kelamin'] === 'L' ? 'M' : ($o['jenis_kelamin'] === 'P' ? 'F' : 'U')]);
    }
    private static function prio(array $o): string { return $o['prioritas'] === 'CITO' ? 'S' : 'R'; } // S = STAT, R = rutin
    private static function statusOrc(string $s): string { return ['ORDERED' => 'IP', 'SCHEDULED' => 'SC', 'IN_PROGRESS' => 'IP', 'COMPLETED' => 'A', 'REPORTED' => 'CM'][$s]; }
    private static function acc(array $o): string { return self::esc($o['accession_no']) . '^SIMRAD'; }
    private static function dokter(array $o): string { return self::esc($o['pengirim_username']) . '^' . self::esc($o['pengirim']); }

    /** ORM^O01: order pemeriksaan baru ke PACS/modalitas (basis DICOM Modality Worklist). */
    public static function ormOrder(array $o): array {
        $seg = [
            self::msh('ORM^O01^ORM_O01', 'SIMRAD' . $o['order_id'] . date('His', strtotime(Clock::now())), 'SIMRAD', 'PACS'),
            self::pid($o),
            self::seg('ORC', [1 => 'NW', 2 => self::acc($o), 3 => self::acc($o), 5 => self::statusOrc($o['status']), 7 => '^^^^^' . self::prio($o), 9 => self::ts($o['ordered_at']), 12 => self::dokter($o)]),
            self::seg('OBR', [1 => '1', 2 => self::acc($o), 3 => self::acc($o), 4 => self::esc($o['kode_pemeriksaan']) . '^' . self::esc($o['nama_pemeriksaan']) . '^SIMRAD', 16 => self::dokter($o), 24 => $o['jenis_modalitas']]),
        ];
        return ['jenis' => 'ORM^O01', 'segmen' => $seg, 'er7' => implode("\r", $seg) . "\r"];
    }

    // ---------- HL7 masuk (HIS -> RIS): ADT^A04 (registrasi pasien) dan ORM^O01 (order), dibalas ACK ----------
    /** Kebalikan esc(): dibaca kiri ke kanan agar urutan escape tidak salah. */
    public static function unesc(string $s): string {
        return preg_replace_callback('/\\\\(\.br|[FSRTE])\\\\/', fn($m) => ['.br' => "\n", 'F' => '|', 'S' => '^', 'R' => '~', 'T' => '&', 'E' => '\\'][$m[1]], $s);
    }
    private static function ack(string $trigger, string $ctrlAsal, string $kode, string $teks): array {
        $seg = [self::msh('ACK^' . $trigger . '^ACK', 'ACK' . $ctrlAsal, 'SIMRAD', 'HIS'), self::seg('MSA', [1 => $kode, 2 => self::esc($ctrlAsal), 3 => self::esc($teks)])];
        return ['hasil' => $kode, 'segmen' => $seg, 'ack' => implode("\r", $seg) . "\r"];
    }
    public static function terima(array $u, string $raw): array {
        $baris = preg_split('/\r\n|\r|\n/', trim($raw)); $seg = [];
        foreach ($baris as $b) { if ($b === '') continue; $p = explode('|', $b); $seg[$p[0]] = $p; }
        if (!isset($seg['MSH']) || count($seg['MSH']) < 12) throw new ApiError(422, 'HL7_TIDAK_VALID', 'Pesan HL7 tidak valid: segmen MSH tidak ada atau tidak lengkap.');
        $tipe = explode('^', $seg['MSH'][8] ?? ''); $ctrl = self::unesc($seg['MSH'][9] ?? ''); $msg = $tipe[0] ?? ''; $trg = $tipe[1] ?? '';
        $f = fn(string $sg, int $n) => $seg[$sg][$n] ?? '';
        try {
            if ($msg === 'ADT' && $trg === 'A04') {
                $ids = explode('~', $f('PID', 3)); $rm = null; $nik = null;
                foreach ($ids as $rep) { $c = explode('^', $rep); $id = self::unesc($c[0] ?? ''); if (($c[4] ?? '') === 'NI') $nik = $id; elseif ($rm === null) $rm = $id; }
                $n = explode('^', $f('PID', 5)); $nama = trim(self::unesc($n[1] ?? '') . ' ' . self::unesc($n[0] ?? ''));
                $dob = $f('PID', 7); $tgl = preg_match('/^(\d{4})(\d{2})(\d{2})/', $dob, $m) ? "$m[1]-$m[2]-$m[3]" : '';
                $jk = ['M' => 'L', 'F' => 'P'][$f('PID', 8)] ?? '';
                try { $r = Workflow::createPatient($u, ['no_rm' => $rm, 'nama' => $nama, 'tanggal_lahir' => $tgl, 'jenis_kelamin' => $jk, 'nik' => $nik]); return self::ack($trg, $ctrl, 'AA', 'Pasien terdaftar: ' . $r['no_rm']) + ['data' => $r]; }
                catch (ApiError $e) { if ($e->kode === 'NO_RM_SUDAH_ADA') return self::ack($trg, $ctrl, 'AA', 'Pasien sudah terdaftar; data tidak diubah.') + ['data' => ['no_rm' => $rm, 'duplikat' => true]]; throw $e; }
            }
            if ($msg === 'ORM' && $trg === 'O01') {
                if ($f('ORC', 1) !== 'NW') return self::ack($trg, $ctrl, 'AR', 'ORC-1 harus NW (order baru).');
                $rm = self::unesc(explode('^', explode('~', $f('PID', 3))[0])[0]);
                $st = Db::pdo()->prepare('SELECT patient_id FROM patient WHERE no_rm = ?'); $st->execute([$rm]); $pid = $st->fetchColumn();
                if (!$pid) return self::ack($trg, $ctrl, 'AE', 'Pasien belum terdaftar: ' . $rm);
                $kode = self::unesc(explode('^', $f('OBR', 4))[0]);
                $st = Db::pdo()->prepare('SELECT procedure_id FROM `procedure` WHERE kode = ?'); $st->execute([$kode]); $prc = $st->fetchColumn();
                if (!$prc) return self::ack($trg, $ctrl, 'AE', 'Kode pemeriksaan tidak dikenal: ' . $kode);
                $prio = (explode('^', $f('ORC', 7))[5] ?? '') === 'S' ? 'CITO' : 'REGULER';
                $o = Workflow::createOrder($u, (int)$pid, (int)$prc, $prio);
                return self::ack($trg, $ctrl, 'AA', 'Order diterima; nomor akses ' . $o['accession_no']) + ['data' => $o];
            }
            return self::ack($trg ?: 'XXX', $ctrl, 'AR', 'Jenis pesan tidak didukung: ' . $msg . '^' . $trg);
        } catch (ApiError $e) {
            if ($e->status === 403) throw $e;                       // hak akses ditolak: bukan urusan format pesan
            return self::ack($trg ?: 'XXX', $ctrl, 'AE', $e->getMessage());
        }
    }

    /** ORU^R01: hasil bacaan final ke HIS. Wajib ada laporan AWAL berstatus FINAL. */
    public static function oruHasil(array $o, array $laporan): array {
        $awal = null; foreach ($laporan as $l) if ($l['jenis'] === 'AWAL' && $l['status'] === 'FINAL') $awal = $l;
        if (!$awal) throw new ApiError(409, 'LAPORAN_BELUM_FINAL', 'Laporan final belum ada; hasil belum dapat dikirim.');
        $seg = [
            self::msh('ORU^R01^ORU_R01', 'SIMRAD' . $o['order_id'] . 'R' . date('His', strtotime(Clock::now())), 'SIMRAD', 'HIS'),
            self::pid($o),
            self::seg('ORC', [1 => 'RE', 2 => self::acc($o), 3 => self::acc($o), 5 => 'CM', 9 => self::ts($awal['finalized_at'])]),
            self::seg('OBR', [1 => '1', 2 => self::acc($o), 3 => self::acc($o), 4 => self::esc($o['kode_pemeriksaan']) . '^' . self::esc($o['nama_pemeriksaan']) . '^SIMRAD', 22 => self::ts($awal['finalized_at']), 24 => $o['jenis_modalitas'], 25 => 'F']),
        ];
        $n = 0;
        foreach ($laporan as $l) {
            if ($l['status'] !== 'FINAL') continue;
            $tag = $l['jenis'] === 'AWAL' ? '' : 'ADDENDUM';
            if ($l['isi_temuan'] !== null && trim($l['isi_temuan']) !== '') $seg[] = self::seg('OBX', [1 => (string)++$n, 2 => 'TX', 3 => ($tag ?: 'FINDINGS') . '^Temuan^SIMRAD', 5 => self::esc($l['isi_temuan']), 11 => 'F']);
            $seg[] = self::seg('OBX', [1 => (string)++$n, 2 => 'TX', 3 => ($tag ?: 'IMPRESSION') . '^Kesimpulan^SIMRAD', 5 => self::esc($l['kesimpulan']), 11 => 'F']);
        }
        return ['jenis' => 'ORU^R01', 'segmen' => $seg, 'er7' => implode("\r", $seg) . "\r"];
    }
}

/** Simulasi Modality Worklist (DICOM MWL, C-FIND): menyajikan order berstatus SCHEDULED dalam atribut DICOM bernama tag. */
final class Mwl {
    private static function aet(string $kodeAlat): string { return preg_replace('/^([A-Z]+)-(\d+)$/', '$1_ROOM$2', $kodeAlat); }
    private static function pn(string $nama): string { // PN DICOM: Keluarga^Nama; karakter ^ dan \ tidak boleh ada di dalam komponen
        $p = preg_split('/\s+/', str_replace(['^', '\\'], ' ', trim($nama))); $kel = array_pop($p); return $kel . ($p ? '^' . implode(' ', $p) : '');
    }
    public static function daftar(array $u, ?string $modality, ?string $aet): array {
        if ($u['role'] !== 'RADIOGRAFER') throw new ApiError(403, 'DILARANG', 'Modality Worklist hanya untuk radiografer (modalitas).');
        if ($modality !== null && $modality !== '' && !in_array($modality, ['CT', 'MR', 'DX', 'US'], true)) throw new ApiError(422, 'FILTER_TIDAK_VALID', 'Modality tidak dikenal.');
        $alat = []; foreach (Db::pdo()->query('SELECT jenis, kode_alat FROM modality ORDER BY kode_alat')->fetchAll() as $a) $alat[$a['jenis']] ??= self::aet($a['kode_alat']); // AET nominal: alat pertama tiap jenis
        $rows = Db::pdo()->query("SELECT o.order_id, o.accession_no, o.prioritas, p.no_rm, p.nama, p.tanggal_lahir, p.jenis_kelamin, pr.kode, pr.nama_pemeriksaan, pr.jenis_modalitas,
                (SELECT MAX(e.event_at) FROM workflow_event e WHERE e.order_id = o.order_id AND e.jenis_event = 'SCHEDULED') AS dijadwalkan
            FROM imaging_order o JOIN patient p ON p.patient_id = o.patient_id JOIN `procedure` pr ON pr.procedure_id = o.procedure_id
            WHERE o.status = 'SCHEDULED' ORDER BY FIELD(o.prioritas,'CITO','REGULER'), dijadwalkan, o.order_id")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $stasiun = $alat[$r['jenis_modalitas']] ?? '';
            if ($modality && $r['jenis_modalitas'] !== $modality) continue;
            if ($aet && $stasiun !== $aet) continue;
            $out[] = ['accession_no' => $r['accession_no'], 'prioritas' => $r['prioritas'], 'dicom' => [
                '(0010,0010) PatientName' => self::pn($r['nama']), '(0010,0020) PatientID' => $r['no_rm'], '(0010,0030) PatientBirthDate' => date('Ymd', strtotime($r['tanggal_lahir'])),
                '(0010,0040) PatientSex' => $r['jenis_kelamin'] === 'L' ? 'M' : 'F', '(0008,0050) AccessionNumber' => $r['accession_no'],
                '(0040,1001) RequestedProcedureID' => $r['kode'], '(0032,1060) RequestedProcedureDescription' => $r['nama_pemeriksaan'],
                '(0040,0100) ScheduledProcedureStepSequence' => [[
                    '(0008,0060) Modality' => $r['jenis_modalitas'], '(0040,0001) ScheduledStationAETitle' => $stasiun,
                    '(0040,0002) ScheduledProcedureStepStartDate' => date('Ymd', strtotime($r['dijadwalkan'])), '(0040,0003) ScheduledProcedureStepStartTime' => date('His', strtotime($r['dijadwalkan'])),
                    '(0040,0007) ScheduledProcedureStepDescription' => $r['nama_pemeriksaan'], '(0040,0009) ScheduledProcedureStepID' => 'SPS' . $r['order_id']]]]];
        }
        return ['jumlah' => count($out), 'data' => $out];
    }
}

final class Fhir {
    private const SOP = ['CT' => '1.2.840.10008.5.1.4.1.1.2', 'MR' => '1.2.840.10008.5.1.4.1.1.4', 'DX' => '1.2.840.10008.5.1.4.1.1.1.1', 'US' => '1.2.840.10008.5.1.4.1.1.6.1'];
    private static function uuid(): string { $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); $h = bin2hex($b); return 'urn:uuid:' . substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20); }
    private static function iso(?string $dt): ?string { return $dt ? date('Y-m-d\TH:i:s', strtotime($dt)) . '+07:00' : null; }
    private static function cond(string $system, string $value): string { return 'identifier=' . rawurlencode($system . '|' . $value); }
    private static function entry(string $full, array $res, string $tipe, string $ifNone): array { return ['fullUrl' => $full, 'resource' => $res, 'request' => ['method' => 'POST', 'url' => $tipe, 'ifNoneExist' => $ifNone]]; }

    /** Bundle transaksi untuk satu order. Hanya sumber daya yang sudah ada datanya yang disertakan. */
    public static function bundle(int $orderId): array {
        $o = IntegrationData::order($orderId); $st = IntegrationData::study($orderId);
        $pU = self::uuid(); $sU = self::uuid(); $iU = self::uuid(); $e = [];

        $pid = [['system' => 'urn:simrad:no-rm', 'value' => $o['no_rm']]];
        if (!empty($o['nik'])) $pid[] = ['system' => 'https://fhir.kemkes.go.id/id/nik', 'value' => $o['nik']];
        $e[] = self::entry($pU, ['resourceType' => 'Patient', 'identifier' => $pid, 'name' => [['text' => $o['pasien']]],
            'gender' => $o['jenis_kelamin'] === 'L' ? 'male' : ($o['jenis_kelamin'] === 'P' ? 'female' : 'unknown'), 'birthDate' => $o['tanggal_lahir']], 'Patient', self::cond('urn:simrad:no-rm', $o['no_rm']));

        $kode = ['coding' => [['system' => 'urn:simrad:procedure', 'code' => $o['kode_pemeriksaan'], 'display' => $o['nama_pemeriksaan']]], 'text' => $o['nama_pemeriksaan']];
        $e[] = self::entry($sU, ['resourceType' => 'ServiceRequest', 'identifier' => [['system' => 'urn:simrad:accession', 'value' => $o['accession_no']]],
            'status' => in_array($o['status'], ['COMPLETED', 'REPORTED'], true) ? 'completed' : 'active', 'intent' => 'order',
            'priority' => $o['prioritas'] === 'CITO' ? 'stat' : 'routine', 'code' => $kode, 'subject' => ['reference' => $pU],
            'authoredOn' => self::iso($o['ordered_at']), 'requester' => ['display' => $o['pengirim']]], 'ServiceRequest', self::cond('urn:simrad:accession', $o['accession_no']));

        $adaStudi = $st && in_array($st['status'], ['COMPLETED', 'REPORTED'], true);
        if ($adaStudi) {
            $series = IntegrationData::series((int)$st['study_id']); $jmlIns = 0; $sr = [];
            foreach ($series as $s) {
                $ins = []; foreach ($s['instances'] as $i) $ins[] = ['uid' => $i['sop_instance_uid'], 'sopClass' => ['system' => 'urn:ietf:rfc:3986', 'code' => 'urn:oid:' . self::SOP[$o['jenis_modalitas']]], 'number' => (int)$i['instance_number']];
                $jmlIns += count($ins);
                $one = ['uid' => $s['series_instance_uid'], 'number' => (int)$s['series_number'], 'modality' => ['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $o['jenis_modalitas']], 'numberOfInstances' => count($ins), 'instance' => $ins];
                if (!empty($s['body_part'])) $one['bodySite'] = ['display' => $s['body_part']];
                $sr[] = $one;
            }
            $e[] = self::entry($iU, ['resourceType' => 'ImagingStudy', 'identifier' => [
                    ['system' => 'urn:dicom:uid', 'value' => 'urn:oid:' . $st['study_instance_uid']],
                    ['type' => ['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0203', 'code' => 'ACSN']]], 'system' => 'urn:simrad:accession', 'value' => $o['accession_no']]],
                'status' => 'available', 'subject' => ['reference' => $pU], 'started' => self::iso($st['started_at']), 'basedOn' => [['reference' => $sU]],
                'modality' => [['system' => 'http://dicom.nema.org/resources/ontology/DCM', 'code' => $o['jenis_modalitas']]],
                'numberOfSeries' => count($sr), 'numberOfInstances' => $jmlIns, 'series' => $sr], 'ImagingStudy', self::cond('urn:dicom:uid', 'urn:oid:' . $st['study_instance_uid']));
            foreach (IntegrationData::reports((int)$st['study_id']) as $r) {
                $final = $r['status'] === 'FINAL';
                $dr = ['resourceType' => 'DiagnosticReport', 'identifier' => [['system' => 'urn:simrad:report', 'value' => 'R' . $r['report_id']]],
                    'status' => !$final ? 'preliminary' : ($r['jenis'] === 'ADDENDUM' ? 'appended' : 'final'),
                    'category' => [['coding' => [['system' => 'http://terminology.hl7.org/CodeSystem/v2-0074', 'code' => 'RAD', 'display' => 'Radiology']]]],
                    'code' => $kode, 'subject' => ['reference' => $pU], 'effectiveDateTime' => self::iso($st['completed_at']),
                    'performer' => [['display' => $r['penulis']]], 'basedOn' => [['reference' => $sU]], 'imagingStudy' => [['reference' => $iU]], 'conclusion' => $r['kesimpulan']];
                if ($final) $dr['issued'] = self::iso($r['finalized_at']);
                if ($r['isi_temuan'] !== null && trim($r['isi_temuan']) !== '') $dr['presentedForm'] = [['contentType' => 'text/plain; charset=utf-8', 'language' => 'id', 'data' => base64_encode($r['isi_temuan'])]];
                $e[] = self::entry(self::uuid(), $dr, 'DiagnosticReport', self::cond('urn:simrad:report', 'R' . $r['report_id']));
            }
        }
        return ['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => $e];
    }
}

/** Pintu masuk API: pemeriksaan hak akses (B4), lalu bangun pesan. Admin TI tidak membuka isi klinis. */
final class Integration {
    private static function muat(array $u, int $id): array {
        if (!in_array($u['role'], ['DOKTER', 'RADIOGRAFER', 'RADIOLOG'], true)) throw new ApiError(403, 'DILARANG', 'Peran ' . $u['role'] . ' tidak berhak mengakses pesan integrasi.');
        $o = IntegrationData::order($id);
        if ($u['role'] === 'DOKTER' && (int)$o['requester_id'] !== $u['user_id']) throw new ApiError(403, 'DILARANG', 'Dokter hanya dapat mengakses order miliknya.');
        return $o;
    }
    public static function hl7Order(array $u, int $id): array { return Hl7::ormOrder(self::muat($u, $id)); }
    public static function hl7Hasil(array $u, int $id): array {
        $o = self::muat($u, $id); $st = IntegrationData::study($id);
        return Hl7::oruHasil($o, $st ? IntegrationData::reports((int)$st['study_id']) : []);
    }
    public static function fhirBundle(array $u, int $id): array { self::muat($u, $id); return Fhir::bundle($id); }
    public static function hl7Terima(array $u, string $er7): array { if (!in_array($u['role'], ['DOKTER', 'RADIOGRAFER'], true)) throw new ApiError(403, 'DILARANG', 'Peran ' . $u['role'] . ' tidak berhak mengirim pesan HL7 masuk.'); return Hl7::terima($u, $er7); }
}
