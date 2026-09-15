<?php
declare(strict_types=1);

/**
 * Run the safe, non-publishing demand refresh chain against an explicit
 * Google Keyword Planner export. All generated artifacts are written to the
 * caller-supplied output directory; the registry and sitemap are never changed.
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/run-demand-refresh.php <google-keyword-planner.json> <tools.json> [output-dir]\n");
    exit(2);
}

$keywordPlanner = $argv[1];
$registry = $argv[2];
$outputDir = $argv[3] ?? sys_get_temp_dir() . '/junctiontools-demand-refresh-' . bin2hex(random_bytes(4));

foreach ([$keywordPlanner, $registry] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Input file not found: {$file}\n");
        exit(1);
    }
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outputDir}\n");
    exit(1);
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$run = static function (string $script, array $args) use ($root, $php): void {
    $command = escapeshellarg($php) . ' ' . escapeshellarg($root . '/' . ltrim($script, '/'));
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg((string) $arg);
    }
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Stage failed: {$script} (exit {$exitCode})");
    }
};

$demand = $outputDir . '/google-demand-opportunities.json';
$queue = $outputDir . '/generation-queue.json';
$specs = $outputDir . '/tool-specs.json';

try {
    $run('tools/build-demand-opportunities.php', [$keywordPlanner, $demand]);
    $run('tools/build-generation-queue.php', [$demand, $registry, $queue]);
    $run('tools/build-tool-specs.php', [$queue, $specs]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$summary = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'status' => 'ready-for-review',
    'automatic_production_publish' => false,
    'registry_or_sitemap_modified' => false,
    'inputs' => [
        'google_keyword_planner' => basename($keywordPlanner),
        'registry' => basename($registry),
    ],
    'artifacts' => [
        'google_demand_opportunities' => $demand,
        'generation_queue' => $queue,
        'tool_specs' => $specs,
    ],
    'next_gate' => 'human review and explicit approval before code generation or publication',
];

$report = $outputDir . '/demand-refresh-report.json';
if (file_put_contents($report, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write refresh report.\n");
    exit(1);
}

echo "Demand refresh completed: review-only\n";
echo "Artifacts: {$outputDir}\n";
