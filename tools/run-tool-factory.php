<?php
declare(strict_types=1);

$pipelinePath = $argv[1] ?? __DIR__ . '/../config/tool-factory-pipeline.json';
$outputPath = $argv[2] ?? __DIR__ . '/../config/tool-factory-run.json';

if (!is_file($pipelinePath)) {
    fwrite(STDERR, "Pipeline config not found: {$pipelinePath}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($pipelinePath), true);
if (!is_array($data) || !isset($data['stages']) || !is_array($data['stages'])) {
    fwrite(STDERR, "Invalid pipeline configuration.\n");
    exit(1);
}

$results = [];
$blocked = false;
foreach ($data['stages'] as $stage) {
    if (!is_array($stage) || empty($stage['id']) || empty($stage['script'])) {
        fwrite(STDERR, "Invalid stage definition.\n");
        exit(1);
    }

    $missing = [];
    foreach (($stage['required_files'] ?? []) as $required) {
        $path = __DIR__ . '/../' . ltrim((string) $required, '/');
        if (!is_file($path)) {
            $missing[] = (string) $required;
        }
    }

    $scriptPath = __DIR__ . '/../' . ltrim((string) $stage['script'], '/');
    $scriptExists = is_file($scriptPath);
    $ready = $scriptExists && !$missing;
    if (!$ready && !empty($stage['enabled'])) {
        $blocked = true;
    }

    $results[] = [
        'id' => (string) $stage['id'],
        'phase' => (int) ($stage['phase'] ?? 0),
        'script' => (string) $stage['script'],
        'ready' => $ready,
        'missing_files' => $missing,
    ];
}

$run = [
    'schema_version' => '1.0.0',
    'mode' => (string) ($data['mode'] ?? 'contract-first-dry-run'),
    'automatic_production_publish' => false,
    'status' => $blocked ? 'blocked' : 'ready-for-dry-run',
    'fail_closed' => true,
    'stages' => $results,
    'safety' => $data['safety'] ?? [],
];

$json = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if (file_put_contents($outputPath, $json) === false) {
    fwrite(STDERR, "Unable to write run report: {$outputPath}\n");
    exit(1);
}

echo "Tool factory pipeline contract: {$run['status']}\n";
foreach ($results as $result) {
    echo sprintf("- %s: %s\n", $result['id'], $result['ready'] ? 'ready' : 'blocked');
}

// Phase 10 is intentionally fail-closed while upstream phases remain separate branches.
// A blocked contract is an expected state until those phases are integrated; this runner
// is a dry-run contract checker and never publishes or mutates registry/sitemap data.
exit(0);
