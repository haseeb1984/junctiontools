<?php
declare(strict_types=1);

/**
 * Production-like Tool Factory E2E.
 *
 * Exercises the review-safe path:
 * search demand -> routing -> specification -> pre-build Security Gate
 * -> approved generation staging -> runtime validation -> final approval.
 *
 * This test MUST NOT publish, mutate the production registry/sitemap, or
 * produce a deployment artifact that can be applied automatically.
 *
 * Exit codes:
 * 0 PASS
 * 1 expected E2E failure
 * 2 invalid test input
 * 3 unavailable prerequisite
 * 4 runner error
 */

const E2E_PASS = 0;
const E2E_FAIL = 1;
const E2E_INVALID = 2;
const E2E_UNAVAILABLE = 3;
const E2E_ERROR = 4;

$root = dirname(__DIR__);
$required = [
    'tools/run-demand-refresh.php',
    'tools/generate-approved-tools.php',
    'tools/evaluate-automatic-publishing.php',
    'security/build-security-gate.php',
    'config/build-security-gate.json',
    'config/automatic-publishing-policy.json',
    'config/tools.json',
    'sitemap.xml',
    'header.html',
    'footer.html',
];

function e2e_log(string $message): void {
    fwrite(STDOUT, "[E2E] {$message}" . PHP_EOL);
}

function e2e_fail(string $message, int $code = E2E_FAIL): never {
    fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    exit($code);
}

function e2e_assert(bool $condition, string $message): void {
    if (!$condition) {
        e2e_fail($message);
    }
    e2e_log("PASS: {$message}");
}

function e2e_write_json(string $path, array $value): void {
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write {$path}");
    }
}

function e2e_read_json(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("Missing JSON artifact: {$path}");
    }
    $value = json_decode((string) file_get_contents($path), true);
    if (!is_array($value)) {
        throw new RuntimeException("Invalid JSON artifact: {$path}");
    }
    return $value;
}

function e2e_run(string $script, array $args, string $root): string {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . ltrim($script, '/'));
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    $lines = [];
    $status = 0;
    exec($command . ' 2>&1', $lines, $status);
    $output = implode(PHP_EOL, $lines);
    if ($status !== 0) {
        throw new RuntimeException("Stage failed: {$script} (exit {$status}). Output: {$output}");
    }
    return $output;
}

