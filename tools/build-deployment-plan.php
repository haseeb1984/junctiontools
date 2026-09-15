<?php
declare(strict_types=1);

/** Compile an approval-gated deployment plan without publishing or changing the registry/sitemap. */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-deployment-plan.php <specs.json> <approvals.json> [output.json]\n");
    exit(2);
}

$specFile = $argv[1];
$approvalFile = $argv[2];
$outputFile = $argv[3] ?? dirname(__DIR__) . '/config/tool-deployment-plan.json';

if (!is_file($specFile) || !is_file($approvalFile)) {
    fwrite(STDERR, "Input file missing.\n");
    exit(1);
}

$specs = json_decode((string) file_get_contents($specFile), true);
$approvals = json_decode((string) file_get_contents($approvalFile), true);
if (!is_array($specs) || !is_array($specs['specifications'] ?? null) || !is_array($approvals) || !is_array($approvals['approvals'] ?? null)) {
    fwrite(STDERR, "Invalid deployment-plan input.\n");
    exit(1);
}

if (($approvals['policy']['default'] ?? 'deny') !== 'deny' || ($approvals['policy']['publication_requires_separate_review'] ?? true) !== true) {
    fwrite(STDERR, "Unsafe approval policy: deployment requires deny-by-default and separate publication review.\n");
    exit(1);
}

$approved = [];
foreach ($approvals['approvals'] as $item) {
    if (!is_array($item)) {
        continue;
    }
    $slug = strtolower(trim((string) ($item['slug'] ?? '')));
    if ($slug !== '' && ($item['approved'] ?? false) === true) {
        $approved[$slug] = true;
    }
}

$plan = [
    'schema_version' => '1.0.0',
    'plan_status' => 'pending_publication_review',
    'publication_allowed' => false,
    'generated_at' => gmdate('c'),
    'policy' => [
        'generation_requires_explicit_approval' => true,
        'publication_requires_separate_review' => true,
        'registry_changes' => 'manual_after_review',
        'sitemap_changes' => 'manual_after_review',
    ],
    'tools' => [],
];

foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) {
        continue;
    }
    $tool = $spec['tool'] ?? [];
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    if ($slug === '' || !isset($approved[$slug])) {
        continue;
    }
    if (($spec['spec_status'] ?? '') !== 'draft' || ($spec['generation_eligible'] ?? true) !== false) {
        fwrite(STDERR, "Refusing non-draft or generation-authorized spec: {$slug}\n");
        exit(1);
    }

    $plan['tools'][] = [
        'slug' => $slug,
        'name' => (string) ($tool['name'] ?? $slug),
        'implementation_template' => (string) ($tool['implementation_template'] ?? 'generic-form'),
        'artifact' => $slug . '.html',
        'validation' => [
            'generated_quality' => true,
            'generated_runtime' => true,
            'human_publication_review' => true,
        ],
        'publication' => [
            'registry_update_required' => true,
            'sitemap_update_required' => true,
            'production_publish_allowed' => false,
        ],
    ];
}

$dir = dirname($outputFile);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}

if (file_put_contents($outputFile, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write deployment plan.\n");
    exit(1);
}

echo 'Deployment plan compiled for ' . count($plan['tools']) . " approved tool(s). Publication remains disabled.\n";
