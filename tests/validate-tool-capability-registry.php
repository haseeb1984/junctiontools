<?php
declare(strict_types=1);

/**
 * Verifies capability-first routing against the real JunctionTools capability
 * registry and specifically prevents image-format duplicates when the
 * existing Asset Optimizer already owns image conversion.
 */
$root = dirname(__DIR__);
$queueScript = $root . '/tools/build-generation-queue.php';
$registry = $root . '/config/tools.json';
$capabilities = $root . '/config/tool-capabilities.json';

foreach ([$queueScript, $registry, $capabilities] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing required capability-routing file: {$path}\n");
        exit(1);
    }
}

$tools = json_decode((string)file_get_contents($registry), true);
$caps = json_decode((string)file_get_contents($capabilities), true);
if (!is_array($tools) || !is_array($tools['tools'] ?? null) ||
    !is_array($caps) || !is_array($caps['tools'] ?? null)) {
    fwrite(STDERR, "Invalid capability registry inputs.\n");
    exit(1);
}

if (count($tools['tools']) !== count($caps['tools'])) {
    fwrite(STDERR, "Capability registry does not cover every registered tool.\n");
    exit(1);
}

$capById = [];
foreach ($caps['tools'] as $entry) {
    if (is_array($entry)) $capById[(string)($entry['tool_id'] ?? '')] = $entry;
}
foreach ($tools['tools'] as $tool) {
    $id = (string)($tool['id'] ?? '');
    if ($id === '' || !isset($capById[$id])) {
        fwrite(STDERR, "Missing capability contract for {$id}.\n");
        exit(1);
    }
    if (!is_file($root . '/' . (string)($capById[$id]['source_page'] ?? ''))) {
        fwrite(STDERR, "Capability source page missing for {$id}.\n");
        exit(1);
    }
}

$asset = $root . '/asset-optimizer.html';
$html = (string)file_get_contents($asset);
foreach (['Output Format', 'image/webp', 'image/avif', 'image/jpeg', 'image/png'] as $needle) {
    if (stripos($html, $needle) === false) {
        fwrite(STDERR, "Asset Optimizer implementation evidence missing: {$needle}\n");
        exit(1);
    }
}
if (stripos($html, 'convert formats') === false || stripos($html, 'format conversion') === false) {
    fwrite(STDERR, "Asset Optimizer image-format conversion evidence missing.\n");
    exit(1);
}

$tmp = sys_get_temp_dir() . '/jt-capability-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true)) {
    fwrite(STDERR, "Unable to create temp directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tmp);
});

$opportunities = [
    'opportunities' => [
        [
            'query' => 'HEIC to JPG converter',
            'normalized_query' => 'heic-to-jpg-converter',
            'score' => 99,
            'search_volume' => 100000,
            'confidence' => 'high',
            'country' => 'global',
            'language' => 'en'
        ],
        [
            'query' => 'JSON diff checker',
            'normalized_query' => 'json-diff-checker',
            'score' => 90,
            'search_volume' => 50000,
            'confidence' => 'medium',
            'country' => 'global',
            'language' => 'en'
        ]
    ]
];
file_put_contents($tmp . '/opportunities.json', json_encode($opportunities, JSON_PRETTY_PRINT));
$out = $tmp . '/queue.json';

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($queueScript) . ' ' .
    escapeshellarg($tmp . '/opportunities.json') . ' ' . escapeshellarg($registry) . ' ' .
    escapeshellarg($out) . ' 2>&1';
exec($cmd, $lines, $status);
if ($status !== 0) {
    fwrite(STDERR, "Routing script failed:\n" . implode(PHP_EOL, $lines) . "\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($out), true);
$queue = $data['queue'] ?? [];
$heic = null;
$jsonDiff = null;
foreach ($queue as $row) {
    if (($row['core_query'] ?? '') === 'HEIC to JPG converter') $heic = $row;
    if (($row['core_query'] ?? '') === 'JSON diff checker') $jsonDiff = $row;
}

if (!is_array($heic) ||
    ($heic['decision'] ?? '') !== 'enhancement' ||
    ($heic['existing_tool_match'] ?? '') !== 'image_compressor' ||
    ($heic['target_tool_slug'] ?? '') !== 'asset-optimizer' ||
    ($heic['intent'] ?? '') !== 'image-format-conversion' ||
    ($heic['requested_options']['input_format'] ?? '') !== 'heic' ||
    ($heic['requested_options']['output_format'] ?? '') !== 'jpg' ||
    !in_array('heic', $heic['missing_options'] ?? [], true) ||
    !in_array('jpg', $heic['supported_options'] ?? [], true)) {
    fwrite(STDERR, "HEIC -> JPG was not routed to Asset Optimizer option enhancement.\n");
    exit(1);
}

if (!is_array($jsonDiff) || ($jsonDiff['decision'] ?? '') !== 'candidate') {
    fwrite(STDERR, "JSON diff was incorrectly treated as an existing capability.\n");
    exit(1);
}

echo "PASS: all registered tools have capability contracts.\n";
echo "PASS: Asset Optimizer conversion capability is detected from the capability contract and implementation evidence.\n";
echo "PASS: HEIC -> JPG routes to asset-optimizer enhancement with missing option 'heic', not a new tool.\n";
echo "PASS: genuinely absent JSON diff capability remains a new-tool candidate.\n";
