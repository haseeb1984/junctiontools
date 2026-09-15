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
    'Unix Timestamp Converter',
    'How to Use'
];
foreach ($required as $needle) {
    if (stripos($html, $needle) === false) { fwrite(STDERR, "Timestamp converter is missing required marker: {$needle}\n"); exit(1); }
}
if (preg_match('/text-cyan-|bg-cyan-|border-cyan-/i', $html)) { fwrite(STDERR, "Timestamp converter contains non-brand cyan styling.\n"); exit(1); }
$titlePos = strpos($html, '<h1');
$howPos = strpos($html, 'How to Use');
$formPos = strpos($html, '<section class="bg-[#0f172a] border border-slate-800/80 rounded-xl p-6');
if ($titlePos === false || $howPos === false || ($formPos !== false && $howPos > $formPos)) { fwrite(STDERR, "Timestamp converter How to Use order is invalid.\n"); exit(1); }
$registry = json_decode((string) file_get_contents($root . '/config/tools.json'), true);
$matches = array_values(array_filter($registry['tools'] ?? [], static fn(array $tool): bool => ($tool['slug'] ?? '') === 'timestamp-converter'));
if (count($matches) !== 1) { fwrite(STDERR, "Timestamp converter must appear exactly once in the registry.\n"); exit(1); }
$tool = $matches[0];
if (($tool['frontend'] ?? '') !== 'timestamp-converter.html' || ($tool['type'] ?? '') !== 'client_side' || ($tool['status'] ?? '') !== 'active') { fwrite(STDERR, "Timestamp converter registry contract is invalid.\n"); exit(1); }
echo "Timestamp converter validation passed.\n";
