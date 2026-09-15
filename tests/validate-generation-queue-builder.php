<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$tool = $root . '/tools/build-generation-queue.php';
if (!is_file($tool)) { fwrite(STDERR, "Queue builder missing.\n"); exit(1); }
$input = tempnam(sys_get_temp_dir(), 'jt-opp-');
$registry = tempnam(sys_get_temp_dir(), 'jt-reg-');
$output = tempnam(sys_get_temp_dir(), 'jt-queue-');
file_put_contents($input, json_encode(['opportunities' => [
    ['query'=>'timestamp converter','normalized_query'=>'timestamp-converter','search_volume'=>48000,'score'=>82,'country'=>'US','confidence'=>'high','source'=>'google-ads-keyword-planner'],
    ['query'=>'brand new utility','normalized_query'=>'brand-new-utility','search_volume'=>5000,'score'=>55,'country'=>'US','confidence'=>'medium','source'=>'google-ads-keyword-planner']
]]));
file_put_contents($registry, json_encode(['tools' => [
    ['id'=>'timestamp_converter','name'=>'Timestamp Converter','slug'=>'timestamp-converter']
]]));
$cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($input).' '.escapeshellarg($registry).' '.escapeshellarg($output);
exec($cmd, $lines, $status);
$data = json_decode((string)file_get_contents($output), true);
@unlink($input); @unlink($registry); @unlink($output);
if ($status !== 0 || !is_array($data) || count($data['queue'] ?? []) !== 2) { fwrite(STDERR, "Queue builder failed.\n"); exit(1); }
if (($data['queue'][0]['decision'] ?? '') !== 'enhancement' || ($data['queue'][0]['existing_tool_match'] ?? '') !== 'timestamp_converter') { fwrite(STDERR, "Existing tool was not routed to enhancement.\n"); exit(1); }
if (($data['queue'][1]['decision'] ?? '') !== 'candidate' || ($data['queue'][1]['existing_tool_match'] ?? null) !== null) { fwrite(STDERR, "New demand was not routed to candidate.\n"); exit(1); }
echo "Generation queue builder validation: PASS\n";
