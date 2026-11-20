<?php
declare(strict_types=1);

/**
 * SIMULATOR PACS (bukan bagian ERD B3). Meniru penerimaan C-STORE: metadata instance dari RIS disimpan di tabel
 * pacs_sim_instance; status online/offline di pacs_sim_status (bisa diubah Admin TI) untuk mensimulasikan PACS mati
 * (decision D2 pada activity diagram B2: gagal kirim -> dicatat, bisa dikirim ulang). Tidak ada berkas DICOM nyata.
 */
final class Pacs {
    private static bool $siap = false;

    /** Harus dipanggil DI LUAR transaksi (CREATE TABLE menyebabkan commit implisit di MySQL). */
    public static function pastikanTabel(): void {
        if (self::$siap) return;
        $pdo = Db::pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS pacs_sim_instance (
            sop_instance_uid VARCHAR(64) NOT NULL PRIMARY KEY, series_instance_uid VARCHAR(64) NOT NULL, study_instance_uid VARCHAR(64) NOT NULL,
            accession_no VARCHAR(20) NOT NULL, patient_id VARCHAR(20) NOT NULL, patient_name VARCHAR(100) NOT NULL, modality CHAR(2) NOT NULL,
            received_at DATETIME NOT NULL, INDEX idx_pacs_study (study_instance_uid))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pacs_sim_status (id TINYINT NOT NULL PRIMARY KEY, online TINYINT(1) NOT NULL)");
        $pdo->exec("INSERT IGNORE INTO pacs_sim_status (id, online) VALUES (1, 1)");
        self::$siap = true;
    }
    public static function online(): bool { self::pastikanTabel(); return (int)Db::pdo()->query('SELECT online FROM pacs_sim_status WHERE id = 1')->fetchColumn() === 1; }

    public static function setOnline(array $u, bool $on): array {
        if ($u['role'] !== 'ADMIN_TI') throw new ApiError(403, 'DILARANG', 'Hanya Admin TI yang dapat mengubah status PACS simulasi.');
        self::pastikanTabel(); Db::pdo()->prepare('UPDATE pacs_sim_status SET online = ? WHERE id = 1')->execute([$on ? 1 : 0]);
        return ['online' => $on];
    }

    /** C-STORE simulasi satu studi. INSERT IGNORE pada UID instance membuat pengiriman ulang idempoten (tidak menggandakan). */
    public static function kirim(int $studyId): array {
        if (!self::online()) return ['ok' => false, 'alasan' => 'PACS offline'];
        $pdo = Db::pdo();
        $st = $pdo->prepare("SELECT s.study_instance_uid, o.accession_no, p.no_rm, p.nama, pr.jenis_modalitas FROM study s
            JOIN imaging_order o ON o.order_id = s.order_id JOIN patient p ON p.patient_id = o.patient_id JOIN `procedure` pr ON pr.procedure_id = o.procedure_id WHERE s.study_id = ?");
        $st->execute([$studyId]); $m = $st->fetch();
        $in = $pdo->prepare('SELECT i.sop_instance_uid, se.series_instance_uid FROM instance i JOIN series se ON se.series_id = i.series_id WHERE se.study_id = ?');
        $in->execute([$studyId]); $rows = $in->fetchAll();
        $w = $pdo->prepare('INSERT IGNORE INTO pacs_sim_instance (sop_instance_uid, series_instance_uid, study_instance_uid, accession_no, patient_id, patient_name, modality, received_at) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) $w->execute([$r['sop_instance_uid'], $r['series_instance_uid'], $m['study_instance_uid'], $m['accession_no'], $m['no_rm'], $m['nama'], $m['jenis_modalitas'], Clock::now()]);
        return ['ok' => true, 'jumlah' => count($rows)];
    }

    /** Perbandingan jumlah instance di RIS dan di PACS simulasi (dasar uji "tanpa data hilang"). */
    public static function status(int $studyId): array {
        self::pastikanTabel(); $pdo = Db::pdo();
        $a = $pdo->prepare('SELECT s.study_instance_uid, (SELECT COUNT(*) FROM instance i JOIN series se ON se.series_id = i.series_id WHERE se.study_id = s.study_id) AS n FROM study s WHERE s.study_id = ?');
        $a->execute([$studyId]); $r = $a->fetch();
        $b = $pdo->prepare('SELECT COUNT(*) FROM pacs_sim_instance WHERE study_instance_uid = ?'); $b->execute([$r['study_instance_uid']]);
        $ris = (int)$r['n']; $pacs = (int)$b->fetchColumn();
        return ['di_ris' => $ris, 'di_pacs' => $pacs, 'lengkap' => $ris > 0 && $ris === $pacs, 'pacs_online' => self::online()];
    }
}
