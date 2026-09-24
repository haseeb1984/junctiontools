<?php
declare(strict_types=1);

/** Build a conservative demand queue. Existing capabilities are routed to enhancement, never duplicate generation. */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-generation-queue.php <opportunities.json> <tools.json> [output.json]\n");
    exit(2);
}
$opportunityFile = $argv[1];
$registryFile = $argv[2];
$output = $argv[3] ?? dirname(__DIR__) . '/config/generated-tool-generation-queue.json';

foreach ([$opportunityFile, $registryFile] as $file) {
    if (!is_file($file)) { fwrite(STDERR, "File not found: {$file}\n"); exit(1); }
}
$opportunities = json_decode((string)file_get_contents($opportunityFile), true);
$registry = json_decode((string)file_get_contents($registryFile), true);
if (!is_array($opportunities) || !is_array($opportunities['opportunities'] ?? null) || !is_array($registry) || !is_array($registry['tools'] ?? null)) {
    fwrite(STDERR, "Invalid opportunity or registry input.\n"); exit(1);
}

function normalize_demand_term(string $value): string {
    return strtolower(trim(preg_replace('~[^a-z0-9]+~i', '-', $value) ?? '', '-'));
}
function demand_tokens(string $value): array {
    $value = strtolower(preg_replace('~[^a-z0-9]+~i', ' ', $value) ?? '');
    $aliases = ['unix' => 'timestamp'];
    $stop = ['a','an','and','for','free','how','in','my','of','online','the','to','tool','use','with'];
    $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_map(static fn(string $t): string => $aliases[$t] ?? $t, $tokens);
    return array_values(array_unique(array_filter($tokens, static fn(string $t): bool => strlen($t) > 1 && !in_array($t, $stop, true))));
}
function demand_profile(string $query): array {
    $normalized = normalize_demand_term($query);
    $profile = ['required_capabilities' => [], 'intent' => 'unknown'];

    $rules = [
        ['~(?:word|character|line|sentence)\\s+(?:counter|count)|count\\s+(?:words|characters|lines|sentences)~i',
            ['accept_text','analyze_text','count_text'], 'text-counting'],
        ['~(?:uppercase|lowercase|title case|sentence case|capitalize|case)\\s+(?:converter|convert|changer|change)~i',
            ['accept_text','transform_text','change_letter_case'], 'text-transformation'],
        ['~(?:clean|cleanup|remove)\\s+(?:text|whitespace|spaces)|extra\\s+spaces?~i',
            ['accept_text','transform_text','clean_text'], 'text-cleaning'],
        ['~(?:json)\\s+(?:formatter|format|beautifier|beautify|pretty)~i',
            ['accept_json','parse_json','format_json'], 'json-formatting'],
        ['~\bbase64\b~i',
            ['accept_text','encode_decode_base64'], 'base64-conversion'],
        ['~\b(?:regex|regular\s+expression)\b.*?(?:tester|test|checker|check)~i',
            ['accept_pattern','accept_text','test_regex'], 'regex-testing'],
        ['~\b(?:unix|epoch|timestamp)\b.*?(?:converter|convert)~i',
            ['accept_timestamp_or_date','convert_timestamp'], 'timestamp-conversion'],
        ['~\b(?:qr|qrcode|qr\s+code)\b.*?(?:generator|generate)~i',
            ['accept_text_or_url','generate_qr_code','download_png'], 'qr-generation'],
        ['~\bage\b.*?(?:calculator|calculate)~i',
            ['accept_birth_date','calculate_age'], 'age-calculation'],
        ['(?:discount).*?(?:calculator|calculate)/i',
            ['accept_price','accept_percentage','calculate_discount'], 'discount-calculation'],
        ['~\binvoice\b.*?(?:generator|generate|maker|create)~i',
            ['accept_invoice_data','generate_invoice_document'], 'invoice-generation'],
        ['~\buuid\b.*?(?:generator|generate)~i',
            ['generate_uuid'], 'uuid-generation'],
        ['~(?:sha.?256|sha.?256 hash|hash).*?(?:generator|generate|calculator|calculate)~i',
            ['accept_text','generate_sha256_hash'], 'hash-generation'],
        ['~(?:px|pixel).*?(?:to|\\-).*?(?:rem)~i',
            ['accept_dimensions','convert_px_to_rem'], 'px-rem-conversion'],
        ['~\bwhatsapp\b.*?(?:link|url)~i',
            ['accept_phone_and_message','generate_whatsapp_url'], 'whatsapp-link-generation'],
        ['~\bcolor\b.*?\bpalette\b.*?(?:generator|generate)~i',
            ['accept_color','generate_color_palette'], 'color-palette-generation'],
        ['~\baspect\s+ratio\b.*?(?:calculator|calculate)~i',
            ['accept_dimensions','calculate_aspect_ratio'], 'aspect-ratio-calculation'],
        ['~\b(?:contrast|wcag)\b.*?(?:checker|check|audit)~i',
            ['accept_url','evaluate_color_contrast'], 'contrast-audit'],
        ['~\b(?:readability|flesch)\b.*?(?:checker|check|calculator|score|test|audit)~i',
            ['accept_url','evaluate_readability'], 'readability-audit'],
        ['~\b(?:meta|meta\s+tags)\b.*?(?:seo|checker|audit)~i',
            ['accept_url','audit_meta_seo'], 'meta-seo-audit'],
    ];

    foreach ($rules as [$pattern, $capabilities, $intent]) {
        if (preg_match($pattern, $query)) {
            return ['required_capabilities' => $capabilities, 'intent' => $intent];
        }
    }

    return $profile;
}

