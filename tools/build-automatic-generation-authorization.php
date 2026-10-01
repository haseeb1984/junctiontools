<?php
declare(strict_types=1);

/**
 * Convert already-evaluated Security Gate decisions into a transient build
 * authorization. This is machine authorization only; it is NOT human approval
 * and it never authorizes production publication.
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-automatic-generation-authorization.php <specs.json> <security-decisions.json> [output.json]\n");
    exit(2);
}
$specFile = $argv[1];
$decisionFile = $argv[2];
$outputFile = $argv[3] ?? dirname(__DIR__) . '/config/.tool-factory-build-authorization.json';

foreach ([$specFile, $decisionFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Input missing: {$file}\n");
        exit(1);
    }
}
$specs = json_decode((string)file_get_contents($specFile), true);
$decisions = json_decode((string)file_get_contents($decisionFile), true);
if (!is_array($specs) || !is_array($specs['specifications'] ?? null) ||
    !is_array($decisions) || !is_array($decisions['decisions'] ?? null)) {
    fwrite(STDERR, "Invalid authorization inputs.\n");
    exit(1);
}

$decisionBySlug = [];
foreach ($decisions['decisions'] as $decision) {
    if (!is_array($decision)) continue;
    $slug = strtolower(trim((string)($decision['source']['specification_slug'] ?? '')));
    if ($slug !== '') $decisionBySlug[$slug] = $decision;
}

$approvals = [];
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) continue;
    $slug = strtolower(trim((string)($spec['tool']['slug'] ?? '')));
    if ($slug === '') continue;
    $decision = $decisionBySlug[$slug] ?? null;
    if (!is_array($decision) || ($decision['decision'] ?? '') !== 'allow' ||
        ($decision['safe_to_build'] ?? false) !== true) {
        fwrite(STDERR, "Build authorization denied by Security Gate: {$slug}\n");
        exit(1);
    }
    $type = (($spec['spec_type'] ?? '') === 'enhancement') ? 'enhancement' : 'new_tool';
    if ($type !== 'new_tool') continue;
    $approvals[] = [
        'slug' => $slug,
        'approved' => true,
        'authorization_type' => 'automated-security-gate',
        'evaluator_id' => (string)($decision['evaluator_id'] ?? 'build-security-gate-v1'),
        'authorized_at' => (string)($decision['evaluated_at'] ?? gmdate('c')),
        'spec_sha256' => (string)($decision['evidence']['spec_sha256'] ?? ''),
        'type' => $type,
        'human_approval' => false,
        'publication_authorized' => false
    ];
}

$result = [
    'schema_version' => '2.0.0',
    'policy' => [
        'description' => 'Transient machine authorization derived only from an allow/safe_to_build Security Gate decision.',
        'default' => 'deny',
        'human_approval_required_for_generation' => false,
        'publication_requires_separate_human_review' => true,
        'automatic_production_publish' => false
    ],
    'approvals' => $approvals
];
$dir = dirname($outputFile);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}
file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);
echo "Built automated build authorization for ".count($approvals)." Security-Gate-approved candidate(s). Human publication approval remains required.\n";
