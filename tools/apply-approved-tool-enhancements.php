<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security/build-security-gate.php';

/**
 * Apply an explicitly approved existing-tool SEO/content enhancement to a
 * staging copy only. The production page is never modified by this script.
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/apply-approved-tool-enhancements.php <specs.json> <approvals.json> [output-dir] [security-policy.json] [security-decisions.json] [tools.json]\n");
    exit(2);
}

$specFile = $argv[1];
$approvalFile = $argv[2];
$outputDir = $argv[3] ?? dirname(__DIR__) . '/generated-enhancements';
$securityPolicyFile = $argv[4] ?? dirname(__DIR__) . '/config/build-security-gate.json';
$securityDecisionFile = $argv[5] ?? dirname(__DIR__) . '/config/build-security-gate-decisions.json';
$registryFile = $argv[6] ?? dirname(__DIR__) . '/config/tools.json';
$root = dirname(__DIR__);

foreach ([$specFile, $approvalFile, $registryFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Input file missing: {$file}\n");
        exit(1);
    }
}

$specs = json_decode((string) file_get_contents($specFile), true);
$approvals = json_decode((string) file_get_contents($approvalFile), true);
$registry = json_decode((string) file_get_contents($registryFile), true);

if (!is_array($specs) || !is_array($specs['specifications'] ?? null) ||
    !is_array($approvals) || !is_array($approvals['approvals'] ?? null) ||
    !is_array($registry) || !is_array($registry['tools'] ?? null)) {
    fwrite(STDERR, "Invalid enhancement input.\n");
    exit(1);
}

if (($approvals['policy']['default'] ?? 'deny') !== 'deny') {
    fwrite(STDERR, "Enhancement approval policy must default to deny.\n");
    exit(1);
}

$registryBySlug = [];
foreach ($registry['tools'] as $tool) {
    if (is_array($tool) && isset($tool['slug'])) {
        $registryBySlug[strtolower(trim((string) $tool['slug']))] = $tool;
    }
}

function enhancement_esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function enhancement_title(string $query): string {
    $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $words = array_map(static function (string $word): string {
        return strtoupper(substr($word, 0, 1)) . strtolower(substr($word, 1));
    }, $words);
    return implode(' ', $words) . ' | JunctionTools';
}

function enhancement_description(string $query): string {
    return 'Use the JunctionTools ' . $query . ' online. Get a fast, privacy-conscious result in your browser with no account required.';
}

function replace_required_meta(string $html, string $query): string {
    $title = enhancement_title($query);
    $description = enhancement_description($query);
    $escapedTitle = enhancement_esc($title);
    $escapedDescription = enhancement_esc($description);

    $html = preg_replace('/<title>.*?<\/title>/is', '<title>' . $escapedTitle . '</title>', $html, 1, $titleCount);
    if ($titleCount !== 1) {
        throw new RuntimeException('Existing title element is missing or ambiguous.');
    }

    $html = preg_replace('/(<meta\s+name=["\']description["\']\s+content=["\']).*?(["\']\s*\/?>)/is', '$1' . $escapedDescription . '$2', $html, 1, $descriptionCount);
    if ($descriptionCount !== 1) {
        throw new RuntimeException('Existing meta description is missing or ambiguous.');
    }

    $html = preg_replace('/(<meta\s+property=["\']og:title["\']\s+content=["\']).*?(["\']\s*\/?>)/is', '$1' . $escapedTitle . '$2', $html, 1, $ogTitleCount);
    if ($ogTitleCount !== 1) {
        throw new RuntimeException('Existing og:title is missing or ambiguous.');
    }

    $html = preg_replace('/(<meta\s+property=["\']og:description["\']\s+content=["\']).*?(["\']\s*\/?>)/is', '$1' . $escapedDescription . '$2', $html, 1, $ogDescriptionCount);
    if ($ogDescriptionCount !== 1) {
        throw new RuntimeException('Existing og:description is missing or ambiguous.');
    }

    return $html;
}

function apply_seo_block(string $html, string $query, string $slug): string {
    $start = '<!-- JT-SEO-ENHANCEMENT-START:' . $slug . ' -->';
    $end = '<!-- JT-SEO-ENHANCEMENT-END:' . $slug . ' -->';
    $block = $start . "\n" .
        '<section class="jt-seo-enhancement" aria-labelledby="jt-seo-enhancement-title">' . "\n" .
        '  <h2 id="jt-seo-enhancement-title">About ' . enhancement_esc($query) . '</h2>' . "\n" .
        '  <p>This page provides a focused way to use the JunctionTools ' . enhancement_esc($query) . ' experience directly in your browser. Enter the required information, run the tool, and review the result.</p>' . "\n" .
        '  <h3>How to Use</h3>' . "\n" .
        '  <ol><li>Enter the information required by the tool.</li><li>Run the tool and review the result.</li><li>Copy or download the result when the page provides that option.</li></ol>' . "\n" .
        '</section>' . "\n" .
        $end;

    $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/is';
    if (preg_match($pattern, $html)) {
        $updated = preg_replace($pattern, $block, $html, 1, $count);
        if ($count !== 1) {
            throw new RuntimeException('Existing SEO enhancement marker could not be replaced.');
        }
        return $updated;
    }

    if (!preg_match('/<\/main>/i', $html)) {
        throw new RuntimeException('Existing tool page has no main container for SEO content.');
    }

    return preg_replace('/<\/main>/i', $block . "\n</main>", $html, 1);
}

$generated = 0;
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec) || ($spec['spec_type'] ?? '') !== 'enhancement') {
        continue;
    }

    $slug = strtolower(trim((string) ($spec['tool']['slug'] ?? '')));
    $query = trim((string) ($spec['source']['query'] ?? $spec['seo']['target_query'] ?? ''));
    $clusterId = (string) ($spec['source']['cluster_id'] ?? '');

    if ($slug === '' || $query === '' || !isset($registryBySlug[$slug])) {
        fwrite(STDERR, "Refusing enhancement without an existing registry target: {$slug}\n");
        exit(1);
    }

    $tool = $registryBySlug[$slug];
    $frontend = trim((string) ($tool['frontend'] ?? ''));
    if ($frontend === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $frontend)) {
        fwrite(STDERR, "Unsafe or missing frontend path for {$slug}.\n");
        exit(1);
    }

    $sourcePage = $root . '/' . $frontend;
    if (!is_file($sourcePage)) {
        fwrite(STDERR, "Existing tool page not found: {$frontend}\n");
        exit(1);
    }

    $approval = null;
    foreach ($approvals['approvals'] as $item) {
        if (!is_array($item)) continue;
        if (strtolower(trim((string) ($item['target_tool_slug'] ?? ''))) !== $slug) continue;
        if ($clusterId !== '' && (string) ($item['cluster_id'] ?? '') !== $clusterId) continue;
        $approval = $item;
        break;
    }

    if (!is_array($approval) || ($approval['decision'] ?? '') !== 'approved-for-implementation-and-validation') {
        fwrite(STDERR, "No explicit implementation approval for {$slug} / {$clusterId}.\n");
        exit(1);
    }

    if (($approval['automatic_publication_allowed'] ?? true) !== false ||
        ($approval['registry_or_sitemap_modification_allowed'] ?? true) !== false) {
        fwrite(STDERR, "Unsafe publication permissions for {$slug}.\n");
        exit(1);
    }

    if (($spec['generation_eligible'] ?? true) !== false ||
        ($spec['tool']['implementation_template'] ?? '') !== 'existing-tool-seo-enhancement' ||
        ($spec['security']['no_new_page'] ?? false) !== true ||
        ($spec['security']['no_new_registry_entry'] ?? false) !== true ||
        ($spec['security']['no_sitemap_mutation'] ?? false) !== true ||
        ($spec['seo']['must_preserve_existing_functionality'] ?? false) !== true) {
        fwrite(STDERR, "Enhancement specification is outside the approved existing-tool SEO contract: {$slug}.\n");
        exit(1);
    }

    $gate = bsg_load_and_evaluate($spec, $securityPolicyFile, $securityDecisionFile);
    if (($gate['allowed'] ?? false) !== true) {
        fwrite(STDERR, "Pre-build Security Gate rejected {$slug}: " . ($gate['error_code'] ?? 'security_gate_rejected') . " - " . ($gate['message'] ?? 'Rejected.') . "\n");
        exit(1);
    }

    $beforeHash = hash_file('sha256', $sourcePage);
    $html = (string) file_get_contents($sourcePage);
    $updated = replace_required_meta($html, $query);
    $updated = apply_seo_block($updated, $query, $slug);

    if ($updated === $html) {
        fwrite(STDERR, "Enhancement produced no page change for {$slug}.\n");
        exit(1);
    }

    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Unable to create output directory.\n");
        exit(1);
    }

    $outputPage = rtrim($outputDir, '/\\') . '/' . $frontend;
    if (file_put_contents($outputPage, $updated, LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write staged enhancement: {$outputPage}\n");
        exit(1);
    }

    if (hash_file('sha256', $sourcePage) !== $beforeHash) {
        fwrite(STDERR, "FAIL-CLOSED: source tool page changed during staging: {$frontend}\n");
        exit(1);
    }

    $generated++;
    echo "Staged approved SEO enhancement for {$slug}: {$outputPage}\n";
}

echo "Staged {$generated} existing-tool enhancement(s). Production files were not modified.\n";
