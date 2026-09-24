<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/junctiontools-demand-refresh-test-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Unable to create temp directory.\n");
    exit(1);
}

$cleanup = static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tmp);
};
register_shutdown_function($cleanup);

$input = $tmp . '/google-keyword-planner.json';
file_put_contents($input, json_encode([
    'schema_version' => '1.0.0',
    'keywords' => [
        ['query' => 'age calculator', 'search_volume' => 220000, 'country' => 'global', 'language' => 'en', 'competition' => 'MEDIUM'],
        ['query' => 'example utility calculator', 'search_volume' => 12000, 'country' => 'global', 'language' => 'en', 'competition' => 'LOW'],
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/run-demand-refresh.php') . ' ' . escapeshellarg($input) . ' ' . escapeshellarg($root . '/config/tools.json') . ' ' . escapeshellarg($tmp . '/out');
passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Demand refresh command failed.\n");
    exit(1);
}

$reportPath = $tmp . '/out/demand-refresh-report.json';
$queuePath = $tmp . '/out/generation-queue.json';
$specPath = $tmp . '/out/tool-specs.json';
foreach ([$reportPath, $queuePath, $specPath] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing expected artifact: {$file}\n");
        exit(1);
    }
}

$report = json_decode((string)file_get_contents($reportPath), true);
$queue = json_decode((string)file_get_contents($queuePath), true);
$specs = json_decode((string)file_get_contents($specPath), true);
if (($report['automatic_production_publish'] ?? true) !== false || ($report['registry_or_sitemap_modified'] ?? true) !== false) {
    fwrite(STDERR, "Safety policy failed.\n");
    exit(1);
}
if (!is_array($queue['queue'] ?? null) || count($queue['queue']) !== 2) {
    fwrite(STDERR, "Unexpected generation queue size.\n");
    exit(1);
}
if (($queue['queue'][0]['decision'] ?? '') !== 'enhancement') {
    fwrite(STDERR, "Existing age calculator capability was not routed to enhancement.\n");
    exit(1);
}
if (!is_array($specs['specifications'] ?? null) || count($specs['specifications']) !== 2) {
    fwrite(STDERR, "Expected one enhancement specification and one new-tool candidate specification.\n");
    exit(1);
}
$enhancementSpec = null;
$candidateSpec = null;
foreach ($specs['specifications'] as $specification) {
    if (($specification['spec_type'] ?? '') === 'enhancement') {
        $enhancementSpec = $specification;
    } elseif (($specification['spec_type'] ?? '') === 'new_tool') {
        $candidateSpec = $specification;
    }
}
if (!is_array($enhancementSpec) || ($enhancementSpec['tool']['slug'] ?? '') !== 'age-calculator' || ($enhancementSpec['generation_eligible'] ?? true) !== false) {
    fwrite(STDERR, "Existing age calculator demand did not compile to a valid enhancement specification.\n");
    exit(1);
}
if (!is_array($candidateSpec) || ($candidateSpec['spec_status'] ?? '') !== 'draft' || ($candidateSpec['generation_eligible'] ?? true) !== false) {
    fwrite(STDERR, "New-tool specification was not kept draft and generation-ineligible.\n");
    exit(1);
}
echo "PASS: demand refresh is deterministic, review-only, and fail-safe.\n";
