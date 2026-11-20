<?php
declare(strict_types=1);

/**
 * Alur kerja order radiologi: ORDERED -> SCHEDULED -> IN_PROGRESS -> COMPLETED -> REPORTED.
 * Mengikuti activity diagram B2 (decision: CITO, kualitas citra) dan hak akses per peran B4.
 * Setiap perubahan status dicatat di workflow_event (log audit, UU PDP Pasal 31).
 */
final class Workflow {
    private static function pdo(): PDO { return Db::pdo(); }

    /** Hak akses minimum per peran (B4): aksi hanya untuk peran yang berwenang. */
    private static function need(array $u, array $roles): void {
        if (!in_array($u['role'], $roles, true)) {
            throw new ApiError(403, 'DILARANG', 'Peran ' . $u['role'] . ' tidak berhak melakukan aksi ini.');
        }
    }

    private static function event(int $orderId, int $actorId, string $jenis): void {
        self::pdo()->prepare('INSERT INTO workflow_event (order_id, actor_id, jenis_event, event_at) VALUES (?,?,?,?)')
            ->execute([$orderId, $actorId, $jenis, Clock::now()]);
    }

    /** UID DICOM dengan akar 2.25 (UUID sebagai bilangan bulat). Panjang 43 karakter, di bawah batas 64. */
    public static function newUid(): string {
        $d = (string)random_int(1, 9);
        for ($i = 0; $i < 37; $i++) $d .= (string)random_int(0, 9);
        return '2.25.' . $d;
    }

    private static function transaksi(callable $fn) {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $hasil = $fn(); $pdo->commit(); return $hasil; }
        catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    private static function getOrder(int $id): array {
        $st = self::pdo()->prepare('SELECT o.*, pr.jenis_modalitas FROM imaging_order o JOIN `procedure` pr ON pr.procedure_id = o.procedure_id WHERE o.order_id = ? FOR UPDATE');
        $st->execute([$id]);
        $o = $st->fetch();
        if (!$o) throw new ApiError(404, 'ORDER_TIDAK_ADA', 'Order tidak ditemukan.');
        return $o;
    }

    private static function getStudy(int $id): array {
        $st = self::pdo()->prepare('SELECT * FROM study WHERE study_id = ? FOR UPDATE');
        $st->execute([$id]);
        $s = $st->fetch();
        if (!$s) throw new ApiError(404, 'STUDI_TIDAK_ADA', 'Studi tidak ditemukan.');
        return $s;
    }

    /** Memotong teks ke panjang maksimum; memakai mbstring bila ada, bila tidak memotong per byte aman UTF-8. */
    private static function potong(string $teks, int $maks): string {
        if (function_exists('mb_substr')) return mb_substr($teks, 0, $maks);
        return preg_match('/^.{0,' . $maks . '}/su', $teks, $m) ? $m[0] : substr($teks, 0, $maks);
    }

    private static function salah(string $kode, string $pesan, int $status = 409): never { throw new ApiError($status, $kode, $pesan); }

    // ---- UC: Dokter membuat order ----------------------------------------------------------
    public static function createOrder(array $u, int $patientId, int $procedureId, string $prioritas): array {
        self::need($u, ['DOKTER']);
        if (!in_array($prioritas, ['CITO', 'REGULER'], true)) self::salah('PRIORITAS_TIDAK_VALID', 'Prioritas harus CITO atau REGULER.', 422);
        return self::transaksi(function () use ($u, $patientId, $procedureId, $prioritas) {
            $c = self::pdo()->prepare('SELECT COUNT(*) FROM patient WHERE patient_id = ?'); $c->execute([$patientId]);
            if (!(int)$c->fetchColumn()) self::salah('PASIEN_TIDAK_ADA', 'Pasien tidak ditemukan.', 404);
            $c = self::pdo()->prepare('SELECT COUNT(*) FROM `procedure` WHERE procedure_id = ?'); $c->execute([$procedureId]);
            if (!(int)$c->fetchColumn()) self::salah('PEMERIKSAAN_TIDAK_ADA', 'Jenis pemeriksaan tidak ditemukan.', 404);
            // Nomor akses sementara, lalu diganti berbasis ID agar pasti unik.
            self::pdo()->prepare('INSERT INTO imaging_order (accession_no, patient_id, procedure_id, requester_id, prioritas, status, ordered_at) VALUES (?,?,?,?,?,?,?)')
                ->execute(['TMP' . bin2hex(random_bytes(8)), $patientId, $procedureId, $u['user_id'], $prioritas, 'ORDERED', Clock::now()]);
            $id = (int)self::pdo()->lastInsertId();
            $acc = 'ACC' . date('Ymd', strtotime(Clock::now())) . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
            self::pdo()->prepare('UPDATE imaging_order SET accession_no = ? WHERE order_id = ?')->execute([$acc, $id]);
            self::event($id, $u['user_id'], 'ORDER_CREATED');
            return ['order_id' => $id, 'accession_no' => $acc, 'status' => 'ORDERED', 'prioritas' => $prioritas];
        });
    }

