<?php
declare(strict_types=1);

/**
 * Metrik operasional untuk dashboard KPI (kebutuhan skenario: TAT CITO < 60 menit, reguler < 120 menit).
 * TAT = selisih waktu order dibuat (ordered_at) sampai laporan awal difinalkan (finalized_at).
 * Metrik berupa agregat, tanpa identitas pasien, sehingga aman ditampilkan ke semua peran.
 */
final class Metrics {
    public static function summary(): array {
        $pdo = Db::pdo();
        $cfg = (require __DIR__ . '/../config.php')['tat_target_menit'];
        $now = Clock::now();

        $status = ['ORDERED' => 0, 'SCHEDULED' => 0, 'IN_PROGRESS' => 0, 'COMPLETED' => 0, 'REPORTED' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) n FROM imaging_order GROUP BY status') as $r) $status[$r['status']] = (int)$r['n'];

        $tat = [];
        foreach (['CITO', 'REGULER'] as $p) {
            $st = $pdo->prepare("SELECT COUNT(*) n, AVG(s) / 60 AS rata_menit, MAX(s) / 60 AS maks_menit, SUM(s < ? * 60) AS dalam_target
                FROM (SELECT TIMESTAMPDIFF(SECOND, o.ordered_at, r.finalized_at) AS s
                      FROM imaging_order o JOIN study st ON st.order_id = o.order_id
                      JOIN report r ON r.study_id = st.study_id AND r.jenis = 'AWAL' AND r.status = 'FINAL'
                      WHERE o.prioritas = ?) t");
            $st->execute([$cfg[$p], $p]);
            $r = $st->fetch();
            $n = (int)$r['n'];
            $tat[$p] = [
                'target_menit' => $cfg[$p],
                'jumlah_selesai' => $n,
                'rata_menit' => $n ? round((float)$r['rata_menit'], 1) : null,
                'maks_menit' => $n ? round((float)$r['maks_menit'], 1) : null,
                'persen_dalam_target' => $n ? round(100 * (int)$r['dalam_target'] / $n, 1) : null,
            ];
        }

        $st = $pdo->query("SELECT AVG(TIMESTAMPDIFF(SECOND, o.ordered_at, s.started_at)) / 60 AS tunggu,
                                  AVG(TIMESTAMPDIFF(SECOND, s.started_at, s.completed_at)) / 60 AS pemeriksaan,
                                  AVG(TIMESTAMPDIFF(SECOND, s.completed_at, r.finalized_at)) / 60 AS pembacaan, COUNT(*) n
                           FROM imaging_order o JOIN study s ON s.order_id = o.order_id
                           JOIN report r ON r.study_id = s.study_id AND r.jenis = 'AWAL' AND r.status = 'FINAL'");
        $t = $st->fetch();
        $tahap = (int)$t['n'] ? ['rata_tunggu_menit' => round((float)$t['tunggu'], 1), 'rata_pemeriksaan_menit' => round((float)$t['pemeriksaan'], 1), 'rata_pembacaan_menit' => round((float)$t['pembacaan'], 1)] : null;

        // Order terbuka yang sudah melewati target (risiko pelanggaran TAT)
        $lewat = [];
        foreach (['CITO', 'REGULER'] as $p) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM imaging_order WHERE prioritas = ? AND status <> 'REPORTED' AND TIMESTAMPDIFF(MINUTE, ordered_at, ?) >= ?");
            $st->execute([$p, $now, $cfg[$p]]);
            $lewat[$p] = (int)$st->fetchColumn();
        }
        return ['per_status' => $status, 'tat' => $tat, 'tahap_rata_rata' => $tahap, 'terbuka_lewat_target' => $lewat, 'dihitung_pada' => $now];
    }
}