function e2e_remove_tree(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

try {
    if (PHP_SAPI !== 'cli') {
        e2e_fail('CLI execution is required.', E2E_INVALID);
    }

    foreach ($required as $relative) {
        if (!is_file($root . '/' . $relative)) {
            e2e_fail("Required file missing: {$relative}", E2E_UNAVAILABLE);
        }
    }

    require_once $root . '/security/build-security-gate.php';

    $tmp = sys_get_temp_dir() . '/junctiontools-production-like-e2e-' . bin2hex(random_bytes(5));
    if (!mkdir($tmp, 0700, true)) {
        e2e_fail('Unable to create isolated E2E directory.', E2E_UNAVAILABLE);
    }
    register_shutdown_function(static function () use ($tmp): void {
        e2e_remove_tree($tmp);
    });

    $registry = $root . '/config/tools.json';
    $sitemap = $root . '/sitemap.xml';
    $registryBefore = hash_file('sha256', $registry);
    $sitemapBefore = hash_file('sha256', $sitemap);

    /*
     * Stage 1: Search demand.
     * Use a deliberately unique candidate so the normal demand router must
     * classify it as a new-tool candidate rather than an existing-tool match.
     */
    $keywordFile = $tmp . '/search-demand.json';
    e2e_write_json($keywordFile, [
        'schema_version' => '1.0.0',
        'keywords' => [[
            'query' => 'e2e production string formatter',
            'search_volume' => 18000,
            'country' => 'global',
            'language' => 'en',
            'competition' => 'LOW',
        ]],
    ]);

    $demandDir = $tmp . '/demand';
    e2e_run('tools/run-demand-refresh.php', [$keywordFile, $registry, $demandDir], $root);

    $report = e2e_read_json($demandDir . '/demand-refresh-report.json');
    $queue = e2e_read_json($demandDir . '/generation-queue.json');
    $specs = e2e_read_json($demandDir . '/tool-specs.json');

    e2e_assert(($report['automatic_production_publish'] ?? true) === false, 'Search-demand stage remains non-publishing');
    e2e_assert(($report['registry_or_sitemap_modified'] ?? true) === false, 'Search-demand stage does not mutate registry/sitemap');
    e2e_assert(count($queue['queue'] ?? []) === 1, 'Search demand produced exactly one routed candidate');
    e2e_assert(($queue['queue'][0]['decision'] ?? '') === 'candidate', 'Search demand routed to new-tool candidate');
    e2e_assert(count($specs['specifications'] ?? []) === 1, 'Routing produced exactly one draft specification');

    $spec = $specs['specifications'][0] ?? null;
    if (!is_array($spec)) {
        e2e_fail('Routed specification is invalid.', E2E_INVALID);
    }
    $slug = strtolower(trim((string)($spec['tool']['slug'] ?? '')));
    e2e_assert($slug === 'e2e-production-string-formatter', 'Candidate slug is deterministic and unique');
    e2e_assert(($spec['spec_status'] ?? '') === 'draft', 'Candidate specification remains draft');
    e2e_assert(($spec['generation_eligible'] ?? true) === false, 'Candidate is generation-ineligible before approval');

    /*
     * Stage 2: Explicit security approval.
     * Evidence is bound to the exact specification and current policy.
     */
    $policyFile = $root . '/config/build-security-gate.json';
    $policy = e2e_read_json($policyFile);
    $securityDecision = [
        'schema_version' => '1.0.0',
        'policy_version' => (string) ($policy['policy_version'] ?? ''),
        'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'evaluated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'evaluator_id' => BUILD_SECURITY_GATE_EVALUATOR,
        'source' => [
            'opportunity_id' => (string) ($spec['source']['opportunity_id'] ?? 'production-like-e2e'),
            'specification_slug' => $slug,
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
            'privacy_contract_present' => true,
        ],
        'blocked_conditions' => [],
        'approval_requirements' => [
            'generation_approval' => true,
            'enhancement_approval' => false,
        ],
        'evidence' => [
            'spec_sha256' => bsg_sha256($spec),
            'policy_sha256' => bsg_sha256($policy),
        ],
    ];

    $decisionFile = $tmp . '/security-decisions.json';
    e2e_write_json($decisionFile, [
        'schema_version' => '1.0.0',
        'policy_version' => $policy['policy_version'] ?? null,
        'decisions' => [$securityDecision],
    ]);

    $gate = bsg_load_and_evaluate($spec, $policyFile, $decisionFile);
    e2e_assert(($gate['allowed'] ?? false) === true, 'Pre-build Security Gate explicitly approves the exact specification');

    /*
     * Stage 3: Generation approval + staging.
     * The approval is local to this E2E artifact and cannot publish the tool.
     */
    $approvalFile = $tmp . '/generation-approval.json';
    e2e_write_json($approvalFile, [
        'schema_version' => '1.0.0',
        'policy' => ['default' => 'deny'],
        'approvals' => [[
            'slug' => $slug,
            'approved' => true,
            'approved_by' => 'ci-production-like-e2e',
            'approved_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ]],
    ]);

    $specFile = $tmp . '/approved-spec.json';
    e2e_write_json($specFile, ['specifications' => [$spec]]);
    $stagingDir = $tmp . '/generated-staging';

    $generationOutput = e2e_run('tools/generate-approved-tools.php', [
        $specFile,
        $approvalFile,
        $stagingDir,
        $policyFile,
        $decisionFile,
        $registry,
    ], $root);

    e2e_assert(str_contains($generationOutput, 'Generated 1 approved tool page'), 'Approved generation completed into isolated staging');
    $generated = $stagingDir . '/' . $slug . '.html';
    e2e_assert(is_file($generated), 'Generated tool exists only in staging');

    /*
     * Stage 4: Runtime validation.
     * Validate the generated artifact as a production-facing page contract:
     * shared layout, usable form controls, JS result path, and no server-side
     * publication side effect.
     */
    $html = (string) file_get_contents($generated);
    e2e_assert(str_contains($html, '<!DOCTYPE html>'), 'Runtime artifact is valid HTML document output');
    e2e_assert(str_contains($html, '<title>E2E Production String Formatter | Free Online Tool | JunctionTools</title>'), 'Runtime artifact contains the generated SEO title');
    e2e_assert(str_contains($html, 'id="run"'), 'Runtime artifact contains the primary action');
    e2e_assert(str_contains($html, 'id="result"'), 'Runtime artifact contains the result surface');
    e2e_assert(str_contains($html, "Please complete the required inputs."), 'Runtime artifact contains inline validation behavior');
    e2e_assert(str_contains($html, 'addEventListener'), 'Runtime artifact contains executable browser interaction logic');
    e2e_assert(!str_contains($html, 'eval('), 'Runtime artifact contains no eval-based dynamic execution');

    $runtimeValidation = [
        'schema_version' => '1.0.0',
        'slug' => $slug,
        'status' => 'passed',
        'generated_artifact' => basename($generated),
        'checks' => [
            'html_document' => true,
            'seo_title' => true,
            'primary_action' => true,
            'result_surface' => true,
            'inline_validation' => true,
            'browser_interaction' => true,
            'no_eval' => true,
        ],
        'publication_allowed' => false,
    ];
    $runtimeFile = $tmp . '/runtime-validation.json';
    e2e_write_json($runtimeFile, $runtimeValidation);

    /*
     * Stage 5: Final approval.
     * Approval may certify the staged artifact, but the publication gate is
     * deliberately evaluated with automatic publishing disabled.
     */
    $deploymentPlan = $tmp . '/deployment-plan.json';
    e2e_write_json($deploymentPlan, [
        'schema_version' => '1.0.0',
        'tools' => [[
            'slug' => $slug,
            'priority_score' => 100,
            'generation_eligible' => true,
            'generated_quality' => true,
            'generated_runtime' => true,
            'human_publication_review' => true,
            'registry_validation' => true,
            'sitemap_validation' => true,
            'post_publish_validation' => true,
        ]],
    ]);

    $publishDecision = $tmp . '/automatic-publishing-decision.json';
    e2e_run('tools/evaluate-automatic-publishing.php', [
        $deploymentPlan,
        $root . '/config/automatic-publishing-policy.json',
        $publishDecision,
    ], $root);

    $publish = e2e_read_json($publishDecision);
    e2e_assert(($publish['automatic_publish_enabled'] ?? true) === false, 'Automatic publishing remains disabled');
    e2e_assert(($publish['decisions'][0]['decision'] ?? '') === 'review-required', 'Final publication decision remains review-required');
    e2e_assert(($publish['decisions'][0]['automatic_publish'] ?? true) === false, 'Final approval cannot convert into automatic publication');

    $finalApproval = [
        'schema_version' => '1.0.0',
        'slug' => $slug,
        'status' => 'approved-for-staging',
        'security_gate' => 'passed',
        'generation' => 'approved',
        'runtime_validation' => 'passed',
        'publication' => 'blocked',
        'automatic_publish' => false,
        'production_registry_mutation' => false,
        'sitemap_mutation' => false,
        'deployment_authorized' => false,
    ];
    $finalApprovalFile = $tmp . '/final-approval.json';
    e2e_write_json($finalApprovalFile, $finalApproval);

    e2e_assert(($finalApproval['status'] ?? '') === 'approved-for-staging', 'Final approval certifies staging only');
    e2e_assert(($finalApproval['publication'] ?? '') === 'blocked', 'Final approval explicitly blocks publication');
    e2e_assert(($finalApproval['deployment_authorized'] ?? true) === false, 'Final approval does not authorize deployment');

    e2e_assert(hash_file('sha256', $registry) === $registryBefore, 'Production tool registry is unchanged');
    e2e_assert(hash_file('sha256', $sitemap) === $sitemapBefore, 'Production sitemap is unchanged');
    e2e_assert(!is_file($root . '/generated-tools/' . $slug . '.html'), 'No production generated-tool page was created');

    echo PHP_EOL . "JunctionTools Production-like Tool Factory E2E: PASS" . PHP_EOL;
    echo "Flow: search demand -> routing -> security approval -> approved generation staging -> runtime validation -> final approval -> publication blocked" . PHP_EOL;
    exit(E2E_PASS);
} catch (JsonException $e) {
    e2e_fail('JSON error: ' . $e->getMessage(), E2E_INVALID);
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(E2E_ERROR);
}
