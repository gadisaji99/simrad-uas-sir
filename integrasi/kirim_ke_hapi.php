<?php
declare(strict_types=1);
/**
 * Menyimpan (dan, bila diminta, mengirim) Bundle FHIR satu order. Menyambung eksperimen A4.
 *   php integrasi/kirim_ke_hapi.php <order_id>            -> hanya menyimpan bundle ke berkas JSON
 *   php integrasi/kirim_ke_hapi.php <order_id> --kirim    -> juga mengirim ke https://hapi.fhir.org/baseR4
 * HANYA data fiktif yang boleh dikirim ke server publik uji tersebut.
 * STATUS: bundle sudah divalidasi terhadap model FHIR R4B; pengiriman ke HAPI BELUM diuji oleh pengembang
 * (jaringan lingkungan pengembang dibatasi). Catat hasil pengiriman apa adanya, termasuk bila ditolak.
 * Dengan ifNoneExist, mengirim ulang bundle yang sama tidak membuat duplikat (di A4, payload identik sempat ditolak: HTTP 412).
 */
require __DIR__ . '/../src/bootstrap.php';
$id = (int)($argv[1] ?? 0);
if ($id < 1) { fwrite(STDERR, "Pemakaian: php integrasi/kirim_ke_hapi.php <order_id> [--kirim]\n"); exit(1); }
$json = json_encode(Fhir::bundle($id), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$berkas = __DIR__ . "/bundle_order_$id.json"; file_put_contents($berkas, $json);
echo "Bundle disimpan: $berkas (" . strlen($json) . " byte)\n";
if (!in_array('--kirim', $argv, true)) { echo "Mode simpan saja. Tambahkan --kirim untuk mengirim ke server uji publik HAPI.\n"; exit(0); }
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/fhir+json\r\nAccept: application/fhir+json\r\n", 'content' => $json, 'ignore_errors' => true, 'timeout' => 60]]);
$t = microtime(true); $res = @file_get_contents('https://hapi.fhir.org/baseR4', false, $ctx); $ms = (int)round((microtime(true) - $t) * 1000);
$status = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $status = (int)$m[1];
echo "Waktu: {$ms} ms; HTTP $status\n";
$r = json_decode((string)$res, true);
if (isset($r['entry'])) foreach ($r['entry'] as $i => $e) echo '  entri ' . ($i + 1) . ': ' . ($e['response']['status'] ?? '-') . ' ' . ($e['response']['location'] ?? '') . "\n";
elseif ($res !== false) echo substr((string)$res, 0, 600) . "\n"; else echo "Tidak ada respons (periksa koneksi internet).\n";
