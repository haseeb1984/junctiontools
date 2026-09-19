<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$urlValidator = (string) file_get_contents($root . '/security/url-validator.php');
$safeHttp = (string) file_get_contents($root . '/security/safe-http.php');

$required = [
    [$urlValidator, 'function jt_resolve_public_ips', 'public DNS resolution helper'],
    [$urlValidator, 'DNS_A | DNS_AAAA', 'A/AAAA resolution'],
    [$safeHttp, 'CURLOPT_RESOLVE', 'DNS pinning'],
    [$safeHttp, 'jt_resolve_public_ips($host)', 'request-time DNS pinning'],
    [$safeHttp, 'CURLOPT_FOLLOWLOCATION => false', 'redirect blocking'],
    [$safeHttp, 'CURLOPT_SSL_VERIFYPEER => true', 'TLS certificate verification'],
    [$safeHttp, 'CURLOPT_SSL_VERIFYHOST => 2', 'TLS hostname verification'],
];

foreach ($required as [$source, $needle, $label]) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing SSRF hardening contract: {$label}\n");
        exit(1);
    }
}

require_once $root . '/security/url-validator.php';

foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '::1'] as $ip) {
    if (!jt_is_private_or_reserved_ip($ip)) {
        fwrite(STDERR, "Private/reserved IP accepted: {$ip}\n");
        exit(1);
    }
}

foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'http://localhost/', 'http://user:pass@example.com/'] as $target) {
    [$ok] = jt_validate_external_url($target);
    if ($ok) {
        fwrite(STDERR, "Blocked SSRF target accepted: {$target}\n");
        exit(1);
    }
}

echo "SSRF hardening validation: PASS\n";
