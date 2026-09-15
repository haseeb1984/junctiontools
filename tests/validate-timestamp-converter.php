<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/timestamp-converter.html';
if (!is_file($file)) { fwrite(STDERR, "Timestamp converter page is missing.\n"); exit(1); }
$html = file_get_contents($file);
if (!is_string($html) || trim($html) === '') { fwrite(STDERR, "Timestamp converter page is empty.\n"); exit(1); }
$required = [
    '<!DOCTYPE html>',
    '<title>Free Unix Timestamp Converter | JunctionTools</title>',
    'https://junctiontools.com/timestamp-converter',
    'name="description"',
    'timestampConverter()',
    'fromTimestamp()',
    'fromDate()',
    'Date.now()',
    'milliseconds',
    'Unix Timestamp Converter'
];
foreach ($required as $needle) {
    if (stripos($html, $needle) === false) { fwrite(STDERR, "Timestamp converter is missing required marker: {$needle}\n"); exit(1); }
}
$registry = json_decode((string) file_get_contents($root . '/config/tools.json'), true);
$matches = array_values(array_filter($registry['tools'] ?? [], static fn(array $tool): bool => ($tool['slug'] ?? '') === 'timestamp-converter'));
if (count($matches) !== 1) { fwrite(STDERR, "Timestamp converter must appear exactly once in the registry.\n"); exit(1); }
$tool = $matches[0];
if (($tool['frontend'] ?? '') !== 'timestamp-converter.html' || ($tool['type'] ?? '') !== 'client_side' || ($tool['status'] ?? '') !== 'active') { fwrite(STDERR, "Timestamp converter registry contract is invalid.\n"); exit(1); }
echo "Timestamp converter validation passed.\n";
