<?php
declare(strict_types=1);

/**
 * End-to-end dry-run for the continuous tool factory.
 * This test never changes production registry/sitemap state and never enables publishing.
 */
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/junctiontools-factory-e2e-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Unable to create temp directory.\n");
    exit(1);
}
$cleanup = static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($tmp);
};
register_shutdown_function($cleanup);

$run = static function (string $script, array $args) use ($root): void {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/' . ltrim($script, '/'));
    foreach ($args as $arg) $cmd .= ' ' . escapeshellarg((string)$arg);
    passthru($cmd, $code);
    if ($code !== 0) throw new RuntimeException("Stage failed: {$script}");
};

$input = $tmp . '/keywords.json';
$scInput = $tmp . '/search-console.json';
file_put_contents($input, json_encode(['schema_version'=>'1.0.0','keywords'=>[
    ['query'=>'age calculator','search_volume'=>220000,'country'=>'global','language'=>'en','competition'=>'MEDIUM'],
    ['query'=>'example utility calculator','search_volume'=>12000,'country'=>'global','language'=>'en','competition'=>'LOW'],
]], JSON_PRETTY_PRINT) . PHP_EOL);
file_put_contents($scInput, json_encode(['schema_version'=>'1.0.0','rows'=>[
    ['query'=>'age calculator','clicks'=>8,'impressions'=>400,'ctr'=>2.0,'position'=>12,'country'=>'global','device'=>'DESKTOP','page'=>'https://junctiontools.com/age-calculator'],
    ['query'=>'new privacy calculator','clicks'=>0,'impressions'=>90,'ctr'=>0,'position'=>18,'country'=>'global','device'=>'MOBILE','page'=>null],
]], JSON_PRETTY_PRINT) . PHP_EOL);

$registry = $root . '/config/tools.json';
$registryBefore = hash_file('sha256', $registry);
$sitemapBefore = hash_file('sha256', $root . '/sitemap.xml');

$out = $tmp . '/demand';
$run('tools/run-demand-refresh.php', [$input, $registry, $out]);
$run('tools/build-search-console-feedback.php', [$registry, $scInput, $tmp . '/search-console-opportunities.json']);
$run('tools/build-search-console-generation-queue.php', [$tmp . '/search-console-opportunities.json', $registry, $tmp . '/search-console-generation-queue.json']);

$plan = $tmp . '/deployment-plan.json';
file_put_contents($plan, json_encode(['schema_version'=>'1.0.0','tools'=>[
    ['slug'=>'dry-run-approved','priority_score'=>95,'generation_eligible'=>true,'generated_quality'=>true,'generated_runtime'=>true,'human_publication_review'=>true,'registry_validation'=>true,'sitemap_validation'=>true,'post_publish_validation'=>true],
    ['slug'=>'dry-run-blocked','priority_score'=>99,'generation_eligible'=>true,'generated_quality'=>true,'generated_runtime'=>false,'human_publication_review'=>true,'registry_validation'=>true,'sitemap_validation'=>true,'post_publish_validation'=>true],
]], JSON_PRETTY_PRINT) . PHP_EOL);
$decision = $tmp . '/automatic-publishing-decision.json';
$run('tools/evaluate-automatic-publishing.php', [$plan, $root . '/config/automatic-publishing-policy.json', $decision]);

$report = json_decode((string)file_get_contents($out . '/demand-refresh-report.json'), true);
$queue = json_decode((string)file_get_contents($out . '/generation-queue.json'), true);
$specs = json_decode((string)file_get_contents($out . '/tool-specs.json'), true);
$scQueue = json_decode((string)file_get_contents($tmp . '/search-console-generation-queue.json'), true);
$publish = json_decode((string)file_get_contents($decision), true);

if (($report['automatic_production_publish'] ?? true) !== false || ($report['registry_or_sitemap_modified'] ?? true) !== false) throw new RuntimeException('Demand refresh safety contract failed.');
if (count($queue['queue'] ?? []) !== 2 || ($queue['queue'][0]['decision'] ?? '') !== 'enhancement') throw new RuntimeException('Demand routing contract failed.');
if (count($specs['specifications'] ?? []) !== 2) throw new RuntimeException('Specification safety contract failed. Expected enhancement plus new-tool draft.');
if (count($scQueue['existing_tool_actions'] ?? []) !== 1 || count($scQueue['new_tool_or_content_candidates'] ?? []) !== 1) throw new RuntimeException('Search Console routing contract failed.');
if (($publish['automatic_publish_enabled'] ?? true) !== false) throw new RuntimeException('Automatic publishing was unexpectedly enabled.');
foreach (($publish['decisions'] ?? []) as $decisionRow) if (($decisionRow['decision'] ?? '') !== 'review-required') throw new RuntimeException('Publishing gate did not remain review-only.');
if (hash_file('sha256', $registry) !== $registryBefore || hash_file('sha256', $root . '/sitemap.xml') !== $sitemapBefore) throw new RuntimeException('Registry or sitemap was modified.');

echo "PASS: full tool-factory dry run is review-only, deterministic, and fail-closed.\n";
