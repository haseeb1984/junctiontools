<?php
declare(strict_types=1);

/** Import Google Search Console Performance export data without credentials. */
if ($argc < 2) { fwrite(STDERR, "Usage: php tools/import-search-console.php <input.csv|input.json> [output.json]\n"); exit(2); }
$input = $argv[1]; $output = $argv[2] ?? dirname(__DIR__) . '/config/search-console-feedback.json';
if (!is_file($input)) { fwrite(STDERR, "Input file not found: {$input}\n"); exit(1); }
$extension = strtolower(pathinfo($input, PATHINFO_EXTENSION)); $rows = [];
if ($extension === 'json') {
    $decoded = json_decode((string)file_get_contents($input), true);
    if (!is_array($decoded)) { fwrite(STDERR, "Invalid JSON input.\n"); exit(1); }
    $rows = array_is_list($decoded) ? $decoded : ($decoded['rows'] ?? $decoded['queries'] ?? $decoded['data'] ?? []);
} elseif ($extension === 'csv') {
    $handle = fopen($input, 'rb'); if ($handle === false) { fwrite(STDERR, "Unable to open CSV.\n"); exit(1); }
    $header = fgetcsv($handle); if (!is_array($header)) { fclose($handle); fwrite(STDERR, "CSV header is missing.\n"); exit(1); }
    $header = array_map(static fn($v): string => strtolower(trim((string)$v)), $header);
    while (($row = fgetcsv($handle)) !== false) { if (count(array_filter($row, static fn($v): bool => trim((string)$v) !== '')) === 0) continue; $row = array_pad($row, count($header), ''); $rows[] = array_combine($header, array_slice($row, 0, count($header))) ?: []; }
    fclose($handle);
} else { fwrite(STDERR, "Only CSV and JSON inputs are supported.\n"); exit(1); }
$find = static function(array $row, array $names): mixed { foreach ($names as $name) foreach ($row as $key => $value) if (strtolower(trim((string)$key)) === strtolower($name)) return $value; return null; };
$number = static function(mixed $value): ?float { if ($value === null) return null; $value = str_replace([',', '%'], '', trim((string)$value)); return is_numeric($value) ? (float)$value : null; };
$rowsOut = [];
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $query = trim((string)$find($row, ['query','top queries','search query','keyword'])); if ($query === '') continue;
    $page = trim((string)$find($row, ['page','top pages','url','landing page'])); $clicks = $number($find($row, ['clicks'])); $impressions = $number($find($row, ['impressions'])); $ctr = $number($find($row, ['ctr','click through rate'])); $position = $number($find($row, ['position','average position'])); $country = trim((string)$find($row, ['country','countries'])); $device = trim((string)$find($row, ['device','devices'])); $date = trim((string)$find($row, ['date','day']));
    foreach (['clicks'=>$clicks,'impressions'=>$impressions,'ctr'=>$ctr,'position'=>$position] as $metric=>$value) if ($value !== null && $value < 0) { fwrite(STDERR, "Invalid negative {$metric} value for query: {$query}\n"); exit(1); }
    $rowsOut[] = ['query'=>$query,'page'=>$page !== '' ? $page : null,'clicks'=>$clicks,'impressions'=>$impressions,'ctr'=>$ctr,'position'=>$position,'country'=>$country !== '' ? $country : null,'device'=>$device !== '' ? $device : null,'date'=>$date !== '' ? $date : null,'source'=>'google-search-console'];
}
$deduped = []; foreach ($rowsOut as $row) { $key = strtolower($row['query']).'|'.strtolower((string)($row['page'] ?? '')).'|'.strtolower((string)($row['country'] ?? '')).'|'.strtolower((string)($row['device'] ?? '')).'|'.(string)($row['date'] ?? ''); $deduped[$key] = $row; }
$rowsOut = array_values($deduped); usort($rowsOut, static fn(array $a,array $b): int => (($b['impressions'] ?? -1) <=> ($a['impressions'] ?? -1)) ?: strcasecmp($a['query'],$b['query']));
$result = ['schema_version'=>'1.0.0','source'=>'google-search-console','generated_at'=>gmdate('Y-m-d'),'methodology'=>['authority'=>'Google Search Console Performance export','policy'=>'Imported clicks, impressions, CTR and position are preserved as supplied by the export; no search demand is inferred from missing values.','credentials_policy'=>'Search Console credentials and API secrets must never be committed; use an external export or secret-backed runtime integration.','privacy'=>'Query and page data must be reviewed before publication or sharing outside the site owner context.'],'rows'=>$rowsOut];
if (file_put_contents($output,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX) === false) { fwrite(STDERR,"Unable to write output: {$output}\n"); exit(1); }
echo 'Imported '.count($rowsOut)." Google Search Console records.\n";
