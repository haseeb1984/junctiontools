<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/age-calculator.html';
if (!is_file($file)) { fwrite(STDERR, "Age calculator page is missing.\n"); exit(1); }
$html = file_get_contents($file);
if (!is_string($html) || trim($html) === '') { fwrite(STDERR, "Age calculator page is empty.\n"); exit(1); }
$required = [
    '<!DOCTYPE html>',
    '<title>Free Age Calculator | Calculate Exact Age in Years, Months & Days | JunctionTools</title>',
    'https://junctiontools.com/age-calculator',
    'name="description"',
    'ageCalculator()',
    'birthDate',
    'asOfDate',
    'totalDays',
    'Date of Birth',
    'Calculate Age On'
];
foreach ($required as $needle) {
    if (stripos($html, $needle) === false) { fwrite(STDERR, "Age calculator is missing required marker: {$needle}\n"); exit(1); }
}
$registry = json_decode((string) file_get_contents($root . '/config/tools.json'), true);
$matches = array_values(array_filter($registry['tools'] ?? [], static fn(array $tool): bool => ($tool['slug'] ?? '') === 'age-calculator'));
if (count($matches) !== 1) { fwrite(STDERR, "Age calculator must appear exactly once in the registry.\n"); exit(1); }
$tool = $matches[0];
if (($tool['frontend'] ?? '') !== 'age-calculator.html' || ($tool['type'] ?? '') !== 'client_side' || ($tool['status'] ?? '') !== 'active' || ($tool['generation_eligibility'] ?? '') !== 'eligible') { fwrite(STDERR, "Age calculator registry contract is invalid.\n"); exit(1); }
echo "Age calculator validation passed.\n";
