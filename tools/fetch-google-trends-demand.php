<?php
declare(strict_types=1);

/**
 * Phase 13 Google Trends Trending Now demand acquisition.
 *
 * Uses Google's public Trending Now RSS export. This is a discovery signal,
 * not exact monthly search volume. No Google Ads credentials are required.
 *
 * Usage:
 *   php tools/fetch-google-trends-demand.php [config.json] [output.json]
 */

$configPath = $argv[1] ?? dirname(__DIR__) . '/config/google-trends-runtime.json';
$outputPath = $argv[2] ?? dirname(__DIR__) . '/config/google-trends-live.json';

if (!is_file($configPath)) {
    fwrite(STDERR, "Runtime config not found: {$configPath}\n");
    exit(1);
}
$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
    fwrite(STDERR, "Google Trends runtime is disabled or invalid.\n");
    exit(1);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP cURL extension is required for Google Trends RSS access.\n");
    exit(1);
}
if (!function_exists('simplexml_load_string')) {
    fwrite(STDERR, "PHP SimpleXML extension is required for Google Trends RSS parsing.\n");
    exit(1);
}

$get = static function (string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/rss+xml, application/xml, text/xml;q=0.9'],
        CURLOPT_USERAGENT => 'JunctionTools-Phase13/1.0 (+https://junctiontools.com)',
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("Google Trends RSS request failed: {$error}");
    if ($status < 200 || $status >= 300) throw new RuntimeException("Google Trends RSS returned HTTP {$status}.");
    return (string) $body;
};

$parseTraffic = static function (string $value): int {
    $digits = preg_replace('/[^0-9]/', '', $value);
    if ($digits === '') return 0;
    $number = (int) $digits;
    $upper = strtoupper($value);
    if (str_contains($upper, 'M')) return $number * 1000000;
    if (str_contains($upper, 'K')) return $number * 1000;
    return $number;
};

try {
    $geos = array_values(array_unique(array_filter(array_map(
        static fn($v): string => strtoupper(trim((string) $v)),
        is_array($config['geo_targets'] ?? null) ? $config['geo_targets'] : ['US']
    ), static fn(string $v): bool => preg_match('/^[A-Z]{2}$/', $v) === 1)));

    $intentTerms = array_values(array_unique(array_filter(array_map(
        static fn($v): string => strtolower(trim((string) $v)),
        is_array($config['tool_intent_terms'] ?? null) ? $config['tool_intent_terms'] : []
    ), static fn(string $v): bool => $v !== '')));

    if ($geos === [] || $intentTerms === []) {
        throw new RuntimeException('Google Trends config requires geo_targets and tool_intent_terms.');
    }

    $keywords = [];
    $seen = [];
    $maxPerGeo = max(1, min(100, (int)($config['max_items_per_geo'] ?? 25)));

    foreach ($geos as $geo) {
        $url = 'https://trends.google.com/trending/rss?geo=' . rawurlencode($geo);
        $xml = @simplexml_load_string($get($url));
        if ($xml === false) throw new RuntimeException("Unable to parse Google Trends RSS for {$geo}.");

        $items = $xml->channel->item ?? [];
        $count = 0;
        foreach ($items as $item) {
            if ($count >= $maxPerGeo) break;
            $query = trim((string)($item->title ?? ''));
            if ($query === '') continue;

            $queryLower = strtolower($query);
            $matchedTerms = [];
            foreach ($intentTerms as $term) {
                if (str_contains($queryLower, $term)) $matchedTerms[] = $term;
            }
            if ($matchedTerms === []) continue;

            $namespaces = $item->getNameSpaces(true);
            $ht = isset($namespaces['ht']) ? $item->children($namespaces['ht']) : null;
            $trafficLabel = $ht !== null ? trim((string)($ht->approx_traffic ?? '')) : '';
            $trafficLowerBound = $parseTraffic($trafficLabel);
            $pubDate = trim((string)($item->pubDate ?? ''));
            $key = strtolower($geo . '|' . $query);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $keywords[] = [
                'query' => $query,
                'country' => $geo,
                'language' => null,
                'search_volume' => null,
                'trend_traffic_label' => $trafficLabel !== '' ? $trafficLabel : null,
                'trend_traffic_lower_bound' => $trafficLowerBound,
                'trend_signal' => 'trending-now',
                'source' => 'google-trends-trending-now-rss',
                'source_date' => gmdate('Y-m-d'),
                'observed_at' => gmdate('c'),
                'started_at' => $pubDate !== '' ? $pubDate : null,
                'matched_tool_intent_terms' => $matchedTerms,
                'evidence_url' => $url
            ];
            $count++;
        }
    }

    if ($keywords === []) {
        throw new RuntimeException('Google Trends returned no tool-relevant trending queries for the configured regions.');
    }

    usort($keywords, static function (array $a, array $b): int {
        return ($b['trend_traffic_lower_bound'] <=> $a['trend_traffic_lower_bound'])
            ?: strcasecmp($a['query'], $b['query']);
    });

    $result = [
        'schema_version' => '1.0.0',
        'source' => 'google-trends-trending-now-rss',
        'generated_at' => gmdate('c'),
        'targeting' => [
            'geo_targets' => $geos,
            'tool_intent_filter' => $intentTerms
        ],
        'methodology' => [
            'authority' => 'Google Trends Trending Now RSS export',
            'policy' => 'Trend traffic is a Google-provided bucket/lower-bound signal; it is not represented as exact monthly search volume.',
            'filter_policy' => 'Only queries containing configured tool-intent terms enter the Tool Factory demand registry. Human review remains required.',
            'credentials_policy' => 'No Google Ads credentials or paid advertising account is required for this demand source.'
        ],
        'keywords' => $keywords
    ];

    if (file_put_contents($outputPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("Unable to write output: {$outputPath}");
    }

    echo "Fetched " . count($keywords) . " tool-relevant Google Trends records.\n";
    echo "Output: {$outputPath}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Live Google Trends demand acquisition failed: " . $e->getMessage() . "\n");
    exit(1);
}
