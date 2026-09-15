<?php
/**
 * SSRF-safe outbound HTTP client.
 *
 * All server-side URL fetching should use this helper. Redirects are disabled
 * deliberately so a validated public URL cannot pivot to a private address.
 */
require_once __DIR__ . '/url-validator.php';

function jt_fetch_url(string $url, array $options = []): array
{
    [$valid, $normalized] = jt_validate_external_url($url);
    if (!$valid) {
        return ['ok' => false, 'error' => $normalized, 'status' => 0, 'body' => '', 'headers' => '', 'content_type' => ''];
    }

    $timeout = min(max((int)($options['timeout'] ?? 10), 1), 15);
    $connectTimeout = min(max((int)($options['connect_timeout'] ?? 5), 1), 10);
    $maxBytes = min(max((int)($options['max_bytes'] ?? 2_000_000), 16_384), 5_000_000);
    $userAgent = (string)($options['user_agent'] ?? 'JunctionTools-SafeScanner/1.0');

    $ch = curl_init($normalized);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Unable to initialize HTTP client.', 'status' => 0, 'body' => '', 'headers' => '', 'content_type' => ''];
    }

    curl_setopt_array($ch, [
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
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => $error ?: 'HTTP request failed.', 'status' => $status, 'body' => '', 'headers' => '', 'content_type' => $contentType];
    }

    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'error' => 'Remote response is too large.', 'status' => $status, 'body' => '', 'headers' => $headers, 'content_type' => $contentType];
    }

    return [
        'ok' => ($status >= 200 && $status < 400),
        'error' => ($status >= 200 && $status < 400) ? '' : 'Remote server returned HTTP ' . $status . '.',
        'status' => $status,
        'content_type' => $contentType,
        'headers' => $headers,
        'body' => $body,
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
    ];
}
