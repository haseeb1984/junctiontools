<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = $root . '/.htaccess';
if (!is_file($htaccess)) {
    fwrite(STDERR, "Missing .htaccess\n");
    exit(1);
}

$source = (string) file_get_contents($htaccess);

// Validate the central response-body injection contract while tolerating
// escaped quotes as represented inside Apache's Substitute directive.
$required = [
    '<IfModule mod_substitute.c>',
    'AddOutputFilterByType SUBSTITUTE text/html',
    'SubstituteMaxLineLength 10m',
    'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-2009027605349204',
    's|</head>|',
];

foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "AdSense integration contract missing: {$needle}\n");
        exit(1);
    }
}

if (!preg_match(
    '/adsbygoogle\.js\?client=ca-pub-2009027605349204[^\r\n]*crossorigin=\\?"anonymous\\?"/',
    $source
)) {
    fwrite(STDERR, "AdSense script tag contract missing or malformed.\n");
    exit(1);
}

if (substr_count($source, 'ca-pub-2009027605349204') !== 1) {
    fwrite(STDERR, "AdSense publisher ID must appear exactly once in .htaccess.\n");
    exit(1);
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

echo "AdSense integration contract validated for " . count($htmlFiles) . " public HTML pages.\n";
