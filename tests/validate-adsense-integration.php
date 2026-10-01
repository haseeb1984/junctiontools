<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = $root . '/.htaccess';
$injector = $root . '/inject_ads.php';

if (!is_file($htaccess)) {
    fwrite(STDERR, "Missing .htaccess\n");
    exit(1);
}
if (!is_file($injector)) {
    fwrite(STDERR, "Missing inject_ads.php\n");
    exit(1);
}

$source = (string) file_get_contents($htaccess);
$injectorSource = (string) file_get_contents($injector);

// AdSense uses one centralized PHP output-buffer injection path.
// mod_substitute is intentionally not part of the contract.
if (strpos($source, 'mod_substitute') !== false || strpos($source, 'SUBSTITUTE') !== false) {
    fwrite(STDERR, "Legacy mod_substitute AdSense integration must not be present.\n");
    exit(1);
}

$requiredHtaccess = [
    'AddHandler application/x-httpd-lsphp .html .htm',
    'php_value auto_prepend_file',
    'inject_ads.php',
];

foreach ($requiredHtaccess as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "AdSense integration contract missing from .htaccess: {$needle}\n");
        exit(1);
    }
}

$requiredInjector = [
    'ob_start',
    'adsbygoogle.js?client=ca-pub-2009027605349204',
    'crossorigin="anonymous"',
    "stripos(\$buffer, '</head>')",
];

foreach ($requiredInjector as $needle) {
    if (strpos($injectorSource, $needle) === false) {
        fwrite(STDERR, "AdSense injector contract missing: {$needle}\n");
        exit(1);
    }
}

if (substr_count($injectorSource, 'adsbygoogle.js?client=ca-pub-2009027605349204') !== 1) {
    fwrite(STDERR, "AdSense script URL must appear exactly once in inject_ads.php.\n");
    exit(1);
}

foreach (['/privacy-policy', '/terms', '/disclaimer', '/contact'] as $excluded) {
    if (strpos($injectorSource, "'" . $excluded . "'") === false) {
        fwrite(STDERR, "Required AdSense exclusion missing: {$excluded}\n");
        exit(1);
    }
}

$htmlFiles = glob($root . '/*.html') ?: [];
if (count($htmlFiles) < 1) {
    fwrite(STDERR, "No public HTML pages found.\n");
    exit(1);
}

foreach ($htmlFiles as $file) {
    $content = (string) file_get_contents($file);
    if (stripos($content, 'adsbygoogle.js?client=ca-pub-2009027605349204') !== false) {
        fwrite(STDERR, "AdSense must remain centralized; duplicate publisher script found in " . basename($file) . "\n");
        exit(1);
    }
}

echo "AdSense integration contract validated: centralized inject_ads.php + PHP auto-prepend; no mod_substitute. Checked " . count($htmlFiles) . " public HTML pages.\n";
