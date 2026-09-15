<?php
declare(strict_types=1);

/**
 * Import Google Ads Keyword Planner historical metrics into the JunctionTools
 * search-demand registry. The importer intentionally accepts exported CSV/JSON
 * rather than credentials, so secrets never enter the repository.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/import-google-keyword-planner.php <input.csv|input.json> [output.json]\n");
    exit(2);
}

$input = $argv[1];
$output = $argv[2] ?? dirname(__DIR__) . '/config/google-keyword-planner.json';
if (!is_file($input)) {
    fwrite(STDERR, "Input file not found: {$input}\n");
    exit(1);
}

$extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
$rows = [];
if ($extension === 'json') {
    $decoded = json_decode((string)file_get_contents($input), true);
    if (!is_array($decoded)) { fwrite(STDERR, "Invalid JSON input.\n"); exit(1); }
    $rows = array_is_list($decoded) ? $decoded : ($decoded['keywords'] ?? []);
} elseif ($extension === 'csv') {
    $handle = fopen($input, 'rb');
    if ($handle === false) { fwrite(STDERR, "Unable to open CSV.\n"); exit(1); }
    $header = fgetcsv($handle);
    if (!is_array($header)) { fclose($handle); fwrite(STDERR, "CSV header is missing.\n"); exit(1); }
    $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
    while (($row = fgetcsv($handle)) !== false) {
        if (count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue;
        $rows[] = array_combine($header, array_pad($row, count($header), '')) ?: [];
    }
    fclose($handle);
} else {
    fwrite(STDERR, "Only CSV and JSON inputs are supported.\n"); exit(1);
}

$find = static function(array $row, array $names): mixed {
    foreach ($names as $name) {
        foreach ($row as $key => $value) {
            if (strtolower(trim((string)$key)) === strtolower($name)) return $value;
        }
    }
    return null;
};

$keywords = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)$find($row, ['keyword','keyword text','search term','query']));
    if ($query === '') continue;
    $volumeRaw = $find($row, ['avg. monthly searches','average monthly searches','avg monthly searches','search volume','monthly searches']);
    $volume = is_numeric($volumeRaw) ? (int)$volumeRaw : null;
    if ($volume === null || $volume < 0) continue;
    $country = trim((string)$find($row, ['country','location','geo','target country']));
    $language = trim((string)$find($row, ['language','language name']));
    $competition = trim((string)$find($row, ['competition','competition level']));
    $competitionIndex = $find($row, ['competition index']);
    $lowBid = $find($row, ['low top of page bid','low bid']);
    $highBid = $find($row, ['high top of page bid','high bid']);
    $keywords[] = [
        'query' => $query,
        'country' => $country !== '' ? $country : 'unspecified',
        'language' => $language !== '' ? $language : null,
        'search_volume' => $volume,
        'competition' => $competition !== '' ? $competition : null,
        'competition_index' => is_numeric($competitionIndex) ? (int)$competitionIndex : null,
        'low_bid' => is_numeric($lowBid) ? (float)$lowBid : null,
        'high_bid' => is_numeric($highBid) ? (float)$highBid : null,
        'source' => 'google-ads-keyword-planner',
        'imported_at' => gmdate('Y-m-d\TH:i:s\Z')
    ];
}

$deduped = [];
foreach ($keywords as $keyword) {
    $key = strtolower($keyword['query']) . '|' . strtolower($keyword['country']) . '|' . strtolower((string)($keyword['language'] ?? ''));
    $deduped[$key] = $keyword;
}
$keywords = array_values($deduped);
usort($keywords, static fn(array $a, array $b): int => ($b['search_volume'] <=> $a['search_volume']) ?: strcasecmp($a['query'], $b['query']));

$result = [
    'schema_version' => '1.0.0',
    'source' => 'google-ads-keyword-planner',
    'generated_at' => gmdate('Y-m-d'),
    'methodology' => [
        'authority' => 'Google Ads Keyword Planner historical metrics export',
        'policy' => 'Imported values are preserved as supplied by the export; no volume is inferred or invented.',
        'credentials_policy' => 'Credentials and API secrets must never be committed; use an external export or secret-backed runtime integration.'
    ],
    'keywords' => $keywords
];

if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output: {$output}\n"); exit(1);
}

echo 'Imported ' . count($keywords) . " Google Keyword Planner keyword records.\n";
