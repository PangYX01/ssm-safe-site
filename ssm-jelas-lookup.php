<?php
// Standalone adaptation of the lookup functions supplied in onestop.zip.
// PHP 8+, cURL and DOM extensions required.
declare(strict_types=1);
define('ONESTOP_SSM_CANARY_NUMBER', '201903123456');
define('ONESTOP_SSM_CANARY_OLD_NUMBER', 'AS0402639-H');
class SSM_Lookup_Error extends RuntimeException {
    public function __construct($code, $message) { parent::__construct((string) $message); }
}
function is_runtime_exception($value): bool { return $value instanceof SSM_Lookup_Error; }
function onestop_ssm_normalize_number($value) {
    $cleaned = strtoupper(trim((string) $value));

    if (preg_match('/^\d{12}$/', $cleaned)) {
        return $cleaned;
    }

    if (!preg_match('/^([A-Z0-9]{9})(?:-[A-Z]?)?$/', $cleaned, $matches)) {
        return '';
    }

    $base = $matches[1];

    if (
        preg_match('/^\d{9}$/', $base) ||
        preg_match('/^[A-Z]\d{8}$/', $base) ||
        preg_match('/^[A-Z]{2}\d{7}$/', $base)
    ) {
        return $base;
    }

    return '';
}

function onestop_ssm_parse_ssm_html($html) {
    if (!is_string($html) || trim($html) === '') {
        return new SSM_Lookup_Error(
            'empty_ssm_response',
            'SSM returned an empty response.'
        );
    }

    if (!class_exists('DOMDocument')) {
        return new SSM_Lookup_Error(
            'dom_unavailable',
            'The PHP DOM extension is unavailable.'
        );
    }

    $previousSetting = libxml_use_internal_errors(true);

    try {
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
    } catch (Throwable $error) {
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        return new SSM_Lookup_Error(
            'ssm_html_parse_error',
            $error->getMessage()
        );
    }

    libxml_clear_errors();
    libxml_use_internal_errors($previousSetting);

    if (!$loaded) {
        return new SSM_Lookup_Error(
            'ssm_html_parse_failed',
            'Unable to parse the SSM response.'
        );
    }

    return $dom;
}

function onestop_ssm_cleanup($curlHandle, $cookieFile) {
    $isCurlObject = class_exists('CurlHandle', false) && $curlHandle instanceof CurlHandle;

    if (is_resource($curlHandle) || $isCurlObject) {
        curl_close($curlHandle);
    }

    if (
        is_string($cookieFile) &&
        $cookieFile !== '' &&
        file_exists($cookieFile)
    ) {
        @unlink($cookieFile);
    }
}

function onestop_ssm_unavailable_result(
    $curlHandle,
    $cookieFile,
    $reason,
    $httpStatus = 0
) {
    onestop_ssm_cleanup($curlHandle, $cookieFile);

    return [
        'exists'      => false,
        'unavailable' => true,
        'reason'      => (string) $reason,
        'http_status' => (int) $httpStatus,
    ];
}

/**
 * Perform one raw e-Search lookup and return data instead of ending AJAX.
 */
