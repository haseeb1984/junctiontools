<?php
/**
 * SSRF-safe URL validation helpers for server-side fetchers.
 *
 * Only HTTP(S) URLs are allowed. Credentials, non-standard ports, localhost,
 * private/reserved IPs and common cloud metadata endpoints are rejected.
 */

function jt_is_private_or_reserved_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    return true;
}

function jt_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return jt_is_private_or_reserved_ip($host) ? [] : [$host];
    }

    $host = strtolower(rtrim($host, '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        return [];
    }

    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (!$records) {
        return [];
    }

    $ips = [];
    foreach ($records as $record) {
        $ip = $record['ip'] ?? ($record['ipv6'] ?? null);
        if (!is_string($ip) || jt_is_private_or_reserved_ip($ip)) {
            return [];
        }
        $ips[] = $ip;
    }

    return array_values(array_unique($ips));
}

function jt_resolves_to_public_ip(string $host): bool
{
    return jt_resolve_public_ips($host) !== [];
}


function jt_validate_external_url(string $url, bool $httpsOnly = false): array
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
        return [false, 'Invalid or oversized URL.'];
    }

    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return [false, 'Please provide a valid URL.'];
    }

    $scheme = strtolower($parts['scheme']);
    if ($httpsOnly ? $scheme !== 'https' : !in_array($scheme, ['http', 'https'], true)) {
        return [false, 'Only HTTP(S) URLs are allowed.'];
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return [false, 'URLs containing credentials are not allowed.'];
    }

    if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
        return [false, 'Non-standard ports are not allowed.'];
    }

    $host = strtolower(rtrim($parts['host'], '.'));
    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)
        && !filter_var($host, FILTER_VALIDATE_IP)) {
        return [false, 'Invalid hostname.'];
    }

    $blockedHosts = [
        '169.254.169.254',
        'metadata.google.internal',
        'metadata.google.com',
        'instance-data.ec2.internal',
    ];
    if (in_array($host, $blockedHosts, true)) {
        return [false, 'Target host is not allowed.'];
    }

    if (!jt_resolves_to_public_ip($host)) {
        return [false, 'Target host must resolve only to public IP addresses.'];
    }

    return [true, $url];
}

function jt_validate_domain(string $domain): array
{
    $domain = trim($domain);
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = rtrim($domain, '/');

    if ($domain === '' || strlen($domain) > 253 || str_contains($domain, '@')) {
        return [false, 'Invalid domain.'];
    }

    if (str_contains($domain, '/') || str_contains($domain, '?') || str_contains($domain, '#') || str_contains($domain, ':')) {
        return [false, 'Please provide a hostname only.'];
    }

    if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $domain)) {
        return [false, 'Invalid domain name.'];
    }

    if (!jt_resolves_to_public_ip($domain)) {
        return [false, 'Target domain must resolve only to public IP addresses.'];
    }

    return [true, strtolower($domain)];
}
