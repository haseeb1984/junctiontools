<?php
declare(strict_types=1);

/** Build a conservative demand queue. Existing capabilities are routed to enhancement, never duplicate generation. */
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
if (!is_array($opportunities) || !is_array($opportunities['opportunities'] ?? null) || !is_array($registry) || !is_array($registry['tools'] ?? null)) {
    fwrite(STDERR, "Invalid opportunity or registry input.\n"); exit(1);
}

function normalize_demand_term(string $value): string {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-'));
}
function demand_tokens(string $value): array {
    $value = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? '');
    $stop = ['a','an','and','for','free','how','in','my','of','online','the','to','tool','use','with'];
    $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_filter($tokens, static fn(string $t): bool => strlen($t) > 1 && !in_array($t, $stop, true))));
}
function find_existing_tool(string $query, array $tools): ?array {
    $normalized = normalize_demand_term($query);
    $queryTokens = demand_tokens($query);
    $best = null; $bestScore = 0.0;
    foreach ($tools as $tool) {
        if (!is_array($tool)) continue;
        $name = (string)($tool['name'] ?? '');
        $slug = (string)($tool['slug'] ?? '');
        $nameNorm = normalize_demand_term($name);
        $slugNorm = normalize_demand_term($slug);
        if ($normalized === $nameNorm || $normalized === $slugNorm) return ['tool' => $tool, 'match_type' => 'exact', 'score' => 1.0];

        $toolTokens = array_values(array_unique(array_merge(demand_tokens($name), demand_tokens($slug))));
        if (!$queryTokens || !$toolTokens) continue;
        $overlap = count(array_intersect($queryTokens, $toolTokens));
        $coverage = $overlap / count($queryTokens);
        $toolCoverage = $overlap / count($toolTokens);
        $score = min($coverage, $toolCoverage);
        if ($coverage >= 0.75 && $score > $bestScore) {
            $best = ['tool' => $tool, 'match_type' => 'capability-token', 'score' => $score];
            $bestScore = $score;
        }
    }
    return $best;
}

$queue = [];
$rank = 1;
foreach ($opportunities['opportunities'] as $candidate) {
    if (!is_array($candidate)) continue;
    $query = trim((string)($candidate['query'] ?? ''));
    $normalized = trim((string)($candidate['normalized_query'] ?? ''));
    if ($query === '' || $normalized === '') continue;

    $match = find_existing_tool($query, $registry['tools']);
    $tool = $match['tool'] ?? null;
    $isExisting = is_array($tool);

    $queue[] = [
        'rank' => $rank++,
        'cluster_id' => $normalized,
        'core_query' => $query,
        'decision' => $isExisting ? 'enhancement' : 'candidate',
        'match_type' => $match['match_type'] ?? 'none',
        'match_score' => $match['score'] ?? 0.0,
        'priority_score' => (int)($candidate['score'] ?? 1),
        'demand_signal' => (int)($candidate['search_volume'] ?? 0),
        'country' => $candidate['country'] ?? 'unspecified',
        'language' => $candidate['language'] ?? null,
        'competition' => $candidate['competition'] ?? null,
        'competition_index' => $candidate['competition_index'] ?? null,
        'existing_tool_match' => $tool['id'] ?? null,
        'target_tool_slug' => $tool['slug'] ?? null,
        'recommended_slug' => $isExisting ? null : $normalized,
        'implementation' => $isExisting ? 'enhance-existing-tool' : 'requires-tool-spec',
        'enhancement_scope' => $isExisting ? [
            'type' => 'seo',
            'description' => 'Improve the existing tool page for the discovered search intent without changing its core functionality.',
            'requested_capabilities' => ['search-intent-aligned-title', 'meta-description', 'on-page-content', 'how-to-use-content'],
            'affected_components' => ['content', 'seo'],
            'preserve_existing_functionality' => true
        ] : null,
        'confidence' => $candidate['confidence'] ?? 'low',
        'source' => 'google-ads-keyword-planner',
        'status' => $isExisting ? 'enhancement-review' : 'candidate'
    ];
}

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'methodology' => [
        'purpose' => 'Route Google demand into existing-tool SEO/content enhancements or genuinely new-tool candidates.',
        'existing_tool_policy' => 'Exact or sufficiently strong capability matches target the existing tool. They must never enter new-tool generation.',
        'duplicate_policy' => 'A matching existing capability is an enhancement or review candidate; no duplicate tool is generated.',
        'automation_policy' => 'Enhancements and new candidates remain non-publishable until specification, security, functional, SEO, and approval gates pass.'
    ],
    'queue' => $queue
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($queue) . " generation-queue records.\n";