function onestop_ssm_raw_lookup($number) {
    if (!function_exists('curl_init')) {
        return [
            'exists'      => false,
            'unavailable' => true,
            'reason'      => 'The PHP cURL extension is unavailable.',
            'http_status' => 0,
        ];
    }

    $ssmUrl = 'https://www.ssm.com.my/Pages/e-Search.aspx';

    $registrationTypes = [
        [
            'key'  => 'robNew',
            'name' => 'Business Registration Number New',
        ],
        [
            'key'  => 'rob',
            'name' => 'Business Registration Number Old',
        ],
    ];

    $cookieFile = tempnam(sys_get_temp_dir(), 'ssm_cookie_');

    if ($cookieFile === false) {
        return [
            'exists'      => false,
            'unavailable' => true,
            'reason'      => 'Unable to create the temporary SSM session.',
            'http_status' => 0,
        ];
    }

    $ch = curl_init();

    if ($ch === false) {
        @unlink($cookieFile);

        return [
            'exists'      => false,
            'unavailable' => true,
            'reason'      => 'Unable to initialise the SSM connection.',
            'http_status' => 0,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $ssmUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $html       = curl_exec($ch);
    $curlError  = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($html === false) {
        return onestop_ssm_unavailable_result(
            $ch,
            $cookieFile,
            $curlError ?: 'SSM connection failed.',
            $httpStatus
        );
    }

    if (trim((string) $html) === '') {
        return onestop_ssm_unavailable_result(
            $ch,
            $cookieFile,
            'SSM returned an empty response.',
            $httpStatus
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return onestop_ssm_unavailable_result(
            $ch,
            $cookieFile,
            'SSM returned HTTP ' . $httpStatus . '.',
            $httpStatus
        );
    }

    $dom = onestop_ssm_parse_ssm_html($html);

    if (is_runtime_exception($dom)) {
        return onestop_ssm_unavailable_result(
            $ch,
            $cookieFile,
            $dom->getMessage(),
            $httpStatus
        );
    }

    $xpath = new DOMXPath($dom);

    $viewState = $xpath->evaluate(
        "string(//input[@name='__VIEWSTATE']/@value)"
    );

    $eventValidation = $xpath->evaluate(
        "string(//input[@name='__EVENTVALIDATION']/@value)"
    );

    $viewStateGenerator = $xpath->evaluate(
        "string(//input[@name='__VIEWSTATEGENERATOR']/@value)"
    );

    if (
        trim($viewState) === '' ||
        trim($eventValidation) === '' ||
        trim($viewStateGenerator) === ''
    ) {
        return onestop_ssm_unavailable_result(
            $ch,
            $cookieFile,
            'The expected SSM hidden fields were not found.',
            $httpStatus
        );
    }

    $confirmedNotFoundCount = 0;

    foreach ($registrationTypes as $type) {
        $postFields = http_build_query([
            '__VIEWSTATE' => $viewState,
            '__EVENTVALIDATION' => $eventValidation,
            '__VIEWSTATEGENERATOR' => $viewStateGenerator,

            'ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$idDropRegistrationType'
                => $type['key'],

            'ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$txtCarieSearch'
                => $number,

            'ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$idBtneSearch'
                => 'Search Now',
        ]);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $ssmUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_REFERER        => $ssmUrl,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $postHtml   = curl_exec($ch);
        $curlError  = curl_error($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($postHtml === false) {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                $curlError ?: 'SSM validation request failed.',
                $httpStatus
            );
        }

        if (trim((string) $postHtml) === '') {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                'SSM returned an empty validation response.',
                $httpStatus
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                'SSM validation returned HTTP ' . $httpStatus . '.',
                $httpStatus
            );
        }

        $postDom = onestop_ssm_parse_ssm_html($postHtml);

        if (is_runtime_exception($postDom)) {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                $postDom->getMessage(),
                $httpStatus
            );
        }

        $postXpath = new DOMXPath($postDom);

        $noRecord = $postXpath->query(
            "//tr[contains(@class,'alert-info')]"
        );

        if ($noRecord && $noRecord->length > 0) {
            $confirmedNotFoundCount++;
            continue;
        }

        $rows = $postXpath->query(
            "//table[contains(@id,'GridViewRob')]/tr"
        );

        if (!$rows || $rows->length < 2) {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                'The SSM validation response structure was unexpected.',
                $httpStatus
            );
        }

        $cols = $postXpath->query('td', $rows->item(1));

        if (!$cols || $cols->length < 5) {
            return onestop_ssm_unavailable_result(
                $ch,
                $cookieFile,
                'The expected SSM business fields were missing.',
                $httpStatus
            );
        }

        $data = [
            'registrationNumber' => trim(
                $cols->item(0)->textContent ?? ''
            ),
            'newRegistrationNumber' => trim(
                $cols->item(1)->textContent ?? ''
            ),
            'entityName' => trim(
                $cols->item(2)->textContent ?? ''
            ),
            'status' => trim(
                $cols->item(3)->textContent ?? ''
            ),
            'gstNumber' => trim(
                $cols->item(4)->textContent ?? ''
            ) ?: null,
        ];

        onestop_ssm_cleanup($ch, $cookieFile);

        return [
            'exists'      => true,
            'unavailable' => false,
            'type'        => $type['name'],
            'data'        => $data,
            'http_status' => $httpStatus,
        ];
    }

    onestop_ssm_cleanup($ch, $cookieFile);

    if ($confirmedNotFoundCount === count($registrationTypes)) {
        return [
            'exists'      => false,
            'unavailable' => false,
            'http_status' => $httpStatus,
        ];
    }

    return [
        'exists'      => false,
        'unavailable' => true,
        'reason'      => 'SSM verification returned an unexpected result.',
        'http_status' => $httpStatus,
    ];
}

function onestop_ssm_result_is_verified($result) {
    return is_array($result) &&
        ($result['exists'] ?? false) === true &&
        ($result['unavailable'] ?? false) !== true &&
        isset($result['data']) &&
        is_array($result['data']);
}

function onestop_ssm_result_is_not_found($result) {
    return is_array($result) &&
        ($result['exists'] ?? null) === false &&
        ($result['unavailable'] ?? true) === false;
}

function onestop_ssm_result_has_old_registration($result) {
    return onestop_ssm_result_is_verified($result) &&
        trim((string) ($result['data']['registrationNumber'] ?? '')) !== '';
}

