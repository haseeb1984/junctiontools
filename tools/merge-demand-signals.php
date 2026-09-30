<?php
declare(strict_types=1);

/**
 * Merge daily Google Trends discovery with optional Google Ads historical
 * Keyword Planner enrichment. Trends remains the primary discovery source.
 *
 * Usage:
 *   php tools/merge-demand-signals.php <trends.json> [ads.json] [output.json]
 */

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
    if (!is_array($data) || !is_array($data['keywords'] ?? null)) {
        throw new RuntimeException("Invalid demand registry: {$path}");
    }
    return $data;
};
$normalize = static fn(string $q): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $q) ?? ''));
$trends = $read($trendsPath);
$ads = ($adsPath !== '' && is_file($adsPath)) ? $read($adsPath) : ['keywords'=>[]];

$merged = [];
$index = [];
$add = static function(array $row) use (&$merged, &$index, $normalize): void {
    $query = trim((string)($row['query'] ?? ''));
    if ($query === '') return;
    $country = strtoupper(trim((string)($row['country'] ?? '')));
    $key = $normalize($query).'|'.$country;
    if (!isset($index[$key])) {
        $index[$key] = count($merged);
        $merged[] = $row;
        return;
    }
    $i = $index[$key];
    $current = $merged[$i];
    if (isset($row['search_volume']) && is_numeric($row['search_volume'])) {
        $current['search_volume'] = (int)$row['search_volume'];
    }
    foreach (['competition','competition_index','low_top_of_page_bid_micros','high_top_of_page_bid_micros'] as $field) {
        if (array_key_exists($field, $row)) $current[$field] = $row[$field];
    }
    $sources = array_values(array_unique(array_filter(array_merge(
        is_array($current['sources'] ?? null) ? $current['sources'] : [$current['source'] ?? null],
        is_array($row['sources'] ?? null) ? $row['sources'] : [$row['source'] ?? null]
    ))));
    $current['sources'] = $sources;
    if (($current['source'] ?? '') === 'google-trends-trending-now-rss' && in_array('google-ads-keyword-planner-api', $sources, true)) {
        $current['source'] = 'google-trends+google-ads-keyword-planner';
    }
    $merged[$i] = $current;
};

foreach ($trends['keywords'] as $row) {
    if (!is_array($row)) continue;
    $row['sources'] = ['google-trends-trending-now-rss'];
    $add($row);
}
foreach ($ads['keywords'] as $row) {
    if (!is_array($row)) continue;
    $row['country'] = $row['country'] ?? 'US';
    $row['sources'] = ['google-ads-keyword-planner-api'];
    $add($row);
}

usort($merged, static function(array $a, array $b): int {
    $aSignal = (int)($a['search_volume'] ?? $a['trend_traffic_lower_bound'] ?? 0);
    $bSignal = (int)($b['search_volume'] ?? $b['trend_traffic_lower_bound'] ?? 0);
    return ($bSignal <=> $aSignal) ?: strcasecmp((string)$a['query'], (string)$b['query']);
});

$result = [
    'schema_version' => '1.0.0',
    'source' => 'junctiontools-daily-demand-merge',
    'generated_at' => gmdate('c'),
    'methodology' => [
        'primary_discovery' => 'Google Trends Trending Now RSS',
        'optional_enrichment' => 'Google Ads Keyword Planner historical metrics',
        'policy' => 'Google Ads data enriches historical demand where available; Trends remains valid when Ads enrichment is unavailable.',
        'spend_policy' => 'The enrichment uses KeywordPlanIdeaService.GenerateKeywordIdeas only; it does not create or run advertising campaigns.'
    ],
    'keywords' => $merged
];

if (file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException("Unable to write output: {$outputPath}");
}
echo "Merged ".count($merged)." daily demand records";
echo " (Trends=".count($trends['keywords']).", Ads=".count($ads['keywords']).").\n";
