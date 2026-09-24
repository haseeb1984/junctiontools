<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security/build-security-gate.php';
require_once dirname(__DIR__) . '/security/adsense-compliance-gate.php';

/**
 * Publish an explicitly approved NEW tool into an isolated or production root.
 *
 * This is the final manual publication step. Generation approval alone is not
 * sufficient: the publication-review file must contain an explicit human
 * approval. Existing-tool enhancements must never enter this flow.
 *
 * Usage:
 * php tools/publish-approved-new-tool.php <specs.json> <generation-approvals.json> <publication-review.json> <generated-dir> [root-dir] [registry.json] [sitemap.xml]
 */

if ($argc < 5) {
    fwrite(STDERR, "Usage: php tools/publish-approved-new-tool.php <specs.json> <generation-approvals.json> <publication-review.json> <generated-dir> [root-dir] [registry.json] [sitemap.xml]\n");
    exit(2);
}

$specFile = $argv[1];
$generationApprovalFile = $argv[2];
$publicationReviewFile = $argv[3];
$generatedDir = rtrim($argv[4], '/\\');
$root = rtrim($argv[5] ?? dirname(__DIR__), '/\\');
$registryFile = $argv[6] ?? $root . '/config/tools.json';
$sitemapFile = $argv[7] ?? $root . '/sitemap.xml';

foreach ([$specFile, $generationApprovalFile, $publicationReviewFile, $registryFile, $sitemapFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Required publication input missing: {$file}\n");
        exit(1);
    }
}
foreach (['header.html', 'footer.html', 'index.html'] as $file) {
    if (!is_file($root . '/' . $file)) {
        fwrite(STDERR, "Required production site file missing: {$file}\n");
        exit(1);
    }
}

$specs = json_decode((string) file_get_contents($specFile), true);
$generationApprovals = json_decode((string) file_get_contents($generationApprovalFile), true);
$review = json_decode((string) file_get_contents($publicationReviewFile), true);
$registry = json_decode((string) file_get_contents($registryFile), true);
$sitemap = (string) file_get_contents($sitemapFile);

if (!is_array($specs) || !is_array($specs['specifications'] ?? null) ||
    !is_array($generationApprovals) || !is_array($generationApprovals['approvals'] ?? null) ||
    !is_array($review) || !is_array($review['tools'] ?? null) ||
    !is_array($registry) || !is_array($registry['tools'] ?? null)) {
    fwrite(STDERR, "Invalid publication input schema.\n");
    exit(1);
}

if (($review['review_type'] ?? '') !== 'new-tool-publication' ||
    ($review['policy']['default_decision'] ?? 'reject-until-explicitly-approved') !== 'reject-until-explicitly-approved' ||
    ($review['policy']['automatic_publication_allowed'] ?? true) !== false) {
    fwrite(STDERR, "Unsafe new-tool publication policy.\n");
    exit(1);
}

$approvedGeneration = [];
foreach ($generationApprovals['approvals'] as $item) {
    if (!is_array($item)) continue;
    $slug = strtolower(trim((string) ($item['slug'] ?? '')));
    if ($slug !== '' && ($item['approved'] ?? false) === true) {
        $approvedGeneration[$slug] = true;
    }
}

$specBySlug = [];
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) continue;
    $tool = $spec['tool'] ?? [];
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    if ($slug !== '') $specBySlug[$slug] = $spec;
}

$reviewed = [];
foreach ($review['tools'] as $item) {
    if (!is_array($item)) continue;
    $slug = strtolower(trim((string) ($item['slug'] ?? '')));
    if ($slug === '') continue;
    if (($item['decision'] ?? '') !== 'approved' ||
        trim((string) ($item['reviewer'] ?? '')) === '' ||
        trim((string) ($item['reviewed_at'] ?? '')) === '') {
        continue;
    }
    $reviewed[$slug] = $item;
}

if (!$reviewed) {
    fwrite(STDERR, "No explicitly human-approved new tool is eligible for publication.\n");
    exit(1);
}

function pub_esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pub_icon(array $spec): string {
    $icon = trim((string) ($spec['ui']['icon'] ?? $spec['tool']['icon'] ?? 'fa-screwdriver-wrench'));
    return preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-screwdriver-wrench';
}

