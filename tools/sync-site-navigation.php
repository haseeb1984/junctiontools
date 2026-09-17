<?php
declare(strict_types=1);

/**
 * Synchronize published-tool references in the shared header, footer, and homepage.
 * Existing internal URLs are treated as authoritative and are never rewritten.
 * The sync is additive: it only adds missing published tools and updates the tool count.
 * Never changes config/tools.json or sitemap.xml.
 *
 * Usage: php tools/sync-site-navigation.php config/tools.json [--apply]
 */
$registryFile = $argv[1] ?? dirname(__DIR__) . '/config/tools.json';
$apply = in_array('--apply', $argv, true);
$root = dirname(__DIR__);
if (!is_file($registryFile)) { fwrite(STDERR, "Registry not found: {$registryFile}\n"); exit(1); }
$registry = json_decode((string) file_get_contents($registryFile), true);
if (!is_array($registry) || !is_array($registry['tools'] ?? null)) { fwrite(STDERR, "Invalid tool registry.\n"); exit(1); }

$tools = [];
foreach ($registry['tools'] as $tool) {
    if (!is_array($tool)) continue;
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) continue;
    if (($tool['status'] ?? 'active') !== 'active' || (($tool['seo']['indexable'] ?? true) !== true)) continue;
    $name = trim((string) ($tool['name'] ?? ''));
    if ($name === '') continue;
    $frontend = trim((string) ($tool['frontend'] ?? ''));
    $frontendBase = preg_replace('/\.html$/i', '', basename($frontend));
    $tools[$slug] = [
        'slug' => $slug,
        'name' => $name,
        'frontend' => $frontend,
        'frontendBase' => is_string($frontendBase) ? strtolower($frontendBase) : '',
    ];
}
ksort($tools);
$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$files = ['header.html', 'footer.html', 'index.html'];
$changed = [];

foreach ($files as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "Required site file missing: {$file}\n"); exit(1); }
    $content = (string) file_get_contents($path);
    $original = $content;
    $content = preg_replace('/All \d+ Tools/', 'All ' . count($tools) . ' Tools', $content) ?? $content;

    // Existing links are authoritative. A tool is already present if either its
    // public slug or its existing frontend filename is referenced anywhere.
    $missing = [];
    foreach ($tools as $tool) {
        $slugPattern = preg_quote($tool['slug'], '/');
        $frontendPattern = $tool['frontendBase'] !== '' ? preg_quote($tool['frontendBase'], '/') : '';
        $hasSlug = preg_match('/href=["\']' . $slugPattern . '(?:["\'?#])/i', $content) === 1;
        $hasFrontend = $frontendPattern !== '' && preg_match('/href=["\']' . $frontendPattern . '(?:\.html)?(?:["\'?#])/i', $content) === 1;
        if (!$hasSlug && !$hasFrontend) $missing[] = $tool;
    }

    if ($missing) {
        if ($file === 'header.html') {
            $links = '';
            foreach ($missing as $tool) $links .= '<a href="' . $esc($tool['slug']) . '" class="nav-item">' . $esc($tool['name']) . '</a>';
            $block = '<div class="relative group" data-generated-tool-links="true"><button class="nav-trigger">More Tools <i class="fa-solid fa-chevron-down text-[9px]"></i></button><div class="dropdown">' . $links . '</div></div>';
            $content = preg_replace('/<\/nav>/i', $block . '</nav>', $content, 1) ?? $content;
        } elseif ($file === 'footer.html') {
            $links = '<div class="space-y-2" data-generated-tool-links="true"><h4 class="font-bold text-white text-sm">More Tools</h4><ul class="space-y-1.5">';
            foreach ($missing as $tool) $links .= '<li><a href="' . $esc($tool['slug']) . '">' . $esc($tool['name']) . '</a></li>';
            $links .= '</ul></div>';
            $content = preg_replace('/<\/div>\s*<div class="flex flex-col md:flex-row justify-between/i', $links . '<div class="flex flex-col md:flex-row justify-between', $content, 1) ?? $content;
        } else {
            $cards = '';
            foreach ($missing as $tool) $cards .= '<a href="' . $esc($tool['slug']) . '" data-generated-tool-card="true" class="tool-card bg-[#0f172a]/80 border border-emerald-900/30 hover:border-emerald-500 p-4 rounded-xl flex items-center space-x-4 transition group"><div class="bg-emerald-500/10 text-emerald-400 p-3 rounded-lg"><i class="fa-solid fa-screwdriver-wrench text-lg"></i></div><div><h3 class="font-semibold text-white group-hover:text-emerald-400 transition">' . $esc($tool['name']) . '</h3><p class="text-xs text-slate-400">Free online tool</p></div></a>';
            $section = '<section class="space-y-4 tool-category" data-generated-tool-section="true"><h2 class="text-xl font-bold text-emerald-400 border-b border-emerald-900/40 pb-2 flex items-center gap-2"><i class="fa-solid fa-screwdriver-wrench"></i> Newly Published Tools</h2><div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">' . $cards . '</div></section>';
            $content = preg_replace('/<\/main>/i', $section . '</main>', $content, 1) ?? $content;
        }
    }
    if ($content !== $original) {
        $changed[] = $file;
        if ($apply && file_put_contents($path, $content, LOCK_EX) === false) { fwrite(STDERR, "Unable to update {$file}.\n"); exit(1); }
    }
}
$mode = $apply ? 'applied' : 'dry-run';
echo "Site navigation sync {$mode}: " . count($tools) . " active/indexable tools.\n";
echo $changed ? "Changed files: " . implode(', ', $changed) . "\n" : "No site navigation changes required.\n";
if (!$apply) echo "No files were modified. Re-run with --apply to update header.html, footer.html, and index.html.\n";
