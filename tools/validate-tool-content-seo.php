<?php
declare(strict_types=1);

/**
 * JunctionTools tool-page content and SEO quality guard.
 * Exit 0 when all published tool pages meet the minimum structural/content contract.
 */
$root = dirname(__DIR__);
$excluded = ['index.html','header.html','footer.html','about.html','contact.html','privacy-policy.html','terms.html','disclaimer.html'];
$files = glob($root.'/*.html') ?: [];
$errors = [];
$checked = 0;

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) continue;
    $html = (string) file_get_contents($file);
    $checked++;

    if (!preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m) || trim(strip_tags($m[1])) === '') {
        $errors[] = "$name: missing title";
    }
    if (!preg_match('/<meta\s+name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m) || mb_strlen(trim($m[1])) < 70) {
        $errors[] = "$name: missing/too-short meta description";
    }
    if (!preg_match('/<link\s+rel=["\']canonical["\'][^>]*href=["\']https:\/\/junctiontools\.com\/[^"\']+["\']/i', $html)) {
        $errors[] = "$name: missing JunctionTools canonical";
    }
    if (substr_count(strtolower($html), '<h1') !== 1) {
        $errors[] = "$name: expected exactly one H1";
    }
    if (!str_contains($html, 'JUNCTIONTOOLS_ADSENSE_CONTENT_V1')) {
        $errors[] = "$name: missing original tool-content section";
    }
    if (!preg_match('/<h2[^>]*>\s*How to Use It\s*<\/h2>/i', $html)) {
        $errors[] = "$name: missing How to Use section";
    }
    if (!preg_match('/<h2[^>]*>\s*Frequently Asked Questions\s*<\/h2>/i', $html)) {
        $errors[] = "$name: missing FAQ section";
    }
    if (!str_contains($html, 'application/ld+json')) {
        $errors[] = "$name: missing structured data";
    }
}

echo "JunctionTools SEO/content guard: checked {$checked} tool pages.\n";
if ($errors) {
    foreach ($errors as $error) echo "FAIL: {$error}\n";
    exit(1);
}
echo "PASS: all tool pages meet the content and SEO structure contract.\n";
