<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/tool-factory-pipeline.json';
$runner = $root . '/tools/run-tool-factory.php';

foreach ([$configPath, $runner] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required file: {$path}\n");
        exit(1);
    }
}

$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config) || ($config['schema_version'] ?? null) !== '1.0.0') {
    fwrite(STDERR, "Invalid pipeline schema.\n");
    exit(1);
}

if (($config['automatic_production_publish'] ?? true) !== false) {
    fwrite(STDERR, "Production publishing must be disabled.\n");
    exit(1);
}

$safety = $config['safety'] ?? [];
foreach (['fail_closed', 'never_modify_registry_or_sitemap', 'never_publish_without_explicit_approval', 'never_commit_credentials', 'production_publish_requires_separate_review'] as $key) {
    if (($safety[$key] ?? false) !== true) {
        fwrite(STDERR, "Safety policy missing or disabled: {$key}\n");
        exit(1);
    }
}

$expected = [
    'search-demand',
    'generation-queue',
    'tool-specification',
    'approved-generation',
    'runtime-validation',
    'deployment-plan',
    'site-navigation-sync',
    'search-console-feedback',
    'automatic-publishing-gate',
];
$ids = array_map(static fn(array $stage): string => (string) ($stage['id'] ?? ''), $config['stages'] ?? []);
if ($ids !== $expected) {
    fwrite(STDERR, "Pipeline stages are missing, duplicated, or out of order.\n");
    exit(1);
}

$tmp = tempnam(sys_get_temp_dir(), 'jt-factory-');
if ($tmp === false) {
    fwrite(STDERR, "Unable to create temporary report.\n");
    exit(1);
}
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($configPath) . ' ' . escapeshellarg($tmp);
exec($command, $output, $exitCode);
if ($exitCode !== 0) {
    @unlink($tmp);
    fwrite(STDERR, "Pipeline runner failed.\n");
    exit(1);
}

$report = json_decode((string) file_get_contents($tmp), true);
@unlink($tmp);
if (!is_array($report) || ($report['fail_closed'] ?? false) !== true || ($report['automatic_production_publish'] ?? true) !== false) {
    fwrite(STDERR, "Runner report violates fail-closed safety contract.\n");
    exit(1);
}

if (!in_array($report['status'] ?? '', ['blocked', 'ready-for-dry-run'], true)) {
    fwrite(STDERR, "Unexpected pipeline status.\n");
    exit(1);
}

echo "Phase 10 tool factory pipeline contract: PASS\n";
