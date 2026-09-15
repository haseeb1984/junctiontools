<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$script = $root . '/tools/sync-site-navigation.php';
$registryFile = $root . '/config/tools.json';
foreach ([$script, $registryFile, $root . '/header.html', $root . '/footer.html', $root . '/index.html'] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "Missing required file: {$path}\n"); exit(1); }
}

$source = (string) file_get_contents($script);
$required = [
    "['header.html', 'footer.html', 'index.html']",
    'config/tools.json',
    'All \\d+ Tools',
    'data-generated-tool-links="true"',
    'data-generated-tool-card="true"',
    'Never changes config/tools.json or sitemap.xml',
    "in_array('--apply', \$argv, true)",
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false) { fwrite(STDERR, "Navigation sync contract missing: {$needle}\n"); exit(1); }
}

$registry = json_decode((string) file_get_contents($registryFile), true);
if (!is_array($registry) || !is_array($registry['tools'] ?? null)) { fwrite(STDERR, "Invalid tool registry.\n"); exit(1); }
$count = 0;
foreach ($registry['tools'] as $tool) {
    if (!is_array($tool)) continue;
    if (($tool['status'] ?? 'active') !== 'active' || (($tool['seo']['indexable'] ?? true) !== true)) continue;
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) { fwrite(STDERR, "Invalid active tool slug.\n"); exit(1); }
    $count++;
}
if ($count < 1) { fwrite(STDERR, "No active indexable tools found.\n"); exit(1); }

$header = (string) file_get_contents($root . '/header.html');
$footer = (string) file_get_contents($root . '/footer.html');
if (strpos($header, '.html"') !== false || strpos($footer, '.html"') !== false) { fwrite(STDERR, "Shared navigation contains a public .html link.\n"); exit(1); }
if (strpos($source, 'config/tools.json or sitemap.xml') === false) { fwrite(STDERR, "Navigation sync safety contract missing.\n"); exit(1); }
if (strpos($source, 'never_publish_without_explicit_approval') !== false) { fwrite(STDERR, "Navigation sync must not become a publication bypass.\n"); exit(1); }

echo "Site navigation sync contract validated for {$count} active/indexable tools.\n";