function pub_description(array $spec): string {
    $description = trim((string) ($spec['seo']['short_description'] ?? $spec['seo']['description'] ?? 'New JunctionTools utility'));
    return $description !== '' ? $description : 'New JunctionTools utility';
}

function pub_category(array $spec): string {
    $category = strtolower(trim((string) ($spec['tool']['category'] ?? '')));
    if ($category === '') {
        throw new RuntimeException('New tool is missing a category.');
    }
    return $category;
}

/**
 * Map registry categories to the site's existing navigation/category buckets.
 * Unknown categories fail closed instead of silently placing a tool in the
 * wrong section.
 */
function pub_bucket(string $category): string {
    return match ($category) {
        'speed-performance', 'analytics' => 'performance',
        'accessibility', 'ux', 'seo' => 'ux-seo',
        'ecommerce-conversion', 'business' => 'ecommerce',
        'design', 'social-media', 'content', 'text' => 'design-content',
        'developer', 'communication' => 'developer',
        'calculators', 'generators' => 'utilities',
        default => throw new RuntimeException("Unsupported new-tool category for site navigation: {$category}"),
    };
}

function pub_nav_link(array $tool): string {
    return '<a href="' . pub_esc($tool['slug']) . '">' . pub_esc($tool['name']) . '</a>';
}

function pub_card(array $tool): string {
    return '<a href="' . pub_esc($tool['slug']) . '" class="tool-card bg-[#0f172a]/80 border border-emerald-900/30 hover:border-emerald-500 p-4 rounded-xl flex items-center space-x-4 transition group"><div class="bg-emerald-500/10 text-emerald-400 p-3 rounded-lg group-hover:bg-emerald-500 group-hover:text-black transition"><i class="fa-solid ' . pub_esc($tool['icon']) . ' text-lg"></i></div><div><h3 class="font-semibold text-white group-hover:text-emerald-400 transition">' . pub_esc($tool['name']) . '</h3><p class="text-xs text-slate-400">' . pub_esc($tool['description']) . '</p></div></a>';
}

