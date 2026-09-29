<?php
declare(strict_types=1);

/**
 * JunctionTools tool-page content, SEO, internal-link and indexing quality guard.
 */
$root = dirname(__DIR__);
$excluded = ['index.html','header.html','footer.html','about.html','contact.html','privacy-policy.html','terms.html','disclaimer.html'];
$files = glob($root.'/*.html') ?: [];
$errors = [];
$checked = 0;
$toolSlugs = [];

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) continue;
    $slug = preg_replace('/\.html$/', '', $name);
    $toolSlugs[] = $slug;
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
    if (stripos($html, 'noindex') !== false) {
        $errors[] = "$name: contains noindex";
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

    if (!str_contains($html, 'related-tools-title')) {
        $errors[] = "$name: missing contextual related-tools section";
    } else {
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $links);
        $related = array_values(array_filter($links[1] ?? [], static function (string $href) use ($toolSlugs): bool {
            $slug = rtrim($href, '/');
            return in_array($slug, $toolSlugs, true);
        }));
        if (count(array_unique($related)) < 3) {
            $errors[] = "$name: fewer than 3 contextual internal tool links";
        }
    }
}

sort($toolSlugs);
if (count($toolSlugs) !== 38) {
    $errors[] = 'Expected 38 published tool pages, found '.count($toolSlugs);
}

$sitemapPath = $root.'/sitemap.xml';
if (!is_file($sitemapPath)) {
    $errors[] = 'sitemap.xml missing';
} else {
    $sitemap = (string) file_get_contents($sitemapPath);
    preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $sitemap, $m);
    $urls = array_values(array_unique($m[1] ?? []));
    $expected = ['https://junctiontools.com/'];
    foreach ($toolSlugs as $slug) {
        $expected[] = 'https://junctiontools.com/'.$slug;
    }
    sort($urls);
    $expectedSorted = $expected;
    sort($expectedSorted);
    if ($urls !== $expectedSorted) {
        $missing = array_values(array_diff($expectedSorted, $urls));
        $extra = array_values(array_diff($urls, $expectedSorted));
        if ($missing) $errors[] = 'sitemap missing: '.implode(', ', $missing);
        if ($extra) $errors[] = 'sitemap contains unexpected URLs: '.implode(', ', $extra);
    }
}

$robotsPath = $root.'/robots.txt';
if (!is_file($robotsPath)) {
    $errors[] = 'robots.txt missing';
} else {
    $robots = (string) file_get_contents($robotsPath);
    if (!preg_match('/^\s*Sitemap:\s*https:\/\/junctiontools\.com\/sitemap\.xml\s*$/mi', $robots)) {
        $errors[] = 'robots.txt missing canonical sitemap declaration';
    }
}

echo "JunctionTools SEO/content/indexing guard: checked {$checked} tool pages.\n";
if ($errors) {
    foreach ($errors as $error) echo "FAIL: {$error}\n";
    exit(1);
}
echo "PASS: all tool pages, contextual links, sitemap and indexing controls meet the contract.\n";
