<?php
declare(strict_types=1);

/**
 * JunctionTools per-tool content, SEO, internal-link and indexing quality guard.
 * This is a release-blocking contract: every published tool page must carry
 * complete, tool-specific metadata and supporting content.
 */
$root = dirname(__DIR__);
$excluded = ['index.html','header.html','footer.html','about.html','contact.html','privacy-policy.html','terms.html','disclaimer.html'];
$files = glob($root.'/*.html') ?: [];
$errors = [];
$checked = 0;
$toolSlugs = [];
$titles = [];
$descriptions = [];

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) continue;
    $toolSlugs[] = preg_replace('/\.html$/', '', $name);
}
sort($toolSlugs);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $excluded, true)) continue;
    $slug = preg_replace('/\.html$/', '', $name);
    $html = (string) file_get_contents($file);
    $checked++;

    if (!preg_match('/<title[^>]*>\s*(.*?)\s*<\/title>/is', $html, $m)) {
        $errors[] = "$name: missing title";
    } else {
        $title = trim(strip_tags($m[1]));
        $titles[$name] = $title;
        $titleLen = mb_strlen($title);
        if ($titleLen < 25 || $titleLen > 70) $errors[] = "$name: title length $titleLen outside 25-70";
    }

    if (!preg_match('/<meta\s+name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
        $errors[] = "$name: missing meta description";
    } else {
        $description = trim($m[1]);
        $descriptions[$name] = $description;
        $descLen = mb_strlen($description);
        if ($descLen < 90 || $descLen > 170) $errors[] = "$name: meta description length $descLen outside 90-170";
    }

    if (!preg_match('/<meta\s+name=["\']keywords["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m) || count(array_filter(array_map('trim', explode(',', $m[1] ?? '')))) < 3) {
        $errors[] = "$name: missing/insufficient tool-specific keyword metadata";
    }

    $expectedCanonical = 'https://junctiontools.com/'.$slug;
    if (!preg_match('/<link\s+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m) || ($m[1] ?? '') !== $expectedCanonical) {
        $errors[] = "$name: canonical must be $expectedCanonical";
    }

    if (!preg_match('/<meta\s+name=["\']robots["\'][^>]*content=["\'][^"\']*index[^"\']*follow[^"\']*["\']/i', $html)) {
        $errors[] = "$name: missing index,follow robots directive";
    }

    foreach ([
        'og:type' => '/<meta\s+property=["\']og:type["\']/i',
        'og:url' => '/<meta\s+property=["\']og:url["\']/i',
        'og:title' => '/<meta\s+property=["\']og:title["\']/i',
        'og:description' => '/<meta\s+property=["\']og:description["\']/i',
        'twitter:card' => '/<meta\s+name=["\']twitter:card["\']/i',
        'twitter:title' => '/<meta\s+name=["\']twitter:title["\']/i',
        'twitter:description' => '/<meta\s+name=["\']twitter:description["\']/i',
    ] as $label => $pattern) {
        if (!preg_match($pattern, $html)) $errors[] = "$name: missing $label metadata";
    }

    if (substr_count(strtolower($html), '<h1') !== 1) $errors[] = "$name: expected exactly one H1";
    if (stripos($html, 'noindex') !== false) $errors[] = "$name: contains noindex";
    if (!str_contains($html, 'JUNCTIONTOOLS_ADSENSE_CONTENT_V1')) $errors[] = "$name: missing original tool-content section";
    if (!preg_match('/<h2[^>]*>\s*How to Use(?: It)?\s*<\/h2>/i', $html)) $errors[] = "$name: missing How to Use section";
    if (!preg_match('/<h2[^>]*>\s*Frequently Asked Questions\s*<\/h2>/i', $html)) $errors[] = "$name: missing FAQ section";
    if (!str_contains($html, 'application/ld+json')) $errors[] = "$name: missing structured data";
    if (!preg_match('/"@type"\s*:\s*"SoftwareApplication"/i', $html)) $errors[] = "$name: structured data must include SoftwareApplication";
    if (!preg_match('/"price"\s*:\s*"0"/i', $html)) $errors[] = "$name: structured data must declare free price";

    if (!str_contains($html, 'related-tools-title')) {
        $errors[] = "$name: missing contextual related-tools section";
    } else {
        preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $links);
        $related = array_values(array_filter($links[1] ?? [], static function (string $href) use ($toolSlugs): bool {
            return in_array(rtrim($href, '/'), $toolSlugs, true);
        }));
        if (count(array_unique($related)) < 3) $errors[] = "$name: fewer than 3 contextual internal tool links";
    }
}

foreach (array_count_values(array_values($titles)) as $value => $count) {
    if ($count > 1) $errors[] = "duplicate tool title: $value";
}
foreach (array_count_values(array_values($descriptions)) as $value => $count) {
    if ($count > 1) $errors[] = 'duplicate tool meta description';
}

if (count($toolSlugs) !== 38) $errors[] = 'Expected 38 published tool pages, found '.count($toolSlugs);

$sitemapPath = $root.'/sitemap.xml';
if (!is_file($sitemapPath)) {
    $errors[] = 'sitemap.xml missing';
} else {
    $sitemap = (string) file_get_contents($sitemapPath);
    preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $sitemap, $m);
    $urls = array_values(array_unique($m[1] ?? []));
    $expected = [
        'https://junctiontools.com/',
        'https://junctiontools.com/about',
        'https://junctiontools.com/contact',
        'https://junctiontools.com/terms',
        'https://junctiontools.com/privacy-policy',
        'https://junctiontools.com/disclaimer',
    ];
    foreach ($toolSlugs as $slug) $expected[] = 'https://junctiontools.com/'.$slug;
    sort($urls);
    sort($expected);
    if ($urls !== $expected) {
        $missing = array_values(array_diff($expected, $urls));
        $extra = array_values(array_diff($urls, $expected));
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
echo "PASS: every tool has tool-specific metadata, canonical/indexing controls, social metadata, structured data, content and contextual links; sitemap/robots are consistent.\n";
