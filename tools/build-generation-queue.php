<?php
declare(strict_types=1);

/** Build a conservative generation queue from ranked Google demand opportunities. */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-generation-queue.php <opportunities.json> <tools.json> [output.json]\n");
    exit(2);
}
$opportunityFile = $argv[1];
$registryFile = $argv[2];
$output = $argv[3] ?? dirname(__DIR__) . '/config/generated-tool-generation-queue.json';

foreach ([$opportunityFile, $registryFile] as $file) {
    if (!is_file($file)) { fwrite(STDERR, "File not found: {$file}\n"); exit(1); }
}
$opportunities = json_decode((string)file_get_contents($opportunityFile), true);
$registry = json_decode((string)file_get_contents($registryFile), true);
if (!is_array($opportunities) || !isset($opportunities['opportunities']) || !is_array($opportunities['opportunities']) || !is_array($registry) || !isset($registry['tools']) || !is_array($registry['tools'])) {
    fwrite(STDERR, "Invalid opportunity or registry input.\n"); exit(1);
}

$existing = [];
foreach ($registry['tools'] as $tool) {
    if (!is_array($tool)) continue;
    foreach ([(string)($tool['name'] ?? ''), (string)($tool['slug'] ?? '')] as $value) {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-'));
        if ($key !== '') $existing[$key] = $tool;
    }
}

$queue = [];
$rank = 1;
foreach ($opportunities['opportunities'] as $candidate) {
    if (!is_array($candidate)) continue;
    $query = trim((string)($candidate['query'] ?? ''));
    $normalized = trim((string)($candidate['normalized_query'] ?? ''));
    if ($query === '' || $normalized === '') continue;
    $match = $existing[$normalized] ?? null;
    $decision = $match !== null ? 'enhancement' : 'candidate';
    $queue[] = [
        'rank' => $rank++,
        'cluster_id' => $normalized,
        'core_query' => $query,
        'decision' => $decision,
        'priority_score' => (int)($candidate['score'] ?? 1),
        'demand_signal' => (int)($candidate['search_volume'] ?? 0),
        'country' => $candidate['country'] ?? 'unspecified',
        'language' => $candidate['language'] ?? null,
        'competition' => $candidate['competition'] ?? null,
        'competition_index' => $candidate['competition_index'] ?? null,
        'existing_tool_match' => $match['id'] ?? null,
        'target_tool_slug' => $match['slug'] ?? null,
        'recommended_slug' => $normalized,
        'implementation' => $match !== null ? 'enhance-existing-tool' : 'requires-tool-spec',
        'confidence' => $candidate['confidence'] ?? 'low',
        'source' => 'google-ads-keyword-planner',
        'status' => 'candidate'
    ];
}

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'methodology' => [
        'purpose' => 'Route ranked demand into new-tool candidates or existing-tool enhancements.',
        'existing_tool_policy' => 'Matching registry capabilities are routed to enhancement rather than duplicate tool generation.',
        'automation_policy' => 'Candidates remain non-publishable until later specification, security, functional, and approval gates.'
    ],
    'queue' => $queue
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($queue) . " generation-queue records.\n";
