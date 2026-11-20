<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function keluar(int $kode, array $data): never { http_response_code($kode); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
/** Ambil bilangan bulat positif dari input; tolak jika bukan angka. */
function ambilInt(array $in, string $k): int {
    $v = $in[$k] ?? null;
    if (is_int($v) && $v > 0) return $v;
    if (is_string($v) && ctype_digit($v) && (int)$v > 0) return (int)$v;
    throw new ApiError(422, 'INPUT_TIDAK_VALID', "Field '$k' wajib berupa bilangan bulat positif.");
}
function ambilStr(array $in, string $k, bool $wajib = true): string {
    $v = $in[$k] ?? '';
    if (!is_string($v)) throw new ApiError(422, 'INPUT_TIDAK_VALID', "Field '$k' harus berupa teks.");
    if ($wajib && trim($v) === '') throw new ApiError(422, 'INPUT_TIDAK_VALID', "Field '$k' wajib diisi.");
    return $v;
}

try {
    $metode = $_SERVER['REQUEST_METHOD'];
    $rute = $_GET['r'] ?? '';
    $raw = file_get_contents('php://input');
    $in = [];
    if ($raw !== '' && $raw !== false) {
        $in = json_decode($raw, true);
        if (!is_array($in)) throw new ApiError(400, 'JSON_TIDAK_VALID', 'Isi permintaan bukan JSON yang valid.');
    }
    $kunci = $metode . ' ' . $rute;

    if ($kunci === 'POST login') {
        $u = Auth::login(ambilStr($in, 'username'), ambilStr($in, 'password'));
        keluar(200, ['pengguna' => $u]);
    }
    if ($kunci === 'POST logout') { Auth::logout(); keluar(200, ['ok' => true]); }

    $u = Auth::user(); // semua rute di bawah ini wajib login
    switch ($kunci) {
        case 'GET me':           keluar(200, ['pengguna' => $u]);
        case 'GET patients':     keluar(200, ['data' => Workflow::patients($u, (string)($_GET['q'] ?? ''))]);
        case 'GET procedures':   keluar(200, ['data' => Workflow::procedures()]);
        case 'GET modalities':   keluar(200, ['data' => Workflow::modalities()]);
        case 'GET orders':       keluar(200, ['data' => Workflow::listOrders($u, $_GET['status'] ?? null, $_GET['prioritas'] ?? null, $_GET['q'] ?? null)]);
        case 'GET orders.detail':keluar(200, Workflow::orderDetail($u, ambilInt($_GET, 'id')));
        case 'GET metrics':      keluar(200, Metrics::summary());
        case 'GET pacs.status':  keluar(200, ['online' => Pacs::online()]);
        case 'POST pacs.set':    keluar(200, Pacs::setOnline($u, (bool)($in['online'] ?? false)));
        case 'GET mwl.daftar':   keluar(200, Mwl::daftar($u, $_GET['modality'] ?? null, $_GET['aet'] ?? null));
        case 'POST hl7.terima':  keluar(200, Integration::hl7Terima($u, ambilStr($in, 'er7')));
        case 'POST patients.create': keluar(201, Workflow::createPatient($u, $in));
        case 'POST studies.kirim_ulang': keluar(200, Workflow::kirimUlangPacs($u, ambilInt($in, 'study_id')));
        case 'GET hl7.order':    keluar(200, Integration::hl7Order($u, ambilInt($_GET, 'order_id')));
        case 'GET hl7.hasil':    keluar(200, Integration::hl7Hasil($u, ambilInt($_GET, 'order_id')));
        case 'GET fhir.bundle':  header('Content-Type: application/fhir+json; charset=utf-8'); keluar(200, Integration::fhirBundle($u, ambilInt($_GET, 'order_id')));
        case 'POST orders.create':
            keluar(201, Workflow::createOrder($u, ambilInt($in, 'patient_id'), ambilInt($in, 'procedure_id'), ambilStr($in, 'prioritas')));
        case 'POST orders.schedule': keluar(200, Workflow::schedule($u, ambilInt($in, 'order_id')));
        case 'POST orders.start':    keluar(200, Workflow::start($u, ambilInt($in, 'order_id'), ambilInt($in, 'modality_id')));
        case 'POST orders.cito':     keluar(200, Workflow::markCito($u, ambilInt($in, 'order_id')));
        case 'POST studies.complete':
            keluar(200, Workflow::complete($u, ambilInt($in, 'study_id'), ambilInt($in, 'jumlah_instance'), (bool)($in['kualitas_baik'] ?? true), ambilStr($in, 'body_part', false)));
        case 'POST reports.save':
            keluar(200, Workflow::saveReport($u, ambilInt($in, 'study_id'), ambilStr($in, 'isi_temuan', false), ambilStr($in, 'kesimpulan', false), (bool)($in['finalize'] ?? false)));
        default: throw new ApiError(404, 'RUTE_TIDAK_ADA', 'Rute tidak dikenal.');
    }
} catch (ApiError $e) {
    keluar($e->status, ['error' => ['kode' => $e->kode, 'pesan' => $e->getMessage()]]);
} catch (Throwable $e) {
    error_log('[SIMRAD] ' . $e->getMessage()); // detail hanya ke log server, tidak ke pengguna
    keluar(500, ['error' => ['kode' => 'GALAT_SERVER', 'pesan' => 'Terjadi kesalahan pada server.']]);
}
