<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$options = getopt('', [
    'suite:',
    'report:',
    'slow-url:',
    'large-url:',
    'contact-url:',
]);

$suite = (string)($options['suite'] ?? 'all');
$reportPath = isset($options['report']) ? (string)$options['report'] : null;

function jt_suite_compatibility(): array
{
    $results = [];

    $clientTools = [
        'PageSpeed Analyzer' => 'pagespeed.html',
        'Image Compressor' => 'asset-optimizer.html',
        'GA4 Event Code Generator' => 'ga4-verifier.html',
        'CSS/JS Minifier' => 'css-js-minifier.html',
        'Invoice Generator' => 'invoice-generator.html',
        'Discount Calculator' => 'discount-calculator.html',
        'Aspect Ratio Calculator' => 'aspect-ratio-calculator.html',
        'Color Palette Generator' => 'color-palette-generator.html',
        'Social Asset Optimizer' => 'social-asset-optimizer.html',
        'Bio Font Generator' => 'bio-font-generator.html',
        'Clean Text Tool' => 'clean-text.html',
        'Case Converter' => 'case-converter.html',
        'Word Counter' => 'word-counter.html',
        'PX to REM' => 'px-to-rem.html',
        'Base64 Converter' => 'base64-converter.html',
        'JSON Formatter' => 'json-formatter.html',
        'Regex Tester' => 'regex-tester.html',
        'SHA-256 Hash' => 'sha256-hash.html',
        'UUID Generator' => 'uuid-generator.html',
        'WhatsApp Link' => 'whatsapp-direct.html',
    ];

    foreach ($clientTools as $name => $file) {
        jt_test_case($results, "35-tool compatibility: {$name}", static function () use ($file): void {
            $path = JT_TEST_ROOT . '/' . $file;
            jt_test_assert(is_file($path), "Expected tool page {$file} does not exist.");
            $html = file_get_contents($path);
            jt_test_assert(is_string($html) && trim($html) !== '', "Tool page {$file} is empty.");
            jt_test_assert(stripos($html, '<html') !== false || stripos($html, '<!doctype') !== false, "Tool page {$file} is not an HTML document.");
        });
    }

    $scannerTools = [
        'Pixel Diagnostic' => ['pixels', 'url'],
        'ARIA & Alt Audit' => ['aria_audit', 'url'],
        'Mobile Viewport UX' => ['mobile_audit', 'url'],
        'UX Layout Evaluator' => ['ux_evaluator', 'url'],
        'Cross-Browser Check' => ['browser_checker', 'url'],
        'Meta SEO Checker' => ['seo_auditor', 'url'],
        'Schema Validator' => ['schema_validator', 'url'],
        'Checkout Funnel Analyzer' => ['friction_analyzer', null],
        'Cart Loss Calculator' => ['cart_abandonment', null],
        'CTA & Headline Analyzer' => ['cta_analyzer', 'url'],
        'Trust Badge Inspector' => ['trust_inspector', 'url'],
        'SSL & Security Checker' => ['ssl_audit', 'url'],
        'Contrast Checker' => ['wcag_checker', 'url'],
        'Product Copy Analyzer' => ['copy_analyzer', 'url'],
        'Readability Evaluator' => ['readability_evaluator', 'url'],
    ];

    foreach ($scannerTools as $name => [$type, $mode]) {
        jt_test_case($results, "35-tool compatibility: {$name}", static function () use ($name, $type, $mode): void {
            $payload = ['type' => $type];
            if ($type === 'cart_abandonment') {
                $payload += ['total_carts' => 100, 'completed_orders' => 80, 'aov' => 50];
            } elseif ($type === 'friction_analyzer') {
                $payload += ['checkout_type' => '1step', 'required_fields' => 5, 'guest_option' => 'enabled'];
            } elseif ($type === 'schema_validator') {
                $payload['payload'] = '{"@context":"https://schema.org","@type":"Product","name":"Test"}';
            } elseif ($mode === 'url') {
                // Contract test only. The hardened endpoint must reject this local target before network access.
                $payload['url'] = 'http://127.0.0.1:8080/health.php';
            }

            if ($mode === 'url') {
                $url = jt_test_scanner_url();
                $response = jt_test_http($url . '/scanner.php', 'POST', json_encode($payload), ['Content-Type: application/json']);
                jt_test_assert(in_array($response['status'], [200, 400, 422], true), "{$name} returned unexpected HTTP status {$response['status']}.");
                $json = jt_test_json($response['body']);
                jt_test_assert(array_key_exists('success', $json), "{$name} response is missing success field.");
            } else {
                $url = jt_test_scanner_url();
                $response = jt_test_http($url . '/scanner.php', 'POST', json_encode($payload), ['Content-Type: application/json']);
                jt_test_assert($response['status'] === 200, "{$name} expected HTTP 200, got {$response['status']}.");
                $json = jt_test_json($response['body']);
                jt_test_assert(($json['success'] ?? false) === true, "{$name} did not return success=true.");
            }
        });
    }

    return $results;
}

