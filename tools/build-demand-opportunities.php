<?php
declare(strict_types=1);

/** Convert imported Google demand metrics into a coverage-oriented opportunity registry. */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/build-demand-opportunities.php <demand.json> [output.json]\n");
    exit(2);
}
$input = $argv[1];
$output = $argv[2] ?? dirname(__DIR__) . '/config/google-demand-opportunities.json';
if (!is_file($input)) { fwrite(STDERR, "Input file not found.\n"); exit(1); }
$data = json_decode((string)file_get_contents($input), true);
if (!is_array($data) || !is_array($data['keywords'] ?? null)) {
    fwrite(STDERR, "Invalid demand registry.\n"); exit(1);
}

$keywords = [];
foreach ($data['keywords'] as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)($row['query'] ?? ''));
    $volume = $row['search_volume'] ?? null;
    $trendTraffic = $row['trend_traffic_lower_bound'] ?? null;
    $isVolume = is_numeric($volume) && (int)$volume >= 0;
    $isTrend = is_numeric($trendTraffic) && (int)$trendTraffic > 0;
    if ($query === '' || (!$isVolume && !$isTrend)) continue;
    $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $query) ?? '', '-'));
    if ($normalized === '') continue;

    $history = is_array($row['monthly_search_history'] ?? null) ? $row['monthly_search_history'] : [];
    $history = array_values(array_filter($history, static fn($m): bool => is_array($m) && isset($m['year'], $m['month'], $m['searches'])));
    $historyValues = array_map(static fn(array $m): int => (int)$m['searches'], $history);
    $historyAvg = $historyValues ? (int)round(array_sum($historyValues) / count($historyValues)) : null;
    $recent = $historyValues ? array_slice($historyValues, -3) : [];
    $recentAvg = $recent ? (int)round(array_sum($recent) / count($recent)) : null;

    $keywords[] = [
        'query' => $query,
        'normalized_query' => $normalized,
        'country' => (string)($row['country'] ?? 'unspecified'),
        'language' => $row['language'] ?? null,
        'search_volume' => $isVolume ? (int)$volume : null,
        'monthly_search_history' => $history,
        'history_months' => count($history),
        'history_average_monthly_searches' => $historyAvg,
        'recent_3_month_average_searches' => $recentAvg,
        'trend_traffic_label' => $row['trend_traffic_label'] ?? null,
        'trend_traffic_lower_bound' => $isTrend ? (int)$trendTraffic : null,
        'trend_signal' => $row['trend_signal'] ?? null,
        'competition' => $row['competition'] ?? null,
        'competition_index' => $row['competition_index'] ?? null,
        'source' => $row['source'] ?? 'unknown',
        'sources' => is_array($row['sources'] ?? null) ? $row['sources'] : [(string)($row['source'] ?? 'unknown')]
    ];
}

/* Collapse only exact query/country/language duplicates; do not discard worldwide history. */
$best = [];
foreach ($keywords as $row) {
    $key = $row['normalized_query'].'|'.strtolower($row['country']).'|'.strtolower((string)($row['language'] ?? ''));
    if (!isset($best[$key])) { $best[$key] = $row; continue; }
    if (count($row['monthly_search_history']) > count($best[$key]['monthly_search_history'])) $best[$key] = $row;
}
$keywords = array_values($best);

usort($keywords, static fn(array $a, array $b): int =>
    ((int)($b['search_volume'] ?? $b['trend_traffic_lower_bound'] ?? 0) <=> (int)($a['search_volume'] ?? $a['trend_traffic_lower_bound'] ?? 0))
    ?: strcasecmp($a['query'], $b['query'])
);

$opportunities = [];
foreach ($keywords as $row) {
    $signal = (int)($row['search_volume'] ?? $row['trend_traffic_lower_bound'] ?? 0);
    $competition = strtolower((string)($row['competition'] ?? ''));
    $competitionScore = str_contains($competition, 'high') ? 5 : (str_contains($competition, 'medium') ? 3 : (str_contains($competition, 'low') ? 1 : 3));

    $opportunities[] = [
        'query' => $row['query'],
        'normalized_query' => $row['normalized_query'],
        'country' => $row['country'],
        'language' => $row['language'],
        'search_volume' => $row['search_volume'],
        'monthly_search_history' => $row['monthly_search_history'],
        'history_months' => $row['history_months'],
        'history_average_monthly_searches' => $row['history_average_monthly_searches'],
        'recent_3_month_average_searches' => $row['recent_3_month_average_searches'],
        'trend_traffic_label' => $row['trend_traffic_label'],
        'trend_traffic_lower_bound' => $row['trend_traffic_lower_bound'],
        'trend_signal' => $row['trend_signal'],
        'competition' => $row['competition'],
        'competition_index' => $row['competition_index'],
        'competition_factor' => $competitionScore,
        'demand_signal' => $signal,
        'source' => $row['source'],
        'sources' => $row['sources'],
        'status' => 'candidate'
    ];
}

$result = [
    'schema_version' => '1.1.0',
    'generated_at' => gmdate('c'),
    'source_registry' => basename($input),
    'methodology' => [
        'purpose' => 'Discover tool demand for worldwide functionality coverage, not search-ranking prediction.',
        'current_signal' => 'Google Trends current/trending demand where available.',
        'recurring_signal' => 'Google Ads Keyword Planner historical demand; monthly history represents approximately the past 12 months.',
        'policy' => 'Volume is a discovery signal only. It does not predict Google ranking, traffic, or conversion.',
        'routing_policy' => 'Downstream capability matching decides enhancement versus genuinely absent new functionality.',
        'automation_policy' => 'Imported demand remains subject to tool-intent, capability, security, validation, and publication gates.'
    ],
    'opportunities' => $opportunities
];

if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built '.count($opportunities)." Google demand opportunities.\n";
