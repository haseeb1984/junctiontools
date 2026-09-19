<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$file = $root . '/qr-code-generator.html';
if (!is_file($file)) { fwrite(STDERR, "QR code generator page is missing.\n"); exit(1); }
$html = file_get_contents($file);
if (!is_string($html) || trim($html) === '') { fwrite(STDERR, "QR code generator page is empty.\n"); exit(1); }
$required = [
    '<!DOCTYPE html>',
    '<title>Free QR Code Generator | JunctionTools</title>',
    'https://junctiontools.com/qr-code-generator',
    'name="description"',
    'qrcode-generator@2.0.4',
    'typeof qrcode!==\'function\'',
    'qr.isDark',
    'Download PNG',
    'x-model="level"',
    'Private by Design',
    'How to Use',
    'qrGenerator()'
];
foreach ($required as $needle) {
    if (stripos($html, $needle) === false) { fwrite(STDERR, "QR code generator is missing required marker: {$needle}\n"); exit(1); }
}
if (stripos($html, 'QRCode.toCanvas') !== false) { fwrite(STDERR, "QR generator still references the broken qrcode.toCanvas API.\n"); exit(1); }
if (preg_match('/text-cyan-|bg-cyan-|border-cyan-/i', $html)) { fwrite(STDERR, "QR generator contains non-brand cyan styling.\n"); exit(1); }
$registry = json_decode((string) file_get_contents($root . '/config/tools.json'), true);
$matches = array_values(array_filter($registry['tools'] ?? [], static fn(array $tool): bool => ($tool['slug'] ?? '') === 'qr-code-generator'));
if (count($matches) !== 1) { fwrite(STDERR, "QR code generator must appear exactly once in the registry.\n"); exit(1); }
$tool = $matches[0];
if (($tool['frontend'] ?? '') !== 'qr-code-generator.html' || ($tool['type'] ?? '') !== 'client_side' || ($tool['status'] ?? '') !== 'active') { fwrite(STDERR, "QR code generator registry contract is invalid.\n"); exit(1); }
echo "QR code generator validation passed.\n";
