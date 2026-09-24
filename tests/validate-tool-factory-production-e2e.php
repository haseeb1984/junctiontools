<?php
declare(strict_types=1);

/**
 * Production-like Tool Factory E2E.
 *
 * Exercises both supported paths from search demand:
 *   1) existing capability -> SEO/content enhancement staging
 *   2) genuinely new capability -> approved generation
 *
 * This is an isolated dry-run. It never modifies the production registry,
 * sitemap, or source tool pages, and final approval remains publication-blocked.
 */
$root = dirname(__DIR__);
$php = PHP_BINARY;
$tmp = sys_get_temp_dir() . '/junctiontools-production-e2e-' . bin2hex(random_bytes(5));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "FAIL: unable to create E2E workspace\n");
    exit(1);
}
$cleanup = static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmp);
};
register_shutdown_function(static function () use ($cleanup, $tmp): void {
    if (getenv('JT_E2E_KEEP_WORKSPACE') === '1') {
        $pointerDir = dirname(__DIR__) . '/storage/e2e-test-ci';
        if (!is_dir($pointerDir)) @mkdir($pointerDir, 0775, true);
        @file_put_contents($pointerDir . '/production-e2e-workspace.txt', $tmp . PHP_EOL, LOCK_EX);
        return;
    }
    $cleanup();
});

$run = static function (string $script, array $args) use ($root, $php): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($root . '/' . ltrim($script, '/'));
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string) $arg);
    $lines = [];
    $status = 0;
    exec($cmd . ' 2>&1', $lines, $status);
    if ($status !== 0) {
        throw new RuntimeException("Stage failed: {$script} (exit {$status})\n" . implode(PHP_EOL, $lines));
    }
    return $lines;
};
$readJson = static function (string $file): array {
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) throw new RuntimeException("Invalid JSON: {$file}");
    return $data;
};
$writeJson = static function (string $file, array $data): void {
    if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write {$file}");
    }
};