function tool_capabilities(array $tool): array {
    $profiles = [
        'word_counter' => ['accept_text','analyze_text','count_text'],
        'case_converter' => ['accept_text','transform_text','change_letter_case'],
        'clean_text_tool' => ['accept_text','transform_text','clean_text'],
        'json_formatter' => ['accept_json','parse_json','format_json'],
        'base64_converter' => ['accept_text','encode_decode_base64'],
        'regex_tester' => ['accept_pattern','accept_text','test_regex'],
        'timestamp_converter' => ['accept_timestamp_or_date','convert_timestamp'],
        'qr_code_generator' => ['accept_text_or_url','generate_qr_code','download_png'],
        'age_calculator' => ['accept_birth_date','calculate_age'],
        'discount_calculator' => ['accept_price','accept_percentage','calculate_discount'],
        'invoice_generator' => ['accept_invoice_data','generate_invoice_document'],
        'uuid_generator' => ['generate_uuid'],
        'sha256_hash' => ['accept_text','generate_sha256_hash'],
        'px_to_rem' => ['accept_dimensions','convert_px_to_rem'],
        'whatsapp_link' => ['accept_phone_and_message','generate_whatsapp_url'],
        'color_palette_generator' => ['accept_color','generate_color_palette'],
        'aspect_ratio_calculator' => ['accept_dimensions','calculate_aspect_ratio'],
        'contrast_checker' => ['accept_url','evaluate_color_contrast'],
        'readability_evaluator' => ['accept_url','evaluate_readability'],
        'meta_seo_checker' => ['accept_url','audit_meta_seo'],
    ];

    $id = (string)($tool['id'] ?? '');
    return $profiles[$id] ?? [];
}

function find_existing_tool(string $query, array $tools): ?array {
    $demand = demand_profile($query);
    $required = $demand['required_capabilities'];

    // Capability matching is the authoritative duplicate/overlap check.
    // Name/slug similarity is deliberately not used to create an enhancement match.
    if (!$required) {
        return null;
    }

    $best = null;
    foreach ($tools as $tool) {
        if (!is_array($tool)) continue;
        $capabilities = tool_capabilities($tool);
        if (!$capabilities) continue;

        $missing = array_values(array_diff($required, $capabilities));
        if ($missing) continue;

        $best = [
            'tool' => $tool,
            'match_type' => 'capability',
            'score' => 1.0,
            'intent' => $demand['intent'],
            'required_capabilities' => $required,
            'matched_capabilities' => $required,
        ];
        break;
    }

    return $best;
}

$queue = [];
$rank = 1;
foreach ($opportunities['opportunities'] as $candidate) {
    if (!is_array($candidate)) continue;
    $query = trim((string)($candidate['query'] ?? ''));
    $normalized = trim((string)($candidate['normalized_query'] ?? ''));
    if ($query === '' || $normalized === '') continue;

    $match = find_existing_tool($query, $registry['tools']);
    $tool = $match['tool'] ?? null;
    $isExisting = is_array($tool);

    $queue[] = [
        'rank' => $rank++,
        'cluster_id' => $normalized,
        'core_query' => $query,
        'decision' => $isExisting ? 'enhancement' : 'candidate',
        'match_type' => $match['match_type'] ?? 'none',
        'match_score' => $match['score'] ?? 0.0,
        'required_capabilities' => $match['required_capabilities'] ?? demand_profile($query)['required_capabilities'],
        'matched_capabilities' => $match['matched_capabilities'] ?? [],
        'intent' => $match['intent'] ?? demand_profile($query)['intent'],
        'priority_score' => (int)($candidate['score'] ?? 1),
        'demand_signal' => (int)($candidate['search_volume'] ?? 0),
        'country' => $candidate['country'] ?? 'unspecified',
        'language' => $candidate['language'] ?? null,
        'competition' => $candidate['competition'] ?? null,
        'competition_index' => $candidate['competition_index'] ?? null,
        'existing_tool_match' => $tool['id'] ?? null,
        'target_tool_slug' => $tool['slug'] ?? null,
        'recommended_slug' => $isExisting ? null : $normalized,
        'implementation' => $isExisting ? 'enhance-existing-tool' : 'requires-tool-spec',
        'enhancement_scope' => $isExisting ? [
            'type' => 'seo',
            'description' => 'Improve the existing tool page for the discovered search intent without changing its core functionality.',
            'requested_capabilities' => ['search-intent-aligned-title', 'meta-description', 'on-page-content', 'how-to-use-content'],
            'affected_components' => ['content', 'seo'],
            'preserve_existing_functionality' => true
        ] : null,
        'confidence' => $candidate['confidence'] ?? 'low',
        'source' => 'google-ads-keyword-planner',
        'status' => $isExisting ? 'enhancement-review' : 'candidate'
    ];
}

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'methodology' => [
        'purpose' => 'Route Google demand into existing-tool SEO/content enhancements or genuinely new-tool candidates.',
        'existing_tool_policy' => 'Exact or sufficiently strong capability matches target the existing tool. They must never enter new-tool generation.',
        'duplicate_policy' => 'A matching existing capability is an enhancement or review candidate; no duplicate tool is generated.',
        'automation_policy' => 'Enhancements and new candidates remain non-publishable until specification, security, functional, SEO, and approval gates pass.'
    ],
    'queue' => $queue
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($queue) . " generation-queue records.\n";