    // ---- Radiografer menjadwalkan ---------------------------------------------------------
    public static function schedule(array $u, int $orderId): array {
        self::need($u, ['RADIOGRAFER']);
        return self::transaksi(function () use ($u, $orderId) {
            $o = self::getOrder($orderId);
            if ($o['status'] !== 'ORDERED') self::salah('STATUS_TIDAK_VALID', 'Order berstatus ' . $o['status'] . '; hanya ORDERED yang bisa dijadwalkan.');
            self::pdo()->prepare("UPDATE imaging_order SET status='SCHEDULED' WHERE order_id=?")->execute([$orderId]);
            self::event($orderId, $u['user_id'], 'SCHEDULED');
            return ['order_id' => $orderId, 'status' => 'SCHEDULED'];
        });
    }

    // ---- Radiografer memulai pemeriksaan (membuat Study + UID DICOM) -----------------------
    public static function start(array $u, int $orderId, int $modalityId): array {
        self::need($u, ['RADIOGRAFER']);
        return self::transaksi(function () use ($u, $orderId, $modalityId) {
            $o = self::getOrder($orderId);
            if ($o['status'] !== 'SCHEDULED') self::salah('STATUS_TIDAK_VALID', 'Order berstatus ' . $o['status'] . '; pemeriksaan hanya bisa dimulai dari SCHEDULED.');
            $st = self::pdo()->prepare('SELECT * FROM modality WHERE modality_id = ?'); $st->execute([$modalityId]);
            $m = $st->fetch();
            if (!$m) self::salah('ALAT_TIDAK_ADA', 'Alat tidak ditemukan.', 404);
            // Pencegahan galat (B5): jenis alat harus sesuai pemeriksaan, dan alat tidak boleh OFFLINE.
            if ($m['jenis'] !== $o['jenis_modalitas']) self::salah('ALAT_TIDAK_SESUAI', 'Alat ' . $m['kode_alat'] . ' (' . $m['jenis'] . ') tidak sesuai untuk pemeriksaan ' . $o['jenis_modalitas'] . '.', 422);
            if ($m['status'] === 'OFFLINE') self::salah('ALAT_OFFLINE', 'Alat ' . $m['kode_alat'] . ' sedang OFFLINE.');
            $uid = self::newUid();
            self::pdo()->prepare("INSERT INTO study (study_instance_uid, order_id, modality_id, performed_by, started_at, status) VALUES (?,?,?,?,?, 'IN_PROGRESS')")
                ->execute([$uid, $orderId, $modalityId, $u['user_id'], Clock::now()]);
            $sid = (int)self::pdo()->lastInsertId();
            self::pdo()->prepare("UPDATE imaging_order SET status='IN_PROGRESS' WHERE order_id=?")->execute([$orderId]);
            self::event($orderId, $u['user_id'], 'STUDY_STARTED');
            $hasil = ['order_id' => $orderId, 'study_id' => $sid, 'study_instance_uid' => $uid, 'status' => 'IN_PROGRESS'];
            if ($m['status'] === 'PERLU_CEK') $hasil['peringatan'] = 'Alat ' . $m['kode_alat'] . ' berstatus PERLU_CEK.';
            return $hasil;
        });
    }