/**
 * Canary validation intentionally checks only the four approved conditions.
 */
function onestop_ssm_is_canary_healthy($result) {
    if (!onestop_ssm_result_is_verified($result)) {
        return false;
    }

    $newNumber = trim((string) ($result['data']['newRegistrationNumber'] ?? ''));
    $oldNumber = trim((string) ($result['data']['registrationNumber'] ?? ''));

    return $newNumber === ONESTOP_SSM_CANARY_NUMBER &&
        $oldNumber === ONESTOP_SSM_CANARY_OLD_NUMBER;
}

function onestop_ssm_verified_response($result) {
    return [
        'mode'                => 'verified',
        'health'              => 'healthy',
        'exists'              => true,
        'unavailable'         => false,
        'verified'            => true,
        'verification_marker' => 'Verified',
        'data'                => $result['data'],
        'message'             => '',
    ];
}

function onestop_ssm_not_found_response() {
    return [
        'mode'                => 'not_found',
        'health'              => 'healthy',
        'exists'              => false,
        'unavailable'         => false,
        'verified'            => false,
        'verification_marker' => '',
        'data'                => null,
        'message'             => "Registration Number doesn't exist, please make sure it is NON SDN BHD.",
    ];
}

function onestop_ssm_fallback_response($health, $result = null) {
    $data = null;

    if (onestop_ssm_result_is_verified($result)) {
        $data = [
            'registrationNumber' => trim(
                (string) ($result['data']['registrationNumber'] ?? '')
            ),
        ];
    }

    return [
        'mode'                => 'fallback',
        'health'              => $health === 'offline' ? 'offline' : 'healthy',
        'exists'              => false,
        'unavailable'         => true,
        'verified'            => false,
        'verification_marker' => 'Unverified',
        'data'                => $data,
        'message'             => 'SSM verification is temporarily unavailable. Please enter the Business Name manually. You may continue after completing the required field.',
    ];
}

/**
 * Decide the response mode. The callback makes this function unit-testable.
 */
function onestop_ssm_orchestrate_lookup($number, $healthState, $lookupCallback) {
    $healthState = $healthState === 'offline' ? 'offline' : 'healthy';
    $userResult = call_user_func($lookupCallback, $number);

    if ($healthState === 'offline') {
        if (onestop_ssm_result_has_old_registration($userResult)) {
            $canaryResult = call_user_func(
                $lookupCallback,
                ONESTOP_SSM_CANARY_NUMBER
            );

            if (onestop_ssm_is_canary_healthy($canaryResult)) {
                return [
                    'next_health' => 'healthy',
                    'reason'      => 'Canary record recovered.',
                    'response'    => onestop_ssm_verified_response($userResult),
                ];
            }
        }

        return [
            'next_health' => 'offline',
            'reason'      => (string) ($userResult['reason'] ?? 'Recovery not confirmed.'),
            'response'    => onestop_ssm_fallback_response('offline', $userResult),
        ];
    }

    if (onestop_ssm_result_is_verified($userResult)) {
        return [
            'next_health' => 'healthy',
            'reason'      => 'Visitor lookup succeeded.',
            'response'    => onestop_ssm_verified_response($userResult),
        ];
    }

    $canaryResult = call_user_func(
        $lookupCallback,
        ONESTOP_SSM_CANARY_NUMBER
    );

    if (!onestop_ssm_is_canary_healthy($canaryResult)) {
        return [
            'next_health' => 'offline',
            'reason'      => (string) ($canaryResult['reason'] ?? 'Canary identity did not match.'),
            'response'    => onestop_ssm_fallback_response('offline', $userResult),
        ];
    }

    if (onestop_ssm_result_is_not_found($userResult)) {
        return [
            'next_health' => 'healthy',
            'reason'      => 'Canary succeeded; visitor number was not found.',
            'response'    => onestop_ssm_not_found_response(),
        ];
    }

    $retryResult = call_user_func($lookupCallback, $number);

    if (onestop_ssm_result_is_verified($retryResult)) {
        return [
            'next_health' => 'healthy',
            'reason'      => 'Canary succeeded and visitor retry succeeded.',
            'response'    => onestop_ssm_verified_response($retryResult),
        ];
    }

    if (onestop_ssm_result_is_not_found($retryResult)) {
        return [
            'next_health' => 'healthy',
            'reason'      => 'Canary succeeded; visitor retry was not found.',
            'response'    => onestop_ssm_not_found_response(),
        ];
    }

    return [
        'next_health' => 'healthy',
        'reason'      => 'Canary succeeded but the visitor lookup remained abnormal.',
        'response'    => onestop_ssm_fallback_response('healthy', $retryResult),
    ];
}

