<?php
declare(strict_types=1);

/**
 * Minimal JunctionTools Tool Factory E2E smoke test.
 *
 * Exit codes:
 * 0 PASS
 * 1 expected test failure
 * 2 invalid input
 * 3 factory/environment unavailable
 * 4 runner error
 */

const E2E_PASS = 0;
const E2E_FAIL = 1;
const E2E_INVALID = 2;
const E2E_UNAVAILABLE = 3;
const E2E_ERROR = 4;

$root = dirname(__DIR__);
$fixture = $root . '/tests/fixtures/e2e-dummy-opportunity.json';
$generator = $root . '/tools/generate-approved-tools.php';
$gate = $root . '/security/build-security-gate.php';
$policyFile = $root . '/config/build-security-gate.json';

function e2e_fail(string $message, int $code = E2E_FAIL): never {
    fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    exit($code);
}
function e2e_pass(string $message): void {
    fwrite(STDOUT, "[PASS] {$message}" . PHP_EOL);
}
function e2e_write(string $path, mixed $value): void {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($path, $json . PHP_EOL) === false) {
        throw new RuntimeException("Unable to write {$path}");
    }
}
function e2e_remove_tree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

try {
    if (PHP_SAPI !== 'cli') e2e_fail('CLI execution is required.', E2E_INVALID);
    foreach ([$fixture, $generator, $gate, $policyFile] as $required) {
        if (!is_file($required)) e2e_fail("Required file missing: {$required}", E2E_UNAVAILABLE);
    }

    require_once $gate;

    $spec = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
    $policy = json_decode((string) file_get_contents($policyFile), true, 512, JSON_THROW_ON_ERROR);

    if (!is_array($spec) || !is_array($policy)) {
        e2e_fail('Fixture or policy is invalid JSON.', E2E_INVALID);
    }

    if (($spec['tool']['slug'] ?? '') !== 'word-counter') {
        e2e_fail('Unexpected real tool slug.', E2E_INVALID);
    }

    $preserveDir = getenv('JUNCTIONTOOLS_E2E_OUTPUT_DIR');
    if ($preserveDir !== false && trim($preserveDir) !== '') {
        $base = rtrim(trim($preserveDir), '/\\');
        if (is_dir($base)) {
            e2e_remove_tree($base);
        }
        if (!mkdir($base, 0700, true)) {
            e2e_fail('Unable to create configured E2E directory.', E2E_UNAVAILABLE);
        }
    } else {
        $base = sys_get_temp_dir() . '/jt-tool-factory-e2e-' . bin2hex(random_bytes(5));
        if (!mkdir($base, 0700, true)) {
            e2e_fail('Unable to create isolated E2E directory.', E2E_UNAVAILABLE);
        }
    }

    $specFile = $base . '/spec.json';
    $approvalFile = $base . '/approval.json';
    $decisionFile = $base . '/security-decision.json';
    $outputDir = $base . '/generated';

    /*
     * Current generator requires the specification wrapper and an approved
     * generation record. Current Security Gate additionally requires:
     * evaluator_id, policy_version, evaluated_at, source binding,
     * safe_to_build, all mandatory conditions, approval requirements,
     * spec_sha256 and policy_sha256.
     *
     * Hashes are calculated from the exact spec/policy used for this run,
     * so the fixture cannot accidentally contain stale approval evidence.
     */
    $decision = [
        'schema_version' => '1.0.0',
        'policy_version' => (string) ($policy['policy_version'] ?? ''),
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'evaluator_id' => BUILD_SECURITY_GATE_EVALUATOR,
        'source' => [
            'opportunity_id' => (string) ($spec['source']['opportunity_id'] ?? ''),
            'specification_slug' => (string) ($spec['tool']['slug'] ?? '')
        ],
        'type' => 'new_tool',
        'decision' => 'allow',
        'safe_to_build' => true,
        'conditions' => [
            'valid_spec' => true,
            'safe_slug' => true,
            'approved_template' => true,
            'no_dynamic_code_execution' => true,
            'no_credential_handling' => true,
            'no_unsafe_filesystem_access' => true,
            'no_arbitrary_url_fetch' => true,
            'no_unapproved_external_network_dependency' => true,
            'bounded_resource_usage' => true,
            'privacy_contract_present' => true
        ],
        'blocked_conditions' => [],
        'approval_requirements' => [
            'generation_approval' => true,
            'enhancement_approval' => false
        ],
        'evidence' => [
            'spec_sha256' => bsg_sha256($spec),
            'policy_sha256' => bsg_sha256($policy)
        ]
    ];

    e2e_write($specFile, ['specifications' => [$spec]]);
    e2e_write($approvalFile, [
        'schema_version' => '1.0.0',
        'policy' => ['default' => 'deny'],
        'approvals' => [[
            'slug' => 'word-counter',
            'approved' => true,
            'approved_by' => 'ci-e2e',
            'approved_at' => gmdate('Y-m-d\TH:i:s\Z')
        ]]
    ]);
    e2e_write($decisionFile, [
        'schema_version' => '1.0.0',
        'policy_version' => $policy['policy_version'],
        'decisions' => [$decision]
    ]);

    e2e_pass('Fixture loaded');
    e2e_pass('Security decision evidence generated for exact spec/policy');
    e2e_pass('Security decision contains evaluator, timestamp, conditions, approval and SHA-256 evidence');

    $command = escapeshellarg(PHP_BINARY) . ' ' .
        escapeshellarg($generator) . ' ' .
        escapeshellarg($specFile) . ' ' .
        escapeshellarg($approvalFile) . ' ' .
        escapeshellarg($outputDir) . ' ' .
        escapeshellarg($policyFile) . ' ' .
        escapeshellarg($decisionFile) . ' 2>&1';

    $lines = [];
    $status = 0;
    exec($command, $lines, $status);

    if ($status === 0) {
        e2e_fail('Duplicate existing tool was incorrectly generated.');
    }
    $output = implode(PHP_EOL, $lines);
    if (stripos($output, 'Duplicate existing tool rejected') === false) {
        e2e_fail('Generator rejected Word Counter, but not through the duplicate-existing-tool guard. Output: ' . $output);
    }
    e2e_pass('Existing Word Counter is blocked from duplicate generation');

    $generated = $outputDir . '/word-counter.html';
    if (is_file($generated)) {
        e2e_fail('Duplicate Word Counter HTML was created despite the guard.');
    }
    e2e_pass('No duplicate Word Counter HTML was created');
    
    e2e_remove_tree($base);
    e2e_pass('E2E temporary files cleaned');
    echo PHP_EOL . "JunctionTools Tool Factory E2E: PASS" . PHP_EOL;
    exit(E2E_PASS);
} catch (JsonException $e) {
    e2e_fail('JSON error: ' . $e->getMessage(), E2E_INVALID);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(E2E_ERROR);
}