    // ---- Radiografer menyelesaikan; decision node D3 (kualitas citra) ---------------------
    // Citra pada prototipe ini SIMULASI (tidak ada berkas DICOM nyata); jumlah instance dicatat sebagai bukti alur.
    public static function complete(array $u, int $studyId, int $jumlahInstance, bool $kualitasBaik, string $bodyPart = ''): array {
        self::need($u, ['RADIOGRAFER']);
        if ($jumlahInstance < 1 || $jumlahInstance > 2000) self::salah('JUMLAH_TIDAK_VALID', 'Jumlah instance harus 1 sampai 2000.', 422);
        Pacs::pastikanTabel(); // di luar transaksi (DDL menyebabkan commit implisit)
        return self::transaksi(function () use ($u, $studyId, $jumlahInstance, $kualitasBaik, $bodyPart) {
            $s = self::getStudy($studyId);
            if ($s['status'] !== 'IN_PROGRESS') self::salah('STATUS_TIDAK_VALID', 'Studi berstatus ' . $s['status'] . '; hanya IN_PROGRESS yang bisa diselesaikan.');
            if (!$kualitasBaik) { // citra tidak layak: ulangi pengambilan, status tidak berubah
                self::event((int)$s['order_id'], $u['user_id'], 'IMAGE_REJECTED');
                return ['study_id' => $studyId, 'status' => 'IN_PROGRESS', 'ulangi_pengambilan' => true];
            }
            self::pdo()->prepare('INSERT INTO series (series_instance_uid, study_id, series_number, body_part) VALUES (?,?,?,?)')
                ->execute([self::newUid(), $studyId, 1, $bodyPart !== '' ? self::potong($bodyPart, 30) : null]);
            $seriesId = (int)self::pdo()->lastInsertId();
            $ins = self::pdo()->prepare('INSERT INTO instance (sop_instance_uid, series_id, instance_number, file_path) VALUES (?,?,?,?)');
            for ($i = 1; $i <= $jumlahInstance; $i++) $ins->execute([self::newUid(), $seriesId, $i, 'simulasi://' . $s['study_instance_uid'] . '/' . $i]);
            self::pdo()->prepare("UPDATE study SET status='COMPLETED', completed_at=? WHERE study_id=?")->execute([Clock::now(), $studyId]);
            self::pdo()->prepare("UPDATE imaging_order SET status='COMPLETED' WHERE order_id=?")->execute([$s['order_id']]);
            self::event((int)$s['order_id'], $u['user_id'], 'STUDY_COMPLETED');
            // Decision D2 (B2): kirim ke PACS; bila PACS tidak tersedia, studi TIDAK hilang, kegagalan dicatat dan dapat dikirim ulang.
            $kirim = Pacs::kirim($studyId);
            self::event((int)$s['order_id'], $u['user_id'], $kirim['ok'] ? 'SENT_TO_PACS' : 'PACS_SEND_FAILED');
            return ['study_id' => $studyId, 'status' => 'COMPLETED', 'jumlah_instance' => $jumlahInstance, 'pacs' => $kirim['ok'] ? 'terkirim' : 'gagal'];
        });
    }

    // ---- Kirim ulang studi ke PACS (setelah gagal kirim) ------------------------------------
    public static function kirimUlangPacs(array $u, int $studyId): array {
        self::need($u, ['RADIOGRAFER']);
        Pacs::pastikanTabel();
        return self::transaksi(function () use ($u, $studyId) {
            $s = self::getStudy($studyId);
            if (!in_array($s['status'], ['COMPLETED', 'REPORTED'], true)) self::salah('STATUS_TIDAK_VALID', 'Studi belum selesai; belum ada citra untuk dikirim.');
            $k = Pacs::kirim($studyId);
            self::event((int)$s['order_id'], $u['user_id'], $k['ok'] ? 'SENT_TO_PACS' : 'PACS_SEND_FAILED');
            return ['study_id' => $studyId, 'pacs' => $k['ok'] ? 'terkirim' : 'gagal', 'jumlah_instance' => $k['jumlah'] ?? 0, 'alasan' => $k['alasan'] ?? null];
        });
    }

