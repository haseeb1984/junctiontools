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
function requested_options(string $query, string $intent): array {
    if ($intent !== 'image-format-conversion') return [];
    if (preg_match('~\b(heic|heif|webp|avif|png|jpg|jpeg|gif)\s*(?:to|->)\s*(heic|heif|webp|avif|png|jpg|jpeg|gif)\b~i', $query, $m)) {
        return ['input_format' => strtolower($m[1]), 'output_format' => strtolower($m[2])];
    }
    return [];
}

function demand_profile(string $query): array {
    $normalized = normalize_demand_term($query);
    $profile = ['required_capabilities' => [], 'intent' => 'unknown'];

    $rules = [
        ['~(?:image|photo|picture).*(?:convert|conversion|converter)|(?:convert|conversion|converter).*(?:image|photo|picture)|(?:heic|heif|webp|avif|png|jpg|jpeg|gif)\s*(?:to|->)\s*(?:heic|heif|webp|avif|png|jpg|jpeg|gif)~i',
            ['image-format-conversion'], 'image-format-conversion'],

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
        ['~\bdiscount\b.*?(?:calculator|calculate)~i',
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

function load_capability_registry(string $root): array {
    $path = $root . '/config/tool-capabilities.json';
    if (!is_file($path)) {
        throw new RuntimeException('Capability registry is missing.');
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !is_array($data['tools'] ?? null)) {
        throw new RuntimeException('Capability registry is invalid.');
    }
    $byId = [];
    foreach ($data['tools'] as $entry) {
        if (!is_array($entry) || trim((string)($entry['tool_id'] ?? '')) === '') continue;
        $byId[(string)$entry['tool_id']] = $entry;
    }
    return $byId;
}

function tool_capabilities(array $tool, array $capabilityRegistry): array {
    $id = (string)($tool['id'] ?? '');
    $entry = $capabilityRegistry[$id] ?? null;
    return is_array($entry) ? $entry : [];
}

function capability_match(array $required, array $toolCapability): array {
    $available = array_values(array_unique(array_map('strval', $toolCapability['capabilities'] ?? [])));
    $missing = array_values(array_diff($required, $available));
    return [$missing, $available];
}


function tool_intent(string $query): array {
    $q = strtolower(trim($query));
    $toolPatterns = [
        '~(?:calculator|converter|convert|generator|generate|formatter|format|checker|check|tester|test|counter|compress|compressor|resizer|resize|optimizer|optimizer|validator|validate|encoder|decoder|encode|decode|parser|parse|creator|maker|builder|analyzer|analyser|audit|scanner|inspector)~i',
        '~(?:online tool|free tool|online calculator|online converter)~i'
    ];
    foreach ($toolPatterns as $pattern) {
        if (preg_match($pattern, $q)) return ['is_tool_intent'=>true,'reason'=>'explicit-utility-language'];
    }
    return ['is_tool_intent'=>false,'reason'=>'no-utility-intent-signal'];
}

function find_existing_tool(string $query, array $tools, array $capabilityRegistry): ?array {
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
        $toolCapability = tool_capabilities($tool, $capabilityRegistry);
        if (!$toolCapability) continue;

        [$missing, $available] = capability_match($required, $toolCapability);
        if ($missing) continue;

        $best = [
            'tool' => $tool,
            'match_type' => 'capability',
            'score' => 1.0,
            'intent' => $demand['intent'],
            'requested_options' => requested_options($query, $demand['intent']),
            'required_capabilities' => $required,
            'matched_capabilities' => $required,
            'supported_options' => $toolCapability['supported_options'] ?? [],
            'missing_options' => array_values(array_diff(requested_options($query, $demand['intent']), $toolCapability['supported_options'] ?? [])),
        ];
        break;
    }

    return $best;
}

$capabilityRegistry = load_capability_registry(dirname(__DIR__));
$queue = [];
$rank = 1;
foreach ($opportunities['opportunities'] as $candidate) {
    if (!is_array($candidate)) continue;
    $query = trim((string)($candidate['query'] ?? ''));
    $normalized = trim((string)($candidate['normalized_query'] ?? ''));
    if ($query === '' || $normalized === '') continue;

    $intent = tool_intent($query);
    if (($intent['is_tool_intent'] ?? false) !== true) {
        echo "Rejected non-tool demand: {$query} ({$intent['reason']})\n";
        continue;
    }
    $match = find_existing_tool($query, $registry['tools'], $capabilityRegistry);
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
        'supported_options' => $match['supported_options'] ?? [],
        'requested_options' => $match['requested_options'] ?? requested_options($query, demand_profile($query)['intent']),
        'missing_options' => $match['missing_options'] ?? [],
        'intent' => $match['intent'] ?? demand_profile($query)['intent'],
        'priority_score' => (int)($candidate['score'] ?? 1),
        'demand_signal' => (int)($candidate['search_volume'] ?? $candidate['trend_traffic_lower_bound'] ?? 0),
        'country' => $candidate['country'] ?? 'unspecified',
        'language' => $candidate['language'] ?? null,
        'competition' => $candidate['competition'] ?? null,
        'competition_index' => $candidate['competition_index'] ?? null,
        'trend_traffic_label' => $candidate['trend_traffic_label'] ?? null,
        'trend_traffic_lower_bound' => $candidate['trend_traffic_lower_bound'] ?? null,
        'trend_signal' => $candidate['trend_signal'] ?? null,
        'existing_tool_match' => $tool['id'] ?? null,
        'target_tool_slug' => $tool['slug'] ?? null,
        'recommended_slug' => $isExisting ? null : $normalized,
        'implementation' => $isExisting ? 'enhance-existing-tool' : 'requires-tool-spec',
        'enhancement_scope' => $isExisting ? [
            'type' => 'seo',
            'description' => 'Improve the existing tool page for the discovered search intent without changing its core functionality.',
            'requested_capabilities' => ['search-intent-aligned-title', 'meta-description', 'on-page-content', 'how-to-use-content'],
            'requested_options' => $match['requested_options'] ?? [],
            'missing_options' => $match['missing_options'] ?? [],
            'affected_components' => ['content', 'seo'],
            'preserve_existing_functionality' => true
        ] : null,
        'confidence' => $candidate['confidence'] ?? 'low',
        'source' => (string)($candidate['source'] ?? 'unknown'),
        'status' => $isExisting ? 'enhancement-review' : 'candidate'
    ];
}

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'methodology' => [
        'purpose' => 'Route only explicit tool-intent demand into existing-tool enhancements or genuinely new-tool candidates.',
        'existing_tool_policy' => 'Capability registry is authoritative for overlap detection. Exact capability matches target the existing tool; missing requested options/formats are enhancement scope; only absent capabilities may become new-tool candidates.',
        'duplicate_policy' => 'A matching existing capability is an enhancement or review candidate; no duplicate tool is generated.',
        'tool_intent_policy' => 'Non-tool searches such as news, sports, people, politics, comparisons, and general information are rejected unless explicit utility intent is present.',
        'automation_policy' => 'Qualified candidates may be generated after Security Gate; human approval is reserved for final publication.'
    ],
    'queue' => $queue
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($queue) . " generation-queue records.\n";
