<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sitemapPath = $root . '/sitemap.xml';
$robotsPath = $root . '/robots.txt';
$htaccessPath = $root . '/.htaccess';
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

$raw = file_get_contents($registryPath);
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Registry JSON could not be decoded.\n");
    exit(1);
}

$site = rtrim((string)($data['site'] ?? 'https://junctiontools.com'), '/');
$expectedUrls = [];
foreach (($data['tools'] ?? []) as $tool) {
    if (($tool['status'] ?? '') !== 'active' || ($tool['seo']['indexable'] ?? false) !== true) {
        continue;
    }

    $slug = trim((string)($tool['slug'] ?? ''));
    $frontend = trim((string)($tool['frontend'] ?? ''));
    if ($slug === '' || $frontend === '') {
        fwrite(STDERR, "Active indexable tool is missing slug/frontend mapping.\n");
        exit(1);
    }

    if (!is_file($root . '/' . $frontend)) {
        fwrite(STDERR, "Registry frontend file missing: {$frontend}\n");
        exit(1);
    }

    $expectedUrls[$site . '/' . $slug] = true;
}
ksort($expectedUrls, SORT_STRING);

$xpath = new DOMXPath($dom);
$xpath->registerNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
$nodes = $xpath->query('/sm:urlset/sm:url/sm:loc');
if ($nodes === false) {
    fwrite(STDERR, "Unable to read sitemap URLs.\n");
    exit(1);
}

$urls = [];
foreach ($nodes as $node) {
    $url = trim($node->textContent);
    if ($url === '' || isset($urls[$url])) {
        fwrite(STDERR, "Missing or duplicate sitemap URL: {$url}\n");
        exit(1);
    }
    if (!str_starts_with($url, $site . '/')) {
        fwrite(STDERR, "Unexpected sitemap URL: {$url}\n");
        exit(1);
    }
    $urls[$url] = true;
}
ksort($urls, SORT_STRING);

if ($urls !== $expectedUrls) {
    $missing = array_keys(array_diff_key($expectedUrls, $urls));
    $unexpected = array_keys(array_diff_key($urls, $expectedUrls));
    fwrite(STDERR, 'Sitemap registry mismatch. Missing: ' . json_encode($missing) . ' Unexpected: ' . json_encode($unexpected) . PHP_EOL);
    exit(1);
}

if (!is_file($robotsPath)) {
    fwrite(STDERR, "robots.txt is missing.\n");
    exit(1);
}

$robots = (string)file_get_contents($robotsPath);
if (!preg_match('/^User-agent:\s*\*\s*$/m', $robots) || !preg_match('/^Allow:\s*\/\s*$/m', $robots)) {
    fwrite(STDERR, "robots.txt is missing the public allow policy.\n");
    exit(1);
}
if (!preg_match('/^Sitemap:\s*' . preg_quote($site . '/sitemap.xml', '/') . '\s*$/m', $robots)) {
    fwrite(STDERR, "robots.txt has no valid sitemap declaration.\n");
    exit(1);
}

if (!is_file($htaccessPath)) {
    fwrite(STDERR, ".htaccess is missing.\n");
    exit(1);
}

$htaccess = (string)file_get_contents($htaccessPath);
if (!str_contains($htaccess, 'RewriteCond %{THE_REQUEST} /([^.]+)\\.html [NC]') ||
    !str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME}\\.html -f') ||
    !str_contains($htaccess, 'RewriteRule ^(.*)$ $1.html [L]')) {
    fwrite(STDERR, "Canonical clean-URL rewrite rules are missing from .htaccess.\n");
    exit(1);
}

echo 'Sitemap validation passed: ' . count($urls) . ' registry-aligned tool URLs, robots.txt, and clean-URL routing verified.' . PHP_EOL;
