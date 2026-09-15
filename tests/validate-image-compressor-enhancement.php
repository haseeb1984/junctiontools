<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = $root . '/asset-optimizer.html';
$registry = $root . '/config/tools.json';
$queue = $root . '/config/tool-generation-queue.json';

foreach ([$page, $registry, $queue] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Missing required file: {$file}\n");
        exit(1);
    }
}

$html = file_get_contents($page);
$data = json_decode(file_get_contents($registry), true, 512, JSON_THROW_ON_ERROR);
$generation = json_decode(file_get_contents($queue), true, 512, JSON_THROW_ON_ERROR);

$required = [
    'Free Image Compressor & Resizer',
    'resizeMode',
    'targetWidth',
    'targetHeight',
    'scalePercent',
    'targetSize',
    '51200',
    '102400',
    'image/webp',
    'image/avif',
    'getDimensions',
    'fitTargetSize',
    'FileReader',
    'toBlob',
    'https://junctiontools.com/image-compressor',
];

foreach ($required as $needle) {
    if (strpos($html, $needle) === false) {
        fwrite(STDERR, "Missing image-resizing feature marker: {$needle}\n");
        exit(1);
    }
}

if (preg_match('/<form[^>]*action=["\'][^"\']*scanner|fetch\([^)]*scanner/i', $html)) {
    fwrite(STDERR, "Image processing must remain client-side; scanner/server submission detected.\n");
    exit(1);
}

$tool = null;
foreach ($data['tools'] ?? [] as $candidate) {
    if (($candidate['slug'] ?? '') === 'image-compressor') {
        $tool = $candidate;
        break;
    }
}

if (!$tool || ($tool['frontend'] ?? '') !== 'asset-optimizer.html' || ($tool['type'] ?? '') !== 'client_side') {
    fwrite(STDERR, "Image Compressor registry entry is missing or inconsistent.\n");
    exit(1);
}

$enhancement = null;
foreach ($generation['enhancements'] ?? [] as $candidate) {
    if (($candidate['cluster_id'] ?? '') === 'image-resizing') {
        $enhancement = $candidate;
        break;
    }
}

if (!$enhancement || ($enhancement['target_tool_slug'] ?? '') !== 'image-compressor') {
    fwrite(STDERR, "Image resizing enhancement queue entry is missing or mis-targeted.\n");
    exit(1);
}

$cluster = $enhancement['seo_page_cluster'] ?? [];
foreach (['image-resizer', 'resize-image-online', 'resize-image-to-50kb', 'resize-image-to-100kb'] as $querySlug) {
    if (!in_array($querySlug, $cluster, true)) {
        fwrite(STDERR, "Missing SEO cluster query: {$querySlug}\n");
        exit(1);
    }
}

if (!preg_match('/<meta name="description"[^>]+image compressor and resizer/i', $html)) {
    fwrite(STDERR, "SEO description does not identify the compressor/resizer capability.\n");
    exit(1);
}

echo "Image Compressor enhancement validation: PASS\n";
