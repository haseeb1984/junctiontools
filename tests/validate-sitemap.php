<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sitemapPath = $root . '/sitemap.xml';
$generator = $root . '/tools/generate-sitemap.php';
$registryPath = $root . '/config/tools.json';

if (!is_file($generator) || !is_file($registryPath)) {
    fwrite(STDERR, "Sitemap generator or registry missing.\n");
    exit(1);
}

passthru(PHP_BINARY . ' ' . escapeshellarg($generator), $exitCode);
if ($exitCode !== 0 || !is_file($sitemapPath)) {
    fwrite(STDERR, "Sitemap generation failed.\n");
    exit(1);
}

$dom = new DOMDocument();
$dom->preserveWhiteSpace = false;
if (!$dom->load($sitemapPath)) {
    fwrite(STDERR, "Generated sitemap is not valid XML.\n");
    exit(1);
}

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$nodes = $xpath->query('/sm:urlset/sm:url/sm:loc');
if ($nodes === false) {
    fwrite(STDERR, "Unable to read sitemap URLs.\n");
    exit(1);
}

$raw = file_get_contents($registryPath);
$data = json_decode((string)$raw, true);
$expected = 0;
foreach (($data['tools'] ?? []) as $tool) {
    if (($tool['status'] ?? '') === 'active' && ($tool['seo']['indexable'] ?? false) === true) {
        $expected++;
    }
}

$urls = [];
foreach ($nodes as $node) {
    $url = trim($node->textContent);
    if ($url === '' || isset($urls[$url])) {
        fwrite(STDERR, "Missing or duplicate sitemap URL: {$url}\n");
        exit(1);
    }
    if (!str_starts_with($url, 'https://junctiontools.com/')) {
        fwrite(STDERR, "Unexpected sitemap URL: {$url}\n");
        exit(1);
    }
    $urls[$url] = true;
}

if (count($urls) !== $expected) {
    fwrite(STDERR, "Sitemap URL count mismatch: expected {$expected}, found " . count($urls) . ".\n");
    exit(1);
}

echo 'Sitemap validation passed: ' . count($urls) . ' unique active indexable tool URLs.' . PHP_EOL;
