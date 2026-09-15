<?php
/**
 * SSRF-safe outbound HTTP client.
 *
 * All server-side URL fetching should use this helper. Redirects are disabled
 * deliberately so a validated public URL cannot pivot to a private address.
 */
require_once __DIR__ . '/url-validator.php';

function jt_parse_certificate_info(array $certInfo): array
{
    $issuer = '';
    $expiresAt = '';
    $expiresTimestamp = null;

    // CURLOPT_CERTINFO normally returns one array per certificate in the
    // verified chain. Be tolerant of the exact nested representation returned
    // by different libcurl/OpenSSL builds while only consuming text supplied by
    // cURL for this already-verified connection.
    $scan = static function ($value) use (&$scan, &$issuer, &$expiresAt): void {
        if (is_array($value)) {
            foreach ($value as $item) {
                $scan($item);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $line = trim($value);
        if ($issuer === '' && preg_match('/^Issuer\s*:\s*(.+)$/i', $line, $m)) {
            $issuer = trim($m[1]);
        }
        if ($expiresAt === '' && preg_match('/^(?:Expire date|Not After)\s*:\s*(.+)$/i', $line, $m)) {
            $expiresAt = trim($m[1]);
        }
    };

    $scan($certInfo);

    if ($expiresAt !== '') {
        try {
            $date = new DateTimeImmutable($expiresAt);
            $expiresTimestamp = $date->getTimestamp();
        } catch (Throwable $e) {
            $expiresTimestamp = null;
        }
    }

    $daysRemaining = null;
    if ($expiresTimestamp !== null) {
        $daysRemaining = (int)floor(($expiresTimestamp - time()) / 86400);
    }

    // Public names match the test/API contract; snake_case aliases preserve
    // compatibility for callers that use conventional PHP field naming.
    return [
        'issuer' => $issuer,
        'expires' => $expiresAt,
        'daysRemaining' => $daysRemaining,
        'expires_at' => $expiresAt,
        'expires_timestamp' => $expiresTimestamp,
        'days_remaining' => $daysRemaining,
    ];
}

function jt_fetch_url(string $url, array $options = []): array
{
    [$valid, $normalized] = jt_validate_external_url($url);
    if (!$valid) {
        return ['ok' => false, 'error' => $normalized, 'status' => 0, 'body' => '', 'headers' => '', 'content_type' => '', 'certificate' => []];
    }

    $timeout = min(max((int)($options['timeout'] ?? 10), 1), 15);
    $connectTimeout = min(max((int)($options['connect_timeout'] ?? 5), 1), 10);
    $maxBytes = min(max((int)($options['max_bytes'] ?? 2_000_000), 16_384), 5_000_000);
    $userAgent = (string)($options['user_agent'] ?? 'JunctionTools-SafeScanner/1.0');
    $captureCertificate = (($options['certificate_info'] ?? false) === true) && str_starts_with(strtolower($normalized), 'https://');

    $ch = curl_init($normalized);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Unable to initialize HTTP client.', 'status' => 0, 'body' => '', 'headers' => '', 'content_type' => '', 'certificate' => []];
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.1'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_ENCODING => '',
    ];

    // Certificate metadata is collected from the same TLS-verified cURL
    // connection. This avoids a second raw socket/DNS operation and therefore
    // preserves the SSRF protections enforced by jt_validate_external_url().
    if ($captureCertificate && defined('CURLOPT_CERTINFO')) {
        $curlOptions[CURLOPT_CERTINFO] = true;
    }

    curl_setopt_array($ch, $curlOptions);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $certInfo = ($captureCertificate && defined('CURLINFO_CERTINFO')) ? curl_getinfo($ch, CURLINFO_CERTINFO) : [];
    $error = curl_error($ch);
    curl_close($ch);

    $certificate = is_array($certInfo) ? jt_parse_certificate_info($certInfo) : [];

    if ($raw === false) {
        return ['ok' => false, 'error' => $error ?: 'HTTP request failed.', 'status' => $status, 'body' => '', 'headers' => '', 'content_type' => $contentType, 'certificate' => $certificate];
    }

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'error' => 'Remote response is too large.', 'status' => $status, 'body' => '', 'headers' => $headers, 'content_type' => $contentType, 'certificate' => $certificate];
    }

    return [
        'ok' => ($status >= 200 && $status < 400),
        'error' => ($status >= 200 && $status < 400) ? '' : 'Remote server returned HTTP ' . $status . '.',
        'status' => $status,
        'content_type' => $contentType,
        'headers' => $headers,
        'body' => $body,
        'certificate' => $certificate,
    ];
}

function jt_safe_http_get(string $url, array $options = []): array
{
    $result = jt_fetch_url($url, $options);
    return [
        'success' => $result['ok'],
        'message' => $result['error'],
        'status' => $result['status'],
        'content_type' => $result['content_type'],
        'headers' => $result['headers'],
        'body' => $result['body'],
        'certificate' => $result['certificate'] ?? [],
    ];
}
