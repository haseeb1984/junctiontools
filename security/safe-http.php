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
    if ($certInfo === []) {
        return [];
    }

    $issuer = '';
    $expiresAt = '';
    $expiresTimestamp = null;

    // Prefer parsing the PEM certificate supplied by cURL. This is still the
    // certificate from the same TLS-verified cURL connection and is more
    // reliable across libcurl/OpenSSL builds than textual field formatting.
    $scan = static function ($value, ?string $key = null) use (&$scan, &$issuer, &$expiresAt, &$expiresTimestamp): void {
        if (is_array($value)) {
            foreach ($value as $entryKey => $item) {
                $scan($item, is_string($entryKey) ? $entryKey : null);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $line = trim($value);
        $normalizedKey = strtolower(trim((string)$key));

        // libcurl's CURLINFO_CERTINFO returns certificate fields as associative
        // entries such as "Issuer" and "Expire date". The values themselves
        // contain only the DN/date, so matching the scalar text alone cannot
        // recover the field name.
        if ($issuer === '' && $normalizedKey === 'issuer' && $line !== '') {
            $issuer = $line;
        }
        if ($expiresAt === '' && in_array($normalizedKey, ['expire date', 'not after', 'validto'], true) && $line !== '') {
            $expiresAt = $line;
        }

        if ($issuer === '' && preg_match('/^Issuer\s*:\s*(.+)$/i', $line, $m)) {
            $issuer = trim($m[1]);
        }
        if ($expiresAt === '' && preg_match('/^(?:Expire date|Not After|validTo)\s*:\s*(.+)$/i', $line, $m)) {
            $expiresAt = trim($m[1]);
        }

        if ($expiresTimestamp === null && preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $value, $m)) {
            $certificate = @openssl_x509_read($m[0]);
            if ($certificate !== false) {
                $parsed = @openssl_x509_parse($certificate);
                if (is_array($parsed)) {
                    $parsedIssuer = $parsed['issuer'] ?? [];
                    if ($issuer === '' && is_array($parsedIssuer)) {
                        $issuerParts = [];
                        foreach ($parsedIssuer as $parsedKey => $part) {
                            if (is_array($part)) {
                                $part = implode(', ', array_map('strval', $part));
                            }
                            $issuerParts[] = $parsedKey . '=' . (string)$part;
                        }
                        $issuer = implode(', ', $issuerParts);
                    }

                    $validToTime = $parsed['validTo_time'] ?? null;
                    if (is_int($validToTime) || is_float($validToTime)) {
                        $expiresTimestamp = (int)$validToTime;
                    } elseif (is_string($validToTime) && is_numeric(trim($validToTime))) {
                        $expiresTimestamp = (int)trim($validToTime);
                    }

                    if ($expiresTimestamp === null) {
                        $validTo = $parsed['validTo'] ?? null;
                        if (is_string($validTo) && trim($validTo) !== '') {
                            $parsedTime = strtotime(trim($validTo));
                            if ($parsedTime !== false) {
                                $expiresTimestamp = $parsedTime;
                            }
                        }
                    }

                    if ($expiresTimestamp !== null) {
                        $expiresAt = gmdate('D, d M Y H:i:s T', $expiresTimestamp);
                    }
                }
                @openssl_x509_free($certificate);
            }
        }
    };

    $scan($certInfo);

    if ($expiresTimestamp === null && $expiresAt !== '') {
        $parsedTime = strtotime(trim($expiresAt));
        if ($parsedTime !== false) {
            $expiresTimestamp = $parsedTime;
            $expiresAt = gmdate('D, d M Y H:i:s T', $expiresTimestamp);
        }
    }

    $daysRemaining = null;
    if ($expiresTimestamp !== null) {
        $daysRemaining = (int)floor(($expiresTimestamp - time()) / 86400);
    }

    if ($issuer === '' && $expiresAt === '' && $daysRemaining === null) {
        return [];
    }

    return [
        'issuer' => $issuer,
        'expires' => $expiresAt,
        'daysRemaining' => $daysRemaining,
        'expires_at' => $expiresAt,
        'expires_timestamp' => $expiresTimestamp,
        'days_remaining' => $daysRemaining,
    ];
}

function jt_certificate_debug_info(array $certInfo): array
{
    $debug = [];
    foreach ($certInfo as $index => $entry) {
        if (!is_array($entry)) {
            $debug[] = ['index' => $index, 'type' => get_debug_type($entry), 'value' => is_scalar($entry) ? (string)$entry : get_debug_type($entry)];
            continue;
        }

        $item = ['index' => $index, 'fields' => []];
        foreach ($entry as $key => $value) {
            if (!is_scalar($value)) {
                $item['fields'][$key] = get_debug_type($value);
                continue;
            }
            $text = trim((string)$value);
            if (preg_match('/BEGIN CERTIFICATE|END CERTIFICATE/i', $text)) {
                $item['fields'][$key] = '[PEM REDACTED]';
                continue;
            }
            if (strlen($text) > 512) {
                $text = substr($text, 0, 512) . '...[truncated]';
            }
            $item['fields'][$key] = $text;
        }
        $debug[] = $item;
    }
    return $debug;
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
    $certificateDebug = (is_array($certInfo) && getenv('JUNCTIONTOOLS_DEBUG_CERT') === '1') ? jt_certificate_debug_info($certInfo) : [];

    if ($raw === false) {
        $result = ['ok' => false, 'error' => $error ?: 'HTTP request failed.', 'status' => $status, 'body' => '', 'headers' => '', 'content_type' => $contentType, 'certificate' => $certificate];
        if ($certificateDebug !== []) {
            $result['certificate_debug'] = $certificateDebug;
        }
        return $result;
    }

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (strlen($body) > $maxBytes) {
        $result = ['ok' => false, 'error' => 'Remote response is too large.', 'status' => $status, 'body' => '', 'headers' => $headers, 'content_type' => $contentType, 'certificate' => $certificate];
        if ($certificateDebug !== []) {
            $result['certificate_debug'] = $certificateDebug;
        }
        return $result;
    }

    $result = [
        'ok' => ($status >= 200 && $status < 400),
        'error' => ($status >= 200 && $status < 400) ? '' : 'Remote server returned HTTP ' . $status . '.',
        'status' => $status,
        'content_type' => $contentType,
        'headers' => $headers,
        'body' => $body,
        'certificate' => $certificate,
    ];
    if ($certificateDebug !== []) {
        $result['certificate_debug'] = $certificateDebug;
    }
    return $result;
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
        'certificate_debug' => $result['certificate_debug'] ?? [],
    ];
}
