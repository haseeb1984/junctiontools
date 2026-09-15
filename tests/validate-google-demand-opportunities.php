<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/build-demand-opportunities.php';
if (!is_file($tool)) { fwrite(STDERR, "Google demand builder missing.\n"); exit(1); }
$output = tempnam(sys_get_temp_dir(), 'jt-demand-');
$input = tempnam(sys_get_temp_dir(), 'jt-gkp-');
file_put_contents($input, json_encode([
    'keywords' => [
        ['query'=>'age calculator','country'=>'US','search_volume'=>220000,'competition'=>'MEDIUM'],
        ['query'=>'timestamp converter','country'=>'US','search_volume'=>48000,'competition'=>'LOW'],
        ['query'=>'qr code generator','country'=>'US','search_volume'=>1000000,'competition'=>'HIGH']
    ]
], JSON_PRETTY_PRINT));
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($input) . ' ' . escapeshellarg($output);
exec($cmd, $lines, $status);
if ($status !== 0) { fwrite(STDERR, "Google demand builder failed.\n"); exit(1); }
$data = json_decode((string)file_get_contents($output), true);
@unlink($input); @unlink($output);
if (!is_array($data) || !isset($data['opportunities']) || count($data['opportunities']) !== 3) { fwrite(STDERR, "Unexpected opportunity output.\n"); exit(1); }
if (($data['opportunities'][0]['rank'] ?? null) !== 1) { fwrite(STDERR, "Ranking metadata missing.\n"); exit(1); }
if (($data['opportunities'][0]['query'] ?? '') !== 'qr code generator') { fwrite(STDERR, "Highest-volume candidate was not ranked first.\n"); exit(1); }
foreach ($data['opportunities'] as $row) {
    foreach (['query','normalized_query','country','search_volume','score','source','confidence','status','rank'] as $field) {
        if (!array_key_exists($field, $row)) { fwrite(STDERR, "Missing field: {$field}\n"); exit(1); }
    }
    if ($row['source'] !== 'google-ads-keyword-planner' || $row['status'] !== 'candidate') { fwrite(STDERR, "Invalid source/status.\n"); exit(1); }
    if ($row['score'] < 1 || $row['score'] > 100) { fwrite(STDERR, "Score outside 1-100.\n"); exit(1); }
}
echo "Google demand opportunity validation: PASS\n";