function pub_insert_before_closing(string $html, string $sectionPattern, string $markup, string $error): string {
    if (!preg_match($sectionPattern, $html, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException($error);
    }
    $pos = $m[0][1];
    return substr($html, 0, $pos) . $markup . substr($html, $pos);
}

function pub_update_nav_bucket(string $html, string $bucket, array $tool): string {
    $labels = [
        'performance' => 'Performance',
        'ux-seo' => 'UX & SEO',
        'ecommerce' => 'E-Commerce',
        'design-content' => 'Design & Content',
        'developer' => 'Developer',
        'utilities' => 'Utilities',
    ];
    $label = $labels[$bucket];
    $link = pub_nav_link($tool);

    $pattern = '/(<div class="relative group"><button class="nav-trigger">' . preg_quote($label, '/') . '.*?<div class="dropdown(?: right-0)?">)(.*?)(<\/div><\/div>)/s';
    if (!preg_match($pattern, $html)) {
        throw new RuntimeException("Header desktop category missing: {$label}");
    }
    $html = preg_replace($pattern, '$1$2' . $link . '$3', $html, 1);

    $mobilePattern = '/(<div><p class="font-bold text-slate-200 mb-1">' . preg_quote($label, '/') . '<\/p><div class="grid gap-2 text-slate-400">)(.*?)(<\/div><\/div>)/s';
    if (!preg_match($mobilePattern, $html)) {
        throw new RuntimeException("Header mobile category missing: {$label}");
    }
    return preg_replace($mobilePattern, '$1$2' . $link . '$3', $html, 1);
}

function pub_update_footer_bucket(string $html, string $bucket, array $tool): string {
    $labels = [
        'performance' => 'Performance',
        'ux-seo' => 'UX & SEO',
        'ecommerce' => 'E-Commerce',
        'design-content' => 'Design & Content',
        'developer' => 'Developer',
        'utilities' => 'Utilities',
    ];
    $label = $labels[$bucket];
    $link = '<li>' . pub_nav_link($tool) . '</li>';
    $pattern = '/(<div class="space-y-2"><h4 class="font-bold text-white text-sm">' . preg_quote($label, '/') . '<\/h4><ul class="space-y-1\.5">)(.*?)(<\/ul><\/div>)/s';
    if (!preg_match($pattern, $html)) {
        throw new RuntimeException("Footer category missing: {$label}");
    }
    return preg_replace($pattern, '$1$2' . $link . '$3', $html, 1);
}

function pub_update_index_bucket(string $html, string $bucket, array $tool): string {
    $sections = [
        'performance' => 'CATEGORY 1: Speed, Performance & Analytics',
        'ux-seo' => 'CATEGORY 2: UX, Accessibility & SEO',
        'ecommerce' => 'CATEGORY 3: E-Commerce, Conversion & Trust',
        'design-content' => 'CATEGORY 4: Design, Media & Content',
        'developer' => 'CATEGORY 5: Developer Utilities & Integrations',
        'utilities' => 'CATEGORY 6: Utilities & Generators',
    ];
    $marker = $sections[$bucket];
    $card = pub_card($tool);
    $pattern = '/(<!-- ' . preg_quote($marker, '/') . ' -->.*?<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">)(.*?)(<\/div>\s*<\/section>)/s';
    if (!preg_match($pattern, $html)) {
        throw new RuntimeException("Index category section missing: {$marker}");
    }
    return preg_replace($pattern, '$1$2' . $card . '$3', $html, 1);
}

function pub_update_count(string $html, int $totalTools): string {
    $html = preg_replace('/Access \d+ completely free online tools/', 'Access ' . $totalTools . ' completely free online tools', $html, 1);
    return preg_replace('/All \d+ Tools/', 'All ' . $totalTools . ' Tools', $html, 1);
}

function pub_sitemap_add(string $sitemap, string $slug): string {
    $url = 'https://junctiontools.com/' . $slug;
    if (preg_match('/<loc>' . preg_quote($url, '/') . '<\/loc>/i', $sitemap)) {
        throw new RuntimeException("Sitemap already contains {$slug}; refusing duplicate publication.");
    }
    $entry = '  <url><loc>' . $url . '</loc></url>\n';
    $updated = pub_insert_before_closing($sitemap, '/<\/urlset>\s*$/i', $entry, 'Sitemap closing element missing.');
    preg_match_all('/<url><loc>(https:\/\/junctiontools\.com\/[^<]+)<\/loc><\/url>/i', $updated, $matches);
    $urls = array_values(array_unique($matches[1] ?? []));
    sort($urls, SORT_STRING);
    $body = '';
    foreach ($urls as $item) $body .= "  <url><loc>{$item}</loc></url>\n";
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n" . $body . "</urlset>\n";
}

function pub_registry_entry(array $spec): array {
    $tool = $spec['tool'] ?? [];
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    $name = (string) ($tool['name'] ?? ucwords(str_replace('-', ' ', $slug)));
    $category = pub_category($spec);
    return [
        'id' => strtolower(trim((string) ($tool['id'] ?? str_replace('-', '_', $slug)))),
        'name' => $name,
        'slug' => $slug,
        'category' => $category,
        'type' => (string) ($tool['type'] ?? $tool['implementation'] ?? 'client_side'),
        'input' => $tool['input'] ?? ($spec['inputs']['fields'] ?? 'text'),
        'output' => $tool['output'] ?? ($spec['outputs']['fields'] ?? 'result'),
        'frontend' => $slug . '.html',
        'backend' => $tool['backend'] ?? null,
        'seo' => [
            'indexable' => (bool) ($spec['seo']['indexable'] ?? true),
            'metadata_source' => 'frontend',
        ],
        'status' => 'active',
        'test_coverage' => 'compatibility',
        'generation_eligibility' => 'eligible',
    ];
}

$publishItems = [];
foreach ($reviewed as $slug => $reviewItem) {
    if (!isset($approvedGeneration[$slug])) {
        throw new RuntimeException("Publication approval exists without approved generation approval: {$slug}");
    }
    if (!isset($specBySlug[$slug])) {
        throw new RuntimeException("Publication approval has no matching specification: {$slug}");
    }
    $spec = $specBySlug[$slug];
    if (($spec['spec_type'] ?? 'new-tool') === 'enhancement' || isset($spec['enhancement'])) {
        throw new RuntimeException("Existing-tool enhancement cannot use new-tool publication flow: {$slug}");
    }
    $tool = $spec['tool'] ?? [];
    if (($tool['slug'] ?? '') !== $slug) {
        throw new RuntimeException("Specification slug mismatch for {$slug}");
    }
    $generatedPage = $generatedDir . '/' . $slug . '.html';
    if (!is_file($generatedPage)) {
        throw new RuntimeException("Generated page missing for approved new tool: {$slug}");
    }
    $publishItems[] = [
        'slug' => $slug,
        'name' => (string) ($tool['name'] ?? ucwords(str_replace('-', ' ', $slug))),
        'description' => pub_description($spec),
        'icon' => pub_icon($spec),
        'category' => pub_category($spec),
        'bucket' => pub_bucket(pub_category($spec)),
        'spec' => $spec,
        'review' => $reviewItem,
        'generated_page' => $generatedPage,
    ];
}

if (!$publishItems) {
    fwrite(STDERR, "No new tools passed all publication gates.\n");
    exit(1);
}

$adsense = adsense_compliance_evaluate($root);
if (($adsense['allowed'] ?? false) !== true) {
    fwrite(STDERR, "AdSense publication gate rejected new-tool publication: " . ($adsense['error_code'] ?? 'adsense_rejected') . " - " . ($adsense['message'] ?? 'Rejected.') . "\n");
    exit(1);
}

$existingSlugs = [];
foreach ($registry['tools'] as $item) {
    if (!is_array($item)) continue;
    $existingSlugs[strtolower(trim((string) ($item['slug'] ?? '')))] = true;
}

foreach ($publishItems as $item) {
    if (isset($existingSlugs[$item['slug']])) {
        throw new RuntimeException("Registry already contains {$item['slug']}; refusing duplicate publication.");
    }
    if (is_file($root . '/' . $item['slug'] . '.html')) {
        throw new RuntimeException("Production root already contains {$item['slug']}.html; refusing overwrite.");
    }
}

$header = (string) file_get_contents($root . '/header.html');
$footer = (string) file_get_contents($root . '/footer.html');
$index = (string) file_get_contents($root . '/index.html');

$totalTools = count($registry['tools']) + count($publishItems);
foreach ($publishItems as $item) {
    $header = pub_update_nav_bucket($header, $item['bucket'], $item);
    $footer = pub_update_footer_bucket($footer, $item['bucket'], $item);
    $index = pub_update_index_bucket($index, $item['bucket'], $item);
}
$header = pub_update_count($header, $totalTools);
$index = pub_update_count($index, $totalTools);

$newRegistry = $registry;
foreach ($publishItems as $item) {
    $newRegistry['tools'][] = pub_registry_entry($item['spec']);
}
$newRegistryJson = json_encode($newRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$newSitemap = $sitemap;
foreach ($publishItems as $item) {
    $newSitemap = pub_sitemap_add($newSitemap, $item['slug']);
}

foreach ($publishItems as $item) {
    $target = $root . '/' . $item['slug'] . '.html';
    if (!copy($item['generated_page'], $target)) {
        throw new RuntimeException("Unable to publish generated page: {$item['slug']}");
    }
}
if (file_put_contents($root . '/header.html', $header, LOCK_EX) === false ||
    file_put_contents($root . '/footer.html', $footer, LOCK_EX) === false ||
    file_put_contents($root . '/index.html', $index, LOCK_EX) === false ||
    file_put_contents($registryFile, $newRegistryJson, LOCK_EX) === false ||
    file_put_contents($sitemapFile, $newSitemap, LOCK_EX) === false) {
    fwrite(STDERR, "Publication write failed. Review the working tree before continuing.\n");
    exit(1);
}

echo "Published " . count($publishItems) . " approved new tool(s).\n";
foreach ($publishItems as $item) {
    echo "[PUBLISHED] {$item['slug']} -> {$item['bucket']} category, registry, sitemap\n";
}
echo "Tool count: {$totalTools}\n";
echo "Publication was explicitly human-approved; automatic publication remains disabled.\n";