try {
    $registry = $root . '/config/tools.json';
    $policyFile = $root . '/config/build-security-gate.json';
    $sourceEnhancementPage = $root . '/word-counter.html';
    $sitemap = $root . '/sitemap.xml';

    foreach ([$registry, $policyFile, $sourceEnhancementPage, $sitemap] as $file) {
        if (!is_file($file)) throw new RuntimeException("Required file missing: {$file}");
    }

    require_once $root . '/security/build-security-gate.php';
    require_once $root . '/security/adsense-compliance-gate.php';

    $adsenseGate = adsense_compliance_evaluate($root);
    if (($adsenseGate['allowed'] ?? false) !== true) {
        throw new RuntimeException('AdSense publication gate failed: ' . ($adsenseGate['error_code'] ?? 'adsense_rejected'));
    }

    $registryBefore = hash_file('sha256', $registry);
    $sitemapBefore = hash_file('sha256', $sitemap);
    $sourceBefore = hash_file('sha256', $sourceEnhancementPage);

    // 1. Search demand -> opportunity -> routing -> specs.
    $keywords = $tmp . '/google-keyword-planner.json';
    $demandOut = $tmp . '/demand';
    $writeJson($keywords, [
        'schema_version' => '1.0.0',
        'keywords' => [
            [
                'query' => 'free online word counter',
                'search_volume' => 12000,
                'country' => 'global',
                'language' => 'en',
                'competition' => 'LOW'
            ],
            [
                'query' => 'parking fee calculator',
                'search_volume' => 900,
                'country' => 'global',
                'language' => 'en',
                'competition' => 'LOW'
            ]
        ]
    ]);
    $run('tools/run-demand-refresh.php', [$keywords, $registry, $demandOut]);

    $queue = $readJson($demandOut . '/generation-queue.json');
    $specs = $readJson($demandOut . '/tool-specs.json');
    if (count($queue['queue'] ?? []) !== 2) throw new RuntimeException('Expected two routed demand records.');
    $enh = null; $new = null;
    foreach ($queue['queue'] as $row) {
        if (($row['core_query'] ?? '') === 'free online word counter') $enh = $row;
        if (($row['core_query'] ?? '') === 'parking fee calculator') $new = $row;
    }
    if (!is_array($enh) || ($enh['decision'] ?? '') !== 'enhancement' || ($enh['target_tool_slug'] ?? '') !== 'word-counter') {
        throw new RuntimeException('Existing demand did not route to Word Counter enhancement.');
    }
    if (!is_array($new) || ($new['decision'] ?? '') !== 'candidate' || ($new['target_tool_slug'] ?? null) !== null || ($new['recommended_slug'] ?? '') !== 'parking-fee-calculator') {
        throw new RuntimeException('New demand did not route to a new-tool candidate.');
    }
    if (count($specs['specifications'] ?? []) !== 2) throw new RuntimeException('Expected one enhancement spec and one new-tool spec.');

    $enhSpec = null; $newSpec = null;
    foreach ($specs['specifications'] as $spec) {
        if (($spec['spec_type'] ?? '') === 'enhancement') $enhSpec = $spec;
        elseif (($spec['tool']['slug'] ?? '') === 'parking-fee-calculator') $newSpec = $spec;
    }
    if (!is_array($enhSpec) || !is_array($newSpec)) throw new RuntimeException('Expected both enhancement and new-tool specifications.');

    // 2. Create exact, ephemeral approvals/security evidence for this E2E run.
    $policy = $readJson($policyFile);
    $decisions = [];
    foreach ([$enhSpec, $newSpec] as $spec) {
        $isEnh = (($spec['spec_type'] ?? '') === 'enhancement');
        $slug = (string) ($spec['tool']['slug'] ?? '');
        $decision = [
            'schema_version' => '1.0.0',
            'policy_version' => (string) ($policy['policy_version'] ?? ''),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'evaluated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'evaluator_id' => BUILD_SECURITY_GATE_EVALUATOR,
            'source' => [
                'opportunity_id' => 'production-like-e2e-' . $slug,
                'specification_slug' => $slug
            ],
            'type' => $isEnh ? 'enhancement' : 'new_tool',
            'decision' => 'allow',
            'safe_to_build' => true,
            'conditions' => $policy['required_conditions'] ?? [],
            'blocked_conditions' => [],
            'approval_requirements' => [
                'generation_approval' => !$isEnh,
                'enhancement_approval' => $isEnh
            ],
            'evidence' => [
                'spec_sha256' => bsg_sha256($spec),
                'policy_sha256' => bsg_sha256($policy)
            ]
        ];
        if ($isEnh) {
            $decision['enhancement'] = [
                'target_tool_slug' => 'word-counter',
                'scope' => [
                    'type' => 'seo',
                    'description' => 'E2E SEO enhancement only.',
                    'requested_capabilities' => ['search-intent-aligned-title','meta-description','on-page-content','how-to-use-content'],
                    'affected_components' => ['content','seo'],
                    'preserve_existing_functionality' => true
                ]
            ];
        }
        $decisions[] = $decision;
    }

    $securityDecisions = $tmp . '/security-decisions.json';
    $writeJson($securityDecisions, [
        'schema_version' => '1.0.0',
        'policy_version' => $policy['policy_version'],
        'decisions' => $decisions
    ]);

    $enhApprovals = $tmp . '/enhancement-approvals.json';
    $writeJson($enhApprovals, [
        'schema_version' => '1.0.0',
        'policy' => ['default' => 'deny', 'publication_requires_separate_review' => true],
        'approvals' => [[
            'cluster_id' => (string) ($enh['cluster_id'] ?? ''),
            'target_tool_slug' => 'word-counter',
            'decision' => 'approved-for-implementation-and-validation',
            'automatic_publication_allowed' => false,
            'registry_or_sitemap_modification_allowed' => false
        ]]
    ]);

    $generationApprovals = $tmp . '/generation-approvals.json';
    $writeJson($generationApprovals, [
        'schema_version' => '1.0.0',
        'policy' => ['default' => 'deny', 'publication_requires_separate_review' => true],
        'approvals' => [[
            'slug' => 'parking-fee-calculator',
            'approved' => true,
            'approved_by' => 'production-like-e2e',
            'approved_at' => gmdate('c')
        ]]
    ]);

    // 3. Security approval is exercised by both downstream consumers.
    $gateEnh = bsg_load_and_evaluate($enhSpec, $policyFile, $securityDecisions);
    if (($gateEnh['allowed'] ?? false) !== true) throw new RuntimeException('Security Gate rejected approved enhancement: ' . json_encode($gateEnh));
    $gateNew = bsg_load_and_evaluate($newSpec, $policyFile, $securityDecisions);
    if (($gateNew['allowed'] ?? false) !== true) throw new RuntimeException('Security Gate rejected approved new-tool spec: ' . json_encode($gateNew));

    // 4A. Existing-tool path: approved enhancement staging.
    $stagedDir = $tmp . '/staged';
    $run('tools/apply-approved-tool-enhancements.php', [
        $demandOut . '/tool-specs.json', $enhApprovals, $stagedDir, $policyFile, $securityDecisions, $registry
    ]);
    $staged = $stagedDir . '/word-counter.html';
    if (!is_file($staged)) throw new RuntimeException('Enhancement staging did not create word-counter.html.');
    if (hash_file('sha256', $sourceEnhancementPage) !== $sourceBefore) throw new RuntimeException('Enhancement modified production/source page.');
    $stagedHtml = (string) file_get_contents($staged);
    foreach (['<title>Free Online Word Counter | JunctionTools</title>', 'JT-SEO-ENHANCEMENT-START:word-counter', 'free online word counter'] as $needle) {
        if (stripos($stagedHtml, $needle) === false) throw new RuntimeException('Staged enhancement missing: ' . $needle);
    }
    preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', (string) file_get_contents($sourceEnhancementPage), $m1);
    preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $stagedHtml, $m2);
    if (($m1[1] ?? []) !== ($m2[1] ?? [])) throw new RuntimeException('Enhancement changed existing JavaScript runtime.');

    // 4B. New-tool path: approved generation.
    $generatedDir = $tmp . '/generated';
    $run('tools/generate-approved-tools.php', [
        $demandOut . '/tool-specs.json', $generationApprovals, $generatedDir, $policyFile, $securityDecisions, $registry
    ]);
    $generated = $generatedDir . '/parking-fee-calculator.html';
    if (!is_file($generated)) throw new RuntimeException('Approved new-tool generation did not create parking-fee-calculator.html.');

    // Generated pages reuse the shared header, which references this image,
    // and the generated shell references the shared favicon. Copy both static
    // deployment assets into the isolated browser fixture so browser E2E
    // observes the same local asset contract as production.
    foreach (['favicon.ico', 'junction-favicon.png'] as $asset) {
        $sourceAsset = $root . '/' . $asset;
        $targetAsset = $generatedDir . '/' . $asset;
        if (!is_file($sourceAsset)) {
            throw new RuntimeException('Required shared browser asset missing from repository: ' . $asset);
        }
        if (!copy($sourceAsset, $targetAsset)) {
            throw new RuntimeException('Unable to copy shared browser asset into E2E fixture: ' . $asset);
        }
    }

    // 5. Runtime validation of the actual generated artifact.
    $html = (string) file_get_contents($generated);
    foreach ([
        '<!DOCTYPE html>', '<html lang="en">', '<meta name="viewport"',
        '<main', '<h1', 'How to Use', 'id="run"', 'id="reset"', '<script>'
    ] as $needle) {
        if (stripos($html, $needle) === false) throw new RuntimeException('Generated runtime missing: ' . $needle);
    }
    foreach (['eval(', 'new Function(', 'fetch(', 'XMLHttpRequest', 'WebSocket(', 'localStorage', 'sessionStorage', 'document.write('] as $unsafe) {
        if (stripos($html, $unsafe) !== false) throw new RuntimeException('Unsafe runtime pattern found: ' . $unsafe);
    }
    foreach (['<iframe', '<frame', '<source', '<video', '<audio', '<img src="http://', '<img src="https://', '<img src="//'] as $external) {
        if (stripos($html, $external) !== false) throw new RuntimeException('External runtime resource found: ' . $external);
    }

    // 6. Final approval/deployment plans. Both remain publication-blocked.
    $newPlan = $tmp . '/new-deployment-plan.json';
    $run('tools/build-deployment-plan.php', [$demandOut . '/tool-specs.json', $generationApprovals, $newPlan]);
    $newPlanData = $readJson($newPlan);
    if (($newPlanData['plan_status'] ?? '') !== 'pending_publication_review' || ($newPlanData['publication_allowed'] ?? true) !== false) {
        throw new RuntimeException('New-tool final approval did not remain publication-blocked.');
    }
    if (($newPlanData['tools'][0]['slug'] ?? '') !== 'parking-fee-calculator') throw new RuntimeException('New-tool approval plan missing generated tool.');
    if (($newPlanData['policy']['adsense_validation_required'] ?? false) !== true || ($newPlanData['policy']['adsense_validation']['allowed'] ?? false) !== true) throw new RuntimeException('New-tool deployment plan did not carry a passing AdSense gate.');

    $enhPlan = $tmp . '/enhancement-deployment-plan.json';
    $run('tools/build-enhancement-deployment-plan.php', [$enhApprovals, $enhPlan]);
    $enhPlanData = $readJson($enhPlan);
    if (($enhPlanData['plan_status'] ?? '') !== 'pending_publication_review' || ($enhPlanData['publication_allowed'] ?? true) !== false) {
        throw new RuntimeException('Enhancement final approval did not remain publication-blocked.');
    }
    if (($enhPlanData['enhancements'][0]['target_tool_slug'] ?? '') !== 'word-counter') throw new RuntimeException('Enhancement approval plan missing Word Counter.');
    if (($enhPlanData['policy']['adsense_validation_required'] ?? false) !== true || ($enhPlanData['policy']['adsense_validation']['allowed'] ?? false) !== true) throw new RuntimeException('Enhancement deployment plan did not carry a passing AdSense gate.');

    // 7. Global invariant: no production state changed.
    if (hash_file('sha256', $registry) !== $registryBefore) throw new RuntimeException('Registry changed during production-like E2E.');
    if (hash_file('sha256', $sitemap) !== $sitemapBefore) throw new RuntimeException('Sitemap changed during production-like E2E.');

    echo "[PASS] Search demand -> opportunity routing -> specs.\n";
    echo "[PASS] Existing demand -> Word Counter SEO enhancement path.\n";
    echo "[PASS] New demand -> parking-fee-calculator generation path.\n";
    echo "[PASS] Security Gate approved both paths with exact SHA-256 evidence.\n";
    echo "[PASS] Approved enhancement staged without changing production source.\n";
    echo "[PASS] Approved new tool generated and runtime-validated.\n";
    echo "[PASS] AdSense publication gate passed and was enforced by both deployment plans.\n";
    echo "[PASS] Final approval/deployment plans created with publication blocked.\n";
    echo "[PASS] Registry, sitemap, and production source remained unchanged.\n";
    echo "Production-like Tool Factory E2E: PASS\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] " . $e->getMessage() . PHP_EOL);
    exit(1);
}
