<?php
// Place beside ssm-jelas-status.html and ssm-jelas-lookup.php on PHP hosting.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405, ['kind' => 'unavailable']);
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) respond(415, ['kind' => 'unavailable']);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 512) respond(413, ['kind' => 'unavailable']);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $host = parse_url($origin, PHP_URL_HOST);
    $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!$host || !$requestHost || strcasecmp($host, $requestHost) !== 0) respond(403, ['kind' => 'unavailable']);
}
$input = json_decode(file_get_contents('php://input', false, null, 0, 512), true);
if (!is_array($input) || !is_string($input['number'] ?? null)) respond(400, ['kind' => 'validation_error']);
require_once __DIR__ . '/ssm-jelas-lookup.php';
$number = onestop_ssm_normalize_number($input['number']);
if ($number === '') respond(400, ['kind' => 'validation_error', 'message' => 'Please enter a valid registration number format.']);

// Anonymous read-only endpoint: at most 20 requests per remote address per hour.
$key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
$limitFile = sys_get_temp_dir() . '/ssm_jelas_limit_' . $key;
$handle = @fopen($limitFile, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) respond(503, ['kind' => 'unavailable']);
$stored = stream_get_contents($handle);
$state = json_decode($stored ?: '{}', true);
$now = time();
if (!is_array($state) || ($state['start'] ?? 0) < $now - 3600) $state = ['start' => $now, 'count' => 0];
if ($state['count'] >= 20) { flock($handle, LOCK_UN); fclose($handle); respond(429, ['kind' => 'unavailable']); }
$state['count']++;
rewind($handle); ftruncate($handle, 0);
fwrite($handle, json_encode($state)); fflush($handle);
flock($handle, LOCK_UN); fclose($handle);

try {
    $outcome = onestop_ssm_orchestrate_lookup($number, 'healthy', 'onestop_ssm_raw_lookup');
    $result = $outcome['response'] ?? [];
    if (($result['mode'] ?? '') === 'not_found') respond(200, ['kind' => 'not_found']);
    if (($result['mode'] ?? '') !== 'verified' || !is_array($result['data'] ?? null)) respond(503, ['kind' => 'unavailable']);
    $data = $result['data'];
    $sourceStatus = trim((string) ($data['status'] ?? ''));
    if (preg_match('/\bEXPIRED\b/i', $sourceStatus)) $kind = 'expired_unknown';
    elseif (preg_match('/\bACTIVE\b/i', $sourceStatus) && !preg_match('/\bINACTIVE\b/i', $sourceStatus)) $kind = 'active';
    else $kind = 'status_unknown';
    respond(200, [
        'kind' => $kind,
        'businessName' => trim((string) ($data['entityName'] ?? '')),
        'newRegistrationNumber' => trim((string) ($data['newRegistrationNumber'] ?? '')),
        'oldRegistrationNumber' => trim((string) ($data['registrationNumber'] ?? '')),
        'registrationNumber' => trim((string) (!empty($data['newRegistrationNumber']) ? $data['newRegistrationNumber'] : ($data['registrationNumber'] ?? $number))),
        'sourceStatus' => $sourceStatus,
    ]);
} catch (Throwable $error) {
    error_log('[SSM Jelas] Lookup failed: ' . $error->getMessage());
    respond(503, ['kind' => 'unavailable']);
}
