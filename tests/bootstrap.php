<?php
declare(strict_types=1);

/**
 * Phase 0 test bootstrap.
 *
 * Deliberately dependency-light: PR #1 does not require PHPUnit.
 */

const JT_TEST_ROOT = __DIR__ . '/..';
const JT_TEST_ARTIFACT_DIR = JT_TEST_ROOT . '/artifacts/test-results';

if (!is_dir(JT_TEST_ARTIFACT_DIR)) {
    @mkdir(JT_TEST_ARTIFACT_DIR, 0775, true);
}

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once JT_TEST_ROOT . '/security/url-validator.php';
require_once JT_TEST_ROOT . '/security/safe-http.php';
require_once JT_TEST_ROOT . '/security/rate-limit.php';

function jt_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function jt_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s Expected %s, got %s.',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function jt_test_json(string $json): array
{
    $data = json_decode($json, true);
    jt_test_assert(is_array($data), 'Response was not a JSON object.');
    return $data;
}

function jt_test_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialise cURL.');
    }

    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $header = trim($header);
            if ($header !== '' && str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'headers' => $responseHeaders,
        'curl_errno' => $errno,
        'curl_error' => $error,
    ];
}

function jt_test_scanner_url(): string
{
    $base = getenv('JUNCTIONTOOLS_TEST_BASE_URL');
    return rtrim($base ?: 'http://127.0.0.1:8080', '/');
}

function jt_test_report(string $path, array $report): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    file_put_contents(
        $path,
        json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL,
        LOCK_EX
    );
}

function jt_test_case(array &$results, string $name, callable $test): void
{
    $started = microtime(true);
    try {
        $test();
        $results[] = [
            'name' => $name,
            'status' => 'PASS',
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ];
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $results[] = [
            'name' => $name,
            'status' => 'FAIL',
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
            'error' => $e->getMessage(),
        ];
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

function jt_test_finish(string $suite, array $results, ?string $reportPath): never
{
    $passed = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'PASS'));
    $failed = count($results) - $passed;

    $report = [
        'suite' => $suite,
        'status' => $failed === 0 ? 'PASS' : 'FAIL',
        'passed' => $passed,
        'failed' => $failed,
        'total' => count($results),
        'results' => $results,
    ];

    if ($reportPath !== null) {
        jt_test_report($reportPath, $report);
    }

    echo PHP_EOL;
    echo sprintf("Suite: %s | PASS: %d | FAIL: %d | TOTAL: %d\n", $suite, $passed, $failed, count($results));
    echo $failed === 0 ? "RESULT: PASS\n" : "RESULT: FAIL\n";

    exit($failed === 0 ? 0 : 1);
}