    // ---- Registrasi pasien (dokter atau radiografer) ---------------------------------------
    // Peran "petugas pendaftaran" pada B1 tidak dimodelkan sebagai akun; prototipe mengizinkan dokter dan radiografer mendaftarkan pasien.
    public static function createPatient(array $u, array $in): array {
        self::need($u, ['DOKTER', 'RADIOGRAFER']);
        $nama = trim((string)($in['nama'] ?? ''));
        if (!preg_match('/^.{1,100}$/su', $nama)) self::salah('NAMA_TIDAK_VALID', 'Nama wajib diisi (maksimal 100 karakter).', 422);
        $tgl = (string)($in['tanggal_lahir'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tgl, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) self::salah('TANGGAL_LAHIR_TIDAK_VALID', 'Tanggal lahir harus berformat YYYY-MM-DD yang valid.', 422);
        if ($tgl > date('Y-m-d', strtotime(Clock::now()))) self::salah('TANGGAL_LAHIR_TIDAK_VALID', 'Tanggal lahir tidak boleh di masa depan.', 422);
        $jk = (string)($in['jenis_kelamin'] ?? '');
        if (!in_array($jk, ['L', 'P'], true)) self::salah('JENIS_KELAMIN_TIDAK_VALID', 'Jenis kelamin harus L atau P.', 422);
        $nik = isset($in['nik']) && $in['nik'] !== '' ? (string)$in['nik'] : null;
        if ($nik !== null && !preg_match('/^\d{16}$/', $nik)) self::salah('NIK_TIDAK_VALID', 'NIK harus 16 digit angka.', 422);
        $rm = isset($in['no_rm']) && trim((string)$in['no_rm']) !== '' ? trim((string)$in['no_rm']) : null;
        if ($rm !== null && !preg_match('/^[A-Za-z0-9\-]{1,20}$/', $rm)) self::salah('NO_RM_TIDAK_VALID', 'Nomor RM hanya huruf, angka, dan tanda hubung (maksimal 20 karakter).', 422);
        return self::transaksi(function () use ($nama, $tgl, $jk, $nik, $rm) {
            // Nomor RM diperiksa lebih dulu: pesan HL7 yang dikirim ulang (MRN sama) harus dikenali sebagai duplikat, bukan konflik NIK.
            if ($rm !== null) { $c = self::pdo()->prepare('SELECT COUNT(*) FROM patient WHERE no_rm = ?'); $c->execute([$rm]); if ((int)$c->fetchColumn()) self::salah('NO_RM_SUDAH_ADA', 'Nomor RM ini sudah terdaftar.'); }
            if ($nik !== null) { $c = self::pdo()->prepare('SELECT COUNT(*) FROM patient WHERE nik = ?'); $c->execute([$nik]); if ((int)$c->fetchColumn()) self::salah('NIK_SUDAH_TERDAFTAR', 'NIK ini sudah terdaftar.'); }
            if ($rm === null) { // nomor RM otomatis: P + nomor urut berikutnya
                $n = (int)self::pdo()->query("SELECT COALESCE(MAX(CAST(SUBSTRING(no_rm, 2) AS UNSIGNED)), 0) FROM patient WHERE no_rm REGEXP '^P[0-9]+$' FOR UPDATE")->fetchColumn();
                $rm = 'P' . str_pad((string)($n + 1), 4, '0', STR_PAD_LEFT);
            }
            try { self::pdo()->prepare('INSERT INTO patient (no_rm, nik, nama, tanggal_lahir, jenis_kelamin) VALUES (?,?,?,?,?)')->execute([$rm, $nik, $nama, $tgl, $jk]); }
            catch (PDOException $e) { if ($e->getCode() === '23000') self::salah('NO_RM_SUDAH_ADA', 'Nomor RM ini sudah terdaftar.'); throw $e; }
            return ['patient_id' => (int)self::pdo()->lastInsertId(), 'no_rm' => $rm, 'nama' => $nama];
        });
    }

    // ---- Radiolog menulis laporan (draft, final, addendum) --------------------------------
    public static function saveReport(array $u, int $studyId, string $isi, string $kesimpulan, bool $finalize): array {
        self::need($u, ['RADIOLOG']);
        return self::transaksi(function () use ($u, $studyId, $isi, $kesimpulan, $finalize) {
            $s = self::getStudy($studyId);
            if ($s['status'] === 'IN_PROGRESS') self::salah('STATUS_TIDAK_VALID', 'Studi belum selesai (IN_PROGRESS); laporan belum bisa dibuat.');
            $kesimpulan = trim($kesimpulan);
            if (($finalize || $s['status'] === 'REPORTED') && $kesimpulan === '') self::salah('KESIMPULAN_WAJIB', 'Kesimpulan wajib diisi untuk laporan final atau addendum.', 422);
            if ($s['status'] === 'REPORTED') { // laporan final tidak diubah; koreksi lewat addendum
                self::pdo()->prepare("INSERT INTO report (study_id, author_id, jenis, isi_temuan, kesimpulan, status, finalized_at) VALUES (?,?, 'ADDENDUM', ?, ?, 'FINAL', ?)")
                    ->execute([$studyId, $u['user_id'], $isi, $kesimpulan, Clock::now()]);
                self::event((int)$s['order_id'], $u['user_id'], 'ADDENDUM_ADDED');
                return ['study_id' => $studyId, 'jenis' => 'ADDENDUM', 'status' => 'FINAL'];
            }
            $st = self::pdo()->prepare("SELECT report_id FROM report WHERE study_id=? AND jenis='AWAL' FOR UPDATE"); $st->execute([$studyId]);
            $rid = $st->fetchColumn();
            if ($rid === false) {
                self::pdo()->prepare("INSERT INTO report (study_id, author_id, jenis, isi_temuan, kesimpulan, status) VALUES (?,?, 'AWAL', ?, ?, 'DRAFT')")
                    ->execute([$studyId, $u['user_id'], $isi, $kesimpulan]);
                $rid = (int)self::pdo()->lastInsertId();
                self::event((int)$s['order_id'], $u['user_id'], 'REPORT_DRAFT_SAVED');
            } else {
                self::pdo()->prepare("UPDATE report SET isi_temuan=?, kesimpulan=?, author_id=? WHERE report_id=?")->execute([$isi, $kesimpulan, $u['user_id'], $rid]);
                self::event((int)$s['order_id'], $u['user_id'], 'REPORT_DRAFT_SAVED');
            }
            if (!$finalize) return ['study_id' => $studyId, 'jenis' => 'AWAL', 'status' => 'DRAFT'];
            self::pdo()->prepare("UPDATE report SET status='FINAL', finalized_at=? WHERE report_id=?")->execute([Clock::now(), $rid]);
            self::pdo()->prepare("UPDATE study SET status='REPORTED' WHERE study_id=?")->execute([$studyId]);
            self::pdo()->prepare("UPDATE imaging_order SET status='REPORTED' WHERE order_id=?")->execute([$s['order_id']]);
            self::event((int)$s['order_id'], $u['user_id'], 'REPORT_FINALIZED');
            return ['study_id' => $studyId, 'jenis' => 'AWAL', 'status' => 'FINAL'];
        });
    }

    // ---- Tandai CITO (tombol pada dashboard Figma; dengan konfirmasi di antarmuka) ---------
    public static function markCito(array $u, int $orderId): array {
        self::need($u, ['DOKTER', 'RADIOLOG']);
        return self::transaksi(function () use ($u, $orderId) {
            $o = self::getOrder($orderId);
            if ($o['status'] === 'REPORTED') self::salah('STATUS_TIDAK_VALID', 'Order sudah REPORTED; prioritas tidak dapat diubah.');
            if ($o['prioritas'] === 'CITO') self::salah('SUDAH_CITO', 'Order sudah berprioritas CITO.');
            self::pdo()->prepare("UPDATE imaging_order SET prioritas='CITO' WHERE order_id=?")->execute([$orderId]);
            self::event($orderId, $u['user_id'], 'PRIORITY_TO_CITO');
            return ['order_id' => $orderId, 'prioritas' => 'CITO'];
        });
    }

    // ---- Daftar kerja (worklist) ----------------------------------------------------------
    public static function listOrders(array $u, ?string $status = null, ?string $prioritas = null, ?string $q = null): array {
        self::need($u, ['DOKTER', 'RADIOGRAFER', 'RADIOLOG']); // Admin TI tidak membuka isi klinis (B4)
        $sql = "SELECT o.order_id, o.accession_no, o.prioritas, o.status, o.ordered_at,
                       p.no_rm, p.nama AS pasien, pr.nama_pemeriksaan, pr.jenis_modalitas,
                       s.study_id, s.study_instance_uid,
                       (SELECT e.jenis_event FROM workflow_event e WHERE e.order_id = o.order_id AND e.jenis_event IN ('SENT_TO_PACS','PACS_SEND_FAILED') ORDER BY e.event_id DESC LIMIT 1) AS pacs_status,
                       TIMESTAMPDIFF(MINUTE, o.ordered_at, COALESCE(rf.finalized_at, ?)) AS menit_berjalan
                FROM imaging_order o
                JOIN patient p ON p.patient_id = o.patient_id
                JOIN `procedure` pr ON pr.procedure_id = o.procedure_id
                LEFT JOIN study s ON s.order_id = o.order_id
                LEFT JOIN report rf ON rf.study_id = s.study_id AND rf.jenis='AWAL' AND rf.status='FINAL'
                WHERE 1=1";
        $par = [Clock::now()];
        if ($u['role'] === 'DOKTER') { $sql .= ' AND o.requester_id = ?'; $par[] = $u['user_id']; } // dokter hanya order miliknya
        if ($status !== null && $status !== '') {
            if (!in_array($status, ['ORDERED','SCHEDULED','IN_PROGRESS','COMPLETED','REPORTED'], true)) self::salah('FILTER_TIDAK_VALID', 'Status filter tidak dikenal.', 422);
            $sql .= ' AND o.status = ?'; $par[] = $status;
        }
        if ($prioritas !== null && $prioritas !== '') {
            if (!in_array($prioritas, ['CITO','REGULER'], true)) self::salah('FILTER_TIDAK_VALID', 'Prioritas filter tidak dikenal.', 422);
            $sql .= ' AND o.prioritas = ?'; $par[] = $prioritas;
        }
        if ($q !== null && $q !== '') { $sql .= ' AND (p.nama LIKE ? OR p.no_rm LIKE ? OR o.accession_no LIKE ?)'; $like = '%' . $q . '%'; array_push($par, $like, $like, $like); }
        $sql .= " ORDER BY (o.status = 'REPORTED'), FIELD(o.prioritas,'CITO','REGULER'), o.ordered_at ASC, o.order_id ASC LIMIT 500";
        $st = self::pdo()->prepare($sql); $st->execute($par);
        return $st->fetchAll();
    }

    // ---- Detail order + jejak audit -------------------------------------------------------
    public static function orderDetail(array $u, int $orderId): array {
        self::need($u, ['DOKTER', 'RADIOGRAFER', 'RADIOLOG']);
        $st = self::pdo()->prepare('SELECT o.*, p.no_rm, p.nama AS pasien, pr.nama_pemeriksaan FROM imaging_order o JOIN patient p USING (patient_id) JOIN `procedure` pr USING (procedure_id) WHERE o.order_id = ?');
        $st->execute([$orderId]); $o = $st->fetch();
        if (!$o) throw new ApiError(404, 'ORDER_TIDAK_ADA', 'Order tidak ditemukan.');
        if ($u['role'] === 'DOKTER' && (int)$o['requester_id'] !== $u['user_id']) throw new ApiError(403, 'DILARANG', 'Dokter hanya dapat melihat order miliknya.');
        $st = self::pdo()->prepare('SELECT * FROM study WHERE order_id = ?'); $st->execute([$orderId]); $o['study'] = $st->fetch() ?: null;
        $o['pacs'] = $o['study'] ? Pacs::status((int)$o['study']['study_id']) : null;
        $o['laporan'] = [];
        if ($o['study']) { $st = self::pdo()->prepare('SELECT report_id, jenis, isi_temuan, kesimpulan, status, finalized_at FROM report WHERE study_id = ? ORDER BY report_id'); $st->execute([$o['study']['study_id']]); $o['laporan'] = $st->fetchAll(); }
        $st = self::pdo()->prepare('SELECT e.jenis_event, e.event_at, a.nama AS aktor FROM workflow_event e JOIN app_user a ON a.user_id = e.actor_id WHERE e.order_id = ? ORDER BY e.event_at, e.event_id');
        $st->execute([$orderId]); $o['jejak_audit'] = $st->fetchAll();
        return $o;
    }

    // ---- Data rujukan untuk formulir ------------------------------------------------------
    public static function patients(array $u, string $q = ''): array {
        self::need($u, ['DOKTER', 'RADIOGRAFER', 'RADIOLOG']);
        $st = self::pdo()->prepare('SELECT patient_id, no_rm, nama, tanggal_lahir, jenis_kelamin FROM patient WHERE nama LIKE ? OR no_rm LIKE ? ORDER BY nama LIMIT 50');
        $like = '%' . $q . '%'; $st->execute([$like, $like]); return $st->fetchAll();
    }
    public static function procedures(): array { return self::pdo()->query('SELECT procedure_id, kode, nama_pemeriksaan, jenis_modalitas FROM `procedure` ORDER BY kode')->fetchAll(); }
    public static function modalities(): array { return self::pdo()->query('SELECT modality_id, kode_alat, jenis, lokasi, status FROM modality ORDER BY kode_alat')->fetchAll(); }
}