function jt_suite_ssl(): array
{
    $results = [];

    jt_test_case($results, 'SSL helper rejects non-HTTPS certificate target', static function (): void {
        [$ok] = jt_validate_external_url('http://example.com');
        jt_test_assert($ok === true, 'HTTP URL validation unexpectedly failed before SSL policy test.');
        $response = jt_safe_http_get('http://example.com', ['certificate_info' => true, 'timeout' => 3, 'connect_timeout' => 2]);
        jt_test_assert(($response['certificate'] ?? null) === null || ($response['certificate']['issuer'] ?? null) === null, 'HTTP request must not produce trusted TLS certificate metadata.');
    });

    $sslUrl = getenv('JUNCTIONTOOLS_SSL_TEST_URL') ?: 'https://example.com';
    jt_test_case($results, 'SSL issuer metadata is present on a valid HTTPS connection', static function () use ($sslUrl): void {
        $response = jt_safe_http_get($sslUrl, ['certificate_info' => true, 'timeout' => 12, 'connect_timeout' => 5, 'max_bytes' => 262144]);
        jt_test_assert(($response['success'] ?? false) === true, 'Valid HTTPS smoke test failed: ' . ($response['message'] ?? 'unknown error'));
        $certificate = $response['certificate'] ?? null;
        jt_test_assert(is_array($certificate), 'Certificate metadata is missing.');
        jt_test_assert(trim((string)($certificate['issuer'] ?? '')) !== '', 'Certificate issuer is empty.');
        jt_test_assert(trim((string)($certificate['expires'] ?? '')) !== '', 'Certificate expiry is empty.');
        jt_test_assert(is_int($certificate['daysRemaining'] ?? null), 'daysRemaining is not an integer.');
        jt_test_assert(strtotime((string)$certificate['expires']) !== false, 'Certificate expiry is not a valid date.');
    });

    jt_test_case($results, 'SSL certificate metadata has internally consistent expiry/days remaining', static function () use ($sslUrl): void {
        $response = jt_safe_http_get($sslUrl, ['certificate_info' => true, 'timeout' => 12, 'connect_timeout' => 5, 'max_bytes' => 262144]);
        jt_test_assert(($response['success'] ?? false) === true, 'HTTPS request failed.');
        $certificate = $response['certificate'];
        $expiry = strtotime((string)$certificate['expires']);
        $expected = (int)floor(($expiry - time()) / 86400);
        jt_test_assert(abs($expected - (int)$certificate['daysRemaining']) <= 1, 'daysRemaining does not match certificate expiry within tolerance.');
    });

    return $results;
}

function jt_suite_ssrf(): array
{
    $results = [];
    $blocked = [
        'http://127.0.0.1/',
        'http://localhost/',
        'http://0.0.0.0/',
        'http://10.0.0.1/',
        'http://172.16.0.1/',
        'http://192.168.1.1/',
        'http://169.254.169.254/',
        'http://[::1]/',
        'http://[fc00::1]/',
        'http://[fe80::1]/',
        'file:///etc/passwd',
        'ftp://example.com/',
        'gopher://example.com/',
        'https://user:password@example.com/',
        'https://example.com:8080/',
    ];

    foreach ($blocked as $url) {
        jt_test_case($results, "SSRF blocked: {$url}", static function () use ($url): void {
            [$ok] = jt_validate_external_url($url);
            jt_test_assert($ok === false, "Unsafe URL was accepted: {$url}");
        });
    }

    return $results;
}

function jt_suite_redirects(): array
{
    $results = [];
    $url = getenv('JUNCTIONTOOLS_TEST_BASE_URL') ?: 'http://127.0.0.1:8080';

    jt_test_case($results, 'Safe HTTP client does not follow redirects', static function () use ($url): void {
        // The local fixture is intentionally a redirect. The response must remain 302.
        $response = jt_safe_http_get($url . '/redirect.php', ['timeout' => 5, 'connect_timeout' => 2, 'max_bytes' => 65536]);
        jt_test_assert(($response['status'] ?? 0) === 302, 'Redirect was followed or transformed into a successful response.');
    });

    return $results;
}

