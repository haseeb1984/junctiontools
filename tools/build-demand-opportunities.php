<?php
declare(strict_types=1);

/**
 * Convert imported Google Keyword Planner metrics into a ranked opportunity
 * registry without claiming that search volume equals guaranteed traffic.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/build-demand-opportunities.php <google-keyword-planner.json> [output.json]\n");
    exit(2);
}

$input = $argv[1];
$output = $argv[2] ?? dirname(__DIR__) . '/config/google-demand-opportunities.json';
if (!is_file($input)) { fwrite(STDERR, "Input file not found.\n"); exit(1); }
$data = json_decode((string)file_get_contents($input), true);
if (!is_array($data) || !isset($data['keywords']) || !is_array($data['keywords'])) {
    fwrite(STDERR, "Invalid Google Keyword Planner registry.\n"); exit(1);
}

$keywords = [];
foreach ($data['keywords'] as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)($row['query'] ?? ''));
    $volume = $row['search_volume'] ?? null;
    if ($query === '' || !is_int($volume) || $volume < 0) continue;
    $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $query) ?? '', '-'));
    if ($normalized === '') continue;
    $keywords[] = [
        'query' => $query,
        'normalized_query' => $normalized,
        'country' => (string)($row['country'] ?? 'unspecified'),
        'language' => $row['language'] ?? null,
        'search_volume' => $volume,
        'competition' => $row['competition'] ?? null,
        'competition_index' => $row['competition_index'] ?? null,
        'source' => 'google-ads-keyword-planner'
    ];
}

$best = [];
foreach ($keywords as $row) {
    $key = $row['normalized_query'] . '|' . strtolower($row['country']) . '|' . strtolower((string)($row['language'] ?? ''));
    if (!isset($best[$key]) || $row['search_volume'] > $best[$key]['search_volume']) $best[$key] = $row;
}
$keywords = array_values($best);
usort($keywords, static fn(array $a, array $b): int => ($b['search_volume'] <=> $a['search_volume']) ?: strcasecmp($a['query'], $b['query']));

$maxVolume = max(1, ...array_map(static fn(array $r): int => $r['search_volume'], $keywords));
$opportunities = [];
foreach ($keywords as $row) {
    $ratio = $row['search_volume'] / $maxVolume;
    $demand = $row['search_volume'] >= 100000 ? 5 : ($row['search_volume'] >= 25000 ? 4 : ($row['search_volume'] >= 5000 ? 3 : ($row['search_volume'] >= 1000 ? 2 : 1)));
    $competition = strtolower((string)($row['competition'] ?? ''));
    $competitionScore = str_contains($competition, 'high') ? 5 : (str_contains($competition, 'medium') ? 3 : (str_contains($competition, 'low') ? 1 : 3));
    $score = (int)round((($demand * 6) + 5*4 + 5*5 + 5*4 + 5*3 - $competitionScore*3) * 100 / 107);
    $opportunities[] = [
        'query' => $row['query'],
        'normalized_query' => $row['normalized_query'],
        'country' => $row['country'],
        'language' => $row['language'],
        'search_volume' => $row['search_volume'],
        'competition' => $row['competition'],
        'competition_index' => $row['competition_index'],
        'demand_factor' => $demand,
        'competition_factor' => $competitionScore,
        'score' => max(1, min(100, $score)),
        'source' => $row['source'],
        'confidence' => $ratio >= 0.25 ? 'high' : ($ratio >= 0.05 ? 'medium' : 'low'),
        'status' => 'candidate'
    ];
}

usort($opportunities, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: ($b['search_volume'] <=> $a['search_volume']));
foreach ($opportunities as $i => &$row) $row['rank'] = $i + 1;
unset($row);

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'source_registry' => basename($input),
    'methodology' => [
        'purpose' => 'Rank Google Keyword Planner demand for human review and downstream tool opportunity generation.',
        'policy' => 'Google-reported historical search volume is evidence, not a traffic guarantee.',
        'automation_policy' => 'Imported keywords remain candidates; automatic publishing requires later safety and quality gates.'
    ],
    'opportunities' => $opportunities
];

if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($opportunities) . " Google demand opportunities.\n";
