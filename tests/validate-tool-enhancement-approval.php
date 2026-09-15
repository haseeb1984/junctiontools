<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$approvalPath = $root . '/config/tool-enhancement-approvals.json';
$registryPath = $root . '/config/tools.json';
$toolPath = $root . '/asset-optimizer.html';

if (!is_file($approvalPath) || !is_file($registryPath) || !is_file($toolPath)) {
    fwrite(STDERR, "FAIL: required enhancement artifacts are missing\n");
    exit(1);
}

$approval = json_decode((string) file_get_contents($approvalPath), true);
$registry = json_decode((string) file_get_contents($registryPath), true);

if (!is_array($approval) || ($approval['policy']['default'] ?? null) !== 'deny') {
    fwrite(STDERR, "FAIL: enhancement approval policy must default to deny\n");
    exit(1);
}

$match = null;
foreach ($approval['approvals'] ?? [] as $item) {
    if (($item['cluster_id'] ?? null) === 'image-resizing' && ($item['target_tool_slug'] ?? null) === 'image-compressor') {
        $match = $item;
        break;
    }
}

if (!$match || ($match['decision'] ?? null) !== 'approved-for-implementation-and-validation') {
    fwrite(STDERR, "FAIL: image resizing enhancement is not explicitly approved\n");
    exit(1);
}

if (($match['automatic_publication_allowed'] ?? true) !== false || ($match['registry_or_sitemap_modification_allowed'] ?? true) !== false) {
    fwrite(STDERR, "FAIL: enhancement approval must not authorize publication or registry/sitemap mutation\n");
    exit(1);
}

$tool = null;
foreach ($registry['tools'] ?? [] as $candidate) {
    if (($candidate['slug'] ?? null) === 'image-compressor') {
        $tool = $candidate;
        break;
    }
}

if (!$tool || ($tool['generation_eligibility'] ?? null) !== 'eligible') {
    fwrite(STDERR, "FAIL: image-compressor must remain an existing eligible tool\n");
    exit(1);
}

$html = (string) file_get_contents($toolPath);
$required = [
    'Free Image Compressor & Resizer',
    'Resize Mode',
    'resizeMode',
    'targetWidth',
    'targetHeight',
    'scalePercent',
    'targetSize',
    'image/webp',
    'image/avif',
    'image/jpeg',
    'image/png',
];

foreach ($required as $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "FAIL: enhancement implementation is missing required capability: {$needle}\n");
        exit(1);
    }
}

$forbiddenUploadPatterns = [
    '/<form[^>]+action=["\'][^"\']*["\']/i',
    '/XMLHttpRequest/i',
    '/navigator\.sendBeacon/i',
];
foreach ($forbiddenUploadPatterns as $pattern) {
    if (preg_match($pattern, $html)) {
        fwrite(STDERR, "FAIL: enhancement implementation exposes a server upload path\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: Image Compressor/Image Resizer enhancement is explicitly approved for implementation and validation, while publication and registry/sitemap mutation remain denied.\n");
