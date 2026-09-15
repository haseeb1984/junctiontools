<?php
/**
 * Safe HTTP client for future server-side scanners.
 * Keep all outbound HTTP(S) fetching behind this helper.
 */
require_once __DIR__ . '/url-validator.php';

function jt_fetch_url(string $url, array $options = []): array
{
    [$valid, $normalized] = jt_validate_external_url($url);
    if (!$valid) {
        return ['ok' => false, 'error' => $normalized, 'status' => 0, 'body' => ''];
    }

    $timeout = min(max((int)($options['timeout'] ?? 10), 1), 15);
    $maxBytes = min(max((int)($options['max_bytes'] ?? 2_000_000), 16_384), 5_000_000);
    $userAgent = (string)($options['user_agent'] ?? 'JunctionTools-SafeScanner/1.0');

    $ch = curl_init($normalized);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.1'],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $error ?: 'HTTP request failed.', 'status' => $status, 'body' => ''];
    }

    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'error' => 'Remote response is too large.', 'status' => $status, 'body' => ''];
    }

    return [
        'ok' => ($status >= 200 && $status < 400),
        'error' => ($status >= 200 && $status < 400) ? '' : 'Remote server returned HTTP ' . $status . '.',
        'status' => $status,
        'content_type' => $contentType,
        'body' => $body,
    ];
}
