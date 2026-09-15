<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/tools/import-google-keyword-planner.php';
if (!is_file($file)) { fwrite(STDERR, "Google Keyword Planner importer is missing.\n"); exit(1); }
$php = file_get_contents($file);
if (!is_string($php) || trim($php) === '') { fwrite(STDERR, "Importer is empty.\n"); exit(1); }
foreach ([
    'google-ads-keyword-planner',
    'avg. monthly searches',
    'competition index',
    'Credentials and API secrets must never be committed',
    'json_encode'
] as $needle) {
    if (stripos($php, $needle) === false) { fwrite(STDERR, "Importer missing required marker: {$needle}\n"); exit(1); }
}
$syntax = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
if (!is_string($syntax) || !str_contains($syntax, 'No syntax errors detected')) { fwrite(STDERR, "Importer PHP syntax validation failed.\n" . (string)$syntax); exit(1); }
echo "Google Keyword Planner importer validation passed.\n";
