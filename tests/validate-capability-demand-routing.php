<?php
declare(strict_types=1);

/**
 * Regression test for capability-first demand routing.
 * It deliberately gives Word Counter a different name/slug to prove routing
 * does not depend on tool naming.
 */
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/junctiontools-capability-routing-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Unable to create temp directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($tmp);
});

$registry = [
    'tools' => [
        [
            'id' => 'word_counter',
            'name' => 'Text Statistics Utility',
            'slug' => 'text-stats',
            'status' => 'active'
        ],
        [
            'id' => 'case_converter',
            'name' => 'Letter Transformer',
            'slug' => 'letter-transformer',
            'status' => 'active'
        ]
    ]
];
$opportunities = [
    'opportunities' => [
        [
            'query' => 'free online word counter',
            'normalized_query' => 'free-online-word-counter',
            'score' => 90,
            'search_volume' => 10000,
            'confidence' => 'high'
        ],
        [
            'query' => 'production string formatter',
            'normalized_query' => 'production-string-formatter',
            'score' => 50,
            'search_volume' => 1000,
            'confidence' => 'medium'
        ]
    ]
];

file_put_contents($tmp . '/tools.json', json_encode($registry, JSON_PRETTY_PRINT));
file_put_contents($tmp . '/opportunities.json', json_encode($opportunities, JSON_PRETTY_PRINT));
$out = $tmp . '/queue.json';

$command = escapeshellarg(PHP_BINARY) . ' ' .
    escapeshellarg($root . '/tools/build-generation-queue.php') . ' ' .
    escapeshellarg($tmp . '/opportunities.json') . ' ' .
    escapeshellarg($tmp . '/tools.json') . ' ' .
    escapeshellarg($out);

passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Capability routing command failed.\n");
    exit(1);
}

$data = json_decode((string)file_get_contents($out), true);
$queue = $data['queue'] ?? [];
if (count($queue) !== 2) {
    fwrite(STDERR, "Expected exactly two routing records.\n");
    exit(1);
}

$existing = $queue[0];
if (($existing['decision'] ?? '') !== 'enhancement' ||
    ($existing['match_type'] ?? '') !== 'capability' ||
    ($existing['existing_tool_match'] ?? '') !== 'word_counter' ||
    ($existing['target_tool_slug'] ?? '') !== 'text-stats' ||
    ($existing['recommended_slug'] ?? 'unexpected') !== null ||
    ($existing['intent'] ?? '') !== 'text-counting' ||
    !in_array('count_text', $existing['matched_capabilities'] ?? [], true)) {
    fwrite(STDERR, "Capability match failed for a differently named existing tool.\n");
    exit(1);
}

$unmatched = $queue[1];
if (($unmatched['decision'] ?? '') !== 'candidate' ||
    ($unmatched['match_type'] ?? '') !== 'none' ||
    ($unmatched['existing_tool_match'] ?? 'unexpected') !== null ||
    ($unmatched['recommended_slug'] ?? '') !== 'production-string-formatter') {
    fwrite(STDERR, "Unmatched demand was not kept as a new-tool candidate.\n");
    exit(1);
}

echo "PASS: demand routing uses verified capabilities, not tool names/slugs.\n";