function jt_suite_limits(array $options): array
{
    $results = [];
    $slowUrl = (string)($options['slow-url'] ?? getenv('JUNCTIONTOOLS_TEST_BASE_URL') . '/slow-response.php?delay=10');
    $largeUrl = (string)($options['large-url'] ?? getenv('JUNCTIONTOOLS_TEST_BASE_URL') . '/large-response.php');

    jt_test_case($results, 'Safe HTTP client enforces timeout', static function () use ($slowUrl): void {
        $started = microtime(true);
        $response = jt_safe_http_get($slowUrl, ['timeout' => 1, 'connect_timeout' => 1, 'max_bytes' => 65536]);
        $elapsed = microtime(true) - $started;
        jt_test_assert(($response['success'] ?? true) === false, 'Slow response unexpectedly succeeded.');
        jt_test_assert($elapsed < 5, sprintf('Timeout test exceeded expected ceiling: %.2fs.', $elapsed));
    });

    jt_test_case($results, 'Safe HTTP client enforces response-size limit', static function () use ($largeUrl): void {
        $response = jt_safe_http_get($largeUrl, ['timeout' => 5, 'connect_timeout' => 2, 'max_bytes' => 2097152]);
        jt_test_assert(($response['success'] ?? true) === false, 'Oversized response unexpectedly succeeded.');
    });

    return $results;
}

function jt_suite_rate_limit(): array
{
    $results = [];
    $bucket = 'ci-' . bin2hex(random_bytes(8));

    for ($i = 1; $i <= 10; $i++) {
        jt_test_case($results, "Rate limit request {$i}/10 is allowed", static function () use ($bucket): void {
            jt_test_assert(jt_rate_limit($bucket, 10, 300), 'Expected request to be allowed.');
        });
    }

    jt_test_case($results, 'Rate limit request 11/10 is rejected', static function () use ($bucket): void {
        jt_test_assert(jt_rate_limit($bucket, 10, 300) === false, 'Expected rate limit rejection.');
    });

    return $results;
}

function jt_suite_contact(array $options): array
{
    $results = [];
    $url = (string)($options['contact-url'] ?? getenv('JUNCTIONTOOLS_CONTACT_TEST_URL') ?: '');

    if ($url === '') {
        jt_test_case($results, 'Contact endpoint configuration is present', static function (): void {
            throw new RuntimeException('JUNCTIONTOOLS_CONTACT_TEST_URL is not configured.');
        });
        return $results;
    }

    jt_test_case($results, 'Contact endpoint rejects GET', static function () use ($url): void {
        $response = jt_test_http($url, 'GET');
        jt_test_assert($response['status'] === 405, 'Expected GET to contact endpoint to return 405.');
    });

    jt_test_case($results, 'Contact endpoint rejects invalid email', static function () use ($url): void {
        $body = http_build_query(['name' => 'CI Test', 'email' => 'invalid', 'message' => 'test']);
        $response = jt_test_http($url, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
        jt_test_assert(in_array($response['status'], [400, 422], true), 'Invalid email was not rejected.');
    });

    jt_test_case($results, 'Contact endpoint rejects oversized request', static function () use ($url): void {
        $body = 'message=' . str_repeat('A', 40000);
        $response = jt_test_http($url, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
        jt_test_assert($response['status'] === 413, 'Oversized contact request was not rejected with 413.');
    });

    jt_test_case($results, 'Contact endpoint rejects honeypot submission', static function () use ($url): void {
        $body = http_build_query(['name' => 'CI Test', 'email' => 'ci@example.test', 'message' => 'test', 'website' => 'bot']);
        $response = jt_test_http($url, 'POST', $body, ['Content-Type: application/x-www-form-urlencoded']);
        jt_test_assert(in_array($response['status'], [400, 422, 303], true), 'Honeypot submission was not handled as a rejected/ignored request.');
    });

    return $results;
}

function jt_suite_http_security(): array
{
    $results = [];
    $url = jt_test_scanner_url() . '/scanner.php';

    jt_test_case($results, 'Scanner rejects GET', static function () use ($url): void {
        $response = jt_test_http($url, 'GET');
        jt_test_assert($response['status'] === 405, 'Scanner GET did not return 405.');
    });

    jt_test_case($results, 'Scanner rejects malformed JSON', static function () use ($url): void {
        $response = jt_test_http($url, 'POST', '{bad-json', ['Content-Type: application/json']);
        jt_test_assert($response['status'] === 400, 'Malformed JSON was not rejected with 400.');
    });

    return $results;
}

$dispatch = [
    'compatibility' => 'jt_suite_compatibility',
    'ssl' => 'jt_suite_ssl',
    'ssrf' => 'jt_suite_ssrf',
    'redirects' => 'jt_suite_redirects',
    'limits' => static fn(): array => jt_suite_limits($options),
    'rate-limit' => 'jt_suite_rate_limit',
    'contact' => static fn(): array => jt_suite_contact($options),
    'http-security' => 'jt_suite_http_security',
];

if ($suite === 'all') {
    $all = [];
    foreach ($dispatch as $name => $runner) {
        echo "\n=== {$name} ===\n";
        $all = array_merge($all, $runner());
    }
    jt_test_finish('all', $all, $reportPath);
}

if (!isset($dispatch[$suite])) {
    fwrite(STDERR, "Unknown suite: {$suite}\n");
    exit(2);
}

$results = $dispatch[$suite]();
jt_test_finish($suite, $results, $reportPath);
