<?php
declare(strict_types=1);

/** Build a review-only deployment plan for explicitly approved existing-tool enhancements. */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/build-enhancement-deployment-plan.php <approvals.json> [output.json]\n");
    exit(2);
}

$approvalFile = $argv[1];
$outputFile = $argv[2] ?? dirname(__DIR__) . '/config/tool-enhancement-deployment-plan.json';
$root = dirname(__DIR__);
$registryFile = $root . '/config/tools.json';

if (!is_file($approvalFile) || !is_file($registryFile)) {
    fwrite(STDERR, "Input file missing.\n");
    exit(1);
}

$approvals = json_decode((string) file_get_contents($approvalFile), true);
$registry = json_decode((string) file_get_contents($registryFile), true);
if (!is_array($approvals) || !is_array($registry) || !is_array($registry['tools'] ?? null)) {
    fwrite(STDERR, "Invalid enhancement deployment-plan input.\n");
    exit(1);
}

if (($approvals['policy']['default'] ?? 'deny') !== 'deny' || ($approvals['policy']['publication_requires_separate_review'] ?? true) !== true) {
    fwrite(STDERR, "Unsafe enhancement approval policy.\n");
    exit(1);
}

$registryBySlug = [];
foreach ($registry['tools'] as $tool) {
    if (is_array($tool) && isset($tool['slug'])) {
        $registryBySlug[strtolower(trim((string) $tool['slug']))] = $tool;
    }
}

$plan = [
    'schema_version' => '1.0.0',
    'plan_status' => 'pending_publication_review',
    'publication_allowed' => false,
    'generated_at' => gmdate('c'),
    'policy' => [
        'implementation_validation_approval_required' => true,
        'publication_requires_separate_review' => true,
        'registry_changes' => 'manual_after_review',
        'sitemap_changes' => 'manual_after_review',
        'production_publish_allowed' => false,
    ],
    'enhancements' => [],
];

foreach ($approvals['approvals'] ?? [] as $item) {
    if (!is_array($item)) continue;
    if (($item['decision'] ?? '') !== 'approved-for-implementation-and-validation') continue;
    $slug = strtolower(trim((string) ($item['target_tool_slug'] ?? '')));
    if ($slug === '' || !isset($registryBySlug[$slug])) {
        fwrite(STDERR, "Refusing enhancement without an existing registry tool: {$slug}\n");
        exit(1);
    }
    if (($item['automatic_publication_allowed'] ?? true) !== false || ($item['registry_or_sitemap_modification_allowed'] ?? true) !== false) {
        fwrite(STDERR, "Refusing enhancement with unsafe publication permissions: {$slug}\n");
        exit(1);
    }

    $tool = $registryBySlug[$slug];
    $plan['enhancements'][] = [
        'cluster_id' => (string) ($item['cluster_id'] ?? ''),
        'target_tool_slug' => $slug,
        'target_tool_name' => (string) ($tool['name'] ?? $slug),
        'frontend' => (string) ($tool['frontend'] ?? ''),
        'implementation_validation_approved' => true,
        'validation' => [
            'approval_gate' => true,
            'implementation_present' => true,
            'runtime_validation_required' => true,
            'human_publication_review_required' => true,
        ],
        'publication' => [
            'registry_update_required' => false,
            'sitemap_update_required' => false,
            'production_publish_allowed' => false,
        ],
    ];
}

$dir = dirname($outputFile);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}

file_put_contents($outputFile, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
echo 'Enhancement deployment plan compiled for ' . count($plan['enhancements']) . " approved enhancement(s). Publication remains disabled.\n";
