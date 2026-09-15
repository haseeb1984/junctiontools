<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$registryPath = $root . '/config/tools.json';
$outputPath = $root . '/sitemap.xml';

if (!is_file($registryPath)) {
    fwrite(STDERR, "Registry missing: {$registryPath}\n");
    exit(1);
}

$raw = file_get_contents($registryPath);
if ($raw === false) {
    fwrite(STDERR, "Unable to read registry.\n");
    exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    fwrite(STDERR, 'Invalid registry JSON: ' . json_last_error_msg() . "\n");
    exit(1);
}

$site = rtrim((string)($data['site'] ?? ''), '/');
if ($site === '' || !filter_var($site, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Registry site URL is invalid.\n");
    exit(1);
}

$urls = [];
foreach (($data['tools'] ?? []) as $tool) {
    if (($tool['status'] ?? '') !== 'active') {
        continue;
    }
    if (($tool['seo']['indexable'] ?? false) !== true) {
        continue;
    }

    $slug = trim((string)($tool['slug'] ?? ''));
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        fwrite(STDERR, "Invalid sitemap slug: {$slug}\n");
        exit(1);
    }

    $urls[$site . '/' . $slug] = true;
}

ksort($urls, SORT_STRING);

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;
$urlset = $dom->createElement('urlset');
$urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$dom->appendChild($urlset);

foreach (array_keys($urls) as $url) {
    $urlNode = $dom->createElement('url');
    $loc = $dom->createElement('loc');
    $loc->appendChild($dom->createTextNode($url));
    $urlNode->appendChild($loc);
    $urlset->appendChild($urlNode);
}

if ($dom->save($outputPath) === false) {
    fwrite(STDERR, "Unable to write {$outputPath}.\n");
    exit(1);
}

echo 'Sitemap generated: ' . count($urls) . ' active indexable tool URLs.' . PHP_EOL;
