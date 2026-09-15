<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$evaluator = $root . '/tools/evaluate-automatic-publishing.php';
$policyFile = $root . '/config/automatic-publishing-policy.json';

foreach ([$evaluator, $policyFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing required file: {$file}\n");
        exit(1);
    }
}

$policy = json_decode((string) file_get_contents($policyFile), true);
if (!is_array($policy)) {
    fwrite(STDERR, "Invalid automatic publishing policy.\n");
    exit(1);
}

if (($policy['automatic_publish']['enabled'] ?? true) !== false) {
    fwrite(STDERR, "Production automatic publishing must remain disabled.\n");
    exit(1);
}
if (($policy['default_decision'] ?? null) !== 'review') {
    fwrite(STDERR, "Default decision must remain review.\n");
    exit(1);
}

$plan = [
    'schema_version' => '1.0.0',
    'status' => 'pending_publication_review',
    'publication_allowed' => false,
    'production_publish_allowed' => false,
    'tools' => [
        [
            'slug' => 'phase9-integration-safe',
            'priority_score' => 95,
            'generation_eligible' => true,
            'generated_quality' => true,
            'generated_runtime' => true,
            'human_publication_review' => true,
            'registry_validation' => true,
            'sitemap_validation' => true,
            'post_publish_validation' => true,
        ],
        [
            'slug' => 'phase9-integration-blocked',
            'priority_score' => 99,
            'generation_eligible' => true,
            'generated_quality' => true,
            'generated_runtime' => false,
            'human_publication_review' => true,
            'registry_validation' => true,
            'sitemap_validation' => true,
            'post_publish_validation' => true,
        ],
    ],
];

$tmpDir = sys_get_temp_dir() . '/junctiontools-phase9-' . bin2hex(random_bytes(5));
if (!mkdir($tmpDir, 0700, true)) {
    fwrite(STDERR, "Unable to create temporary directory.\n");
    exit(1);
}
$planFile = $tmpDir . '/deployment-plan.json';
$enabledPolicyFile = $tmpDir . '/enabled-policy.json';
$outputFile = $tmpDir . '/decision.json';
file_put_contents($planFile, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$run = static function (string $policyPath) use ($evaluator, $planFile, $outputFile): int {
    $cmd = 'php ' . escapeshellarg($evaluator) . ' ' . escapeshellarg($planFile) . ' ' . escapeshellarg($policyPath) . ' ' . escapeshellarg($outputFile);
    exec($cmd, $lines, $code);
    return $code;
};

if ($run($policyFile) !== 0) {
    fwrite(STDERR, "Evaluator failed with repository policy.\n");
    exit(1);
}

$decision = json_decode((string) file_get_contents($outputFile), true);
if (!is_array($decision) || count($decision['decisions'] ?? []) !== 2) {
    fwrite(STDERR, "Unexpected evaluator output.\n");
    exit(1);
}
foreach ($decision['decisions'] as $item) {
    if (($item['decision'] ?? null) !== 'review-required' || ($item['automatic_publish'] ?? true) !== false) {
        fwrite(STDERR, "Disabled production policy was not enforced.\n");
        exit(1);
    }
}

$enabledPolicy = $policy;
$enabledPolicy['automatic_publish']['enabled'] = true;
file_put_contents($enabledPolicyFile, json_encode($enabledPolicy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($run($enabledPolicyFile) !== 0) {
    fwrite(STDERR, "Evaluator failed with enabled test policy.\n");
    exit(1);
}

$decision = json_decode((string) file_get_contents($outputFile), true);
$bySlug = [];
foreach ($decision['decisions'] as $item) {
    $bySlug[$item['slug']] = $item;
}

if (($bySlug['phase9-integration-safe']['decision'] ?? null) !== 'auto-publish-eligible') {
    fwrite(STDERR, "Fully gated deployment plan was not eligible under the enabled test policy.\n");
    exit(1);
}
if (($bySlug['phase9-integration-blocked']['decision'] ?? null) !== 'review-required') {
    fwrite(STDERR, "Incomplete deployment plan incorrectly became eligible.\n");
    exit(1);
}

@unlink($planFile);
@unlink($enabledPolicyFile);
@unlink($outputFile);
@rmdir($tmpDir);

echo "Phase 9 deployment-plan integration: PASS\n";
