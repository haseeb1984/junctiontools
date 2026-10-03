<?php
declare(strict_types=1);

/** Merge current Trends discovery with worldwide recurring Google Ads demand. */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/merge-demand-signals.php <trends.json> [ads.json] [output.json]\n");
    exit(2);
}
$trendsPath = $argv[1];
$adsPath = $argv[2] ?? '';
$outputPath = $argv[3] ?? dirname(__DIR__) . '/config/daily-demand-input.json';

$read = static function(string $path): array {
    if (!is_file($path)) throw new RuntimeException("Input file not found: {$path}");
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['keywords'] ?? null)) throw new RuntimeException("Invalid demand registry: {$path}");
    return $data;
};
$normalize = static fn(string $q): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $q) ?? ''));
$trends = $read($trendsPath);
$ads = ($adsPath !== '' && is_file($adsPath)) ? $read($adsPath) : ['keywords'=>[]];

$merged = [];
$index = [];
$add = static function(array $row, string $sourceType) use (&$merged, &$index, $normalize): void {
    $query = trim((string)($row['query'] ?? ''));
    if ($query === '') return;
    $country = strtoupper(trim((string)($row['country'] ?? 'UNSPECIFIED')));
    $language = strtoupper(trim((string)($row['language'] ?? 'UNSPECIFIED')));
    $key = $normalize($query).'|'.$country.'|'.$language;

    if (!isset($index[$key])) {
        $row['sources'] = is_array($row['sources'] ?? null) ? $row['sources'] : [$sourceType];
        $row['source_types'] = [$sourceType];
        $index[$key] = count($merged);
        $merged[] = $row;
        return;
    }

    $i = $index[$key];
    $current = $merged[$i];
    foreach (['search_volume','monthly_search_history','history_months','history_average_monthly_searches','recent_3_month_average_searches','competition','competition_index','low_top_of_page_bid_micros','high_top_of_page_bid_micros'] as $field) {
        if (array_key_exists($field, $row)) $current[$field] = $row[$field];
    }
    $current['sources'] = array_values(array_unique(array_filter(array_merge(
        is_array($current['sources'] ?? null) ? $current['sources'] : [],
        is_array($row['sources'] ?? null) ? $row['sources'] : [$sourceType]
    ))));
    $current['source_types'] = array_values(array_unique(array_merge(
        is_array($current['source_types'] ?? null) ? $current['source_types'] : [],
        [$sourceType]
    )));
    $current['source'] = count($current['source_types']) > 1 ? 'google-trends+google-ads-keyword-planner' : (string)($current['source'] ?? $sourceType);
    $merged[$i] = $current;
};

foreach ($trends['keywords'] as $row) {
    if (is_array($row)) $add($row, 'current-trends');
}
foreach ($ads['keywords'] as $row) {
    if (is_array($row)) {
        $row['country'] = $row['country'] ?? 'WORLDWIDE';
        $add($row, 'worldwide-recurring');
    }
}

usort($merged, static function(array $a, array $b): int {
    $aSignal = (int)($a['search_volume'] ?? $a['trend_traffic_lower_bound'] ?? 0);
    $bSignal = (int)($b['search_volume'] ?? $b['trend_traffic_lower_bound'] ?? 0);
    return ($bSignal <=> $aSignal) ?: strcasecmp((string)$a['query'], (string)$b['query']);
});

$result = [
    'schema_version' => '1.1.0',
    'source' => 'junctiontools-daily-demand-merge',
    'generated_at' => gmdate('c'),
    'methodology' => [
        'current_demand' => 'Google Trends current/trending discovery feeds.',
        'recurring_demand' => 'Google Ads Keyword Planner worldwide recurring demand with approximately 12 months of historical monthly search volumes.',
        'coverage_policy' => 'Current and recurring signals are complementary discovery inputs; neither is a ranking prediction.',
        'spend_policy' => 'Keyword Planner is used only for demand research; no advertising campaigns are created or run.'
    ],
    'keywords' => $merged
];

if (file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("Unable to write output: {$outputPath}");
}
echo "Merged ".count($merged)." demand records (current Trends=".count($trends['keywords']).", worldwide recurring Ads=".count($ads['keywords']).").\n";
