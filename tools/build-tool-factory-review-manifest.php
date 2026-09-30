<?php
declare(strict_types=1);

/**
 * Build a deterministic human-review manifest for the current Tool Factory run.
 *
 * The manifest is the hand-off between automated discovery/security validation
 * and the human approval files committed to the repository. It intentionally
 * contains no executable publication authorization.
 */
if ($argc < 4) {
    fwrite(STDERR, "Usage: php tools/build-tool-factory-review-manifest.php <specs.json> <security-decisions.json> <output.json> [policy.json]\n");
    exit(2);
}

$specFile = $argv[1];
$decisionFile = $argv[2];
$outputFile = $argv[3];
$policyFile = $argv[4] ?? dirname(__DIR__) . '/config/build-security-gate.json';

foreach ([$specFile, $decisionFile, $policyFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Input file missing: {$file}\n");
        exit(1);
    }
}

$specs = json_decode((string) file_get_contents($specFile), true);
$decisions = json_decode((string) file_get_contents($decisionFile), true);
$policy = json_decode((string) file_get_contents($policyFile), true);

if (!is_array($specs) || !is_array($specs['specifications'] ?? null) ||
    !is_array($decisions) || !is_array($decisions['decisions'] ?? null) ||
    !is_array($policy)) {
    fwrite(STDERR, "Invalid review-manifest input schema.\n");
    exit(1);
}

function review_manifest_canonical(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) {
        return array_map('review_manifest_canonical', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = review_manifest_canonical($item);
    }
    return $value;
}

function review_manifest_hash(array $value): string {
    return hash('sha256', json_encode(review_manifest_canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}

$decisionBySlug = [];
foreach ($decisions['decisions'] as $decision) {
    if (!is_array($decision)) continue;
    $slug = strtolower(trim((string) ($decision['source']['specification_slug'] ?? '')));
    if ($slug !== '') $decisionBySlug[$slug] = $decision;
}

$candidates = [];
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) continue;
    $tool = $spec['tool'] ?? [];
    if (!is_array($tool)) continue;
    $slug = strtolower(trim((string) ($tool['slug'] ?? '')));
    if ($slug === '') continue;

    $type = (($spec['spec_type'] ?? '') === 'enhancement') ? 'enhancement' : 'new_tool';
    $specHash = review_manifest_hash($spec);
    $policyHash = hash('sha256', json_encode(review_manifest_canonical($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    $candidateId = hash('sha256', $type . '|' . $slug . '|' . $specHash);

    $source = $spec['source'] ?? [];
    if (!is_array($source)) $source = [];

    $decision = $decisionBySlug[$slug] ?? null;
    $candidates[] = [
        'candidate_id' => $candidateId,
        'type' => $type,
        'slug' => $slug,
        'name' => (string) ($tool['name'] ?? ''),
        'cluster_id' => (string) ($source['cluster_id'] ?? ''),
        'query' => (string) ($source['query'] ?? $spec['seo']['target_query'] ?? ''),
        'spec_sha256' => $specHash,
        'policy_sha256' => $policyHash,
        'security_decision' => is_array($decision) ? (string) ($decision['decision'] ?? 'missing') : 'missing',
        'security_evaluator_id' => is_array($decision) ? (string) ($decision['evaluator_id'] ?? '') : '',
        'generation_approval_required' => $type === 'new_tool',
        'enhancement_approval_required' => $type === 'enhancement',
        'publication_approval_required' => true,
        'review_status' => 'pending-human-review'
    ];
}

file_put_contents($outputFile, json_encode([
    'schema_version' => '1.1.0',
    'generated_at' => gmdate('c'),
    'policy_sha256' => hash('sha256', json_encode(review_manifest_canonical($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
    'automatic_production_publish' => false,
    'publication_requires_separate_review' => true,
    'candidates' => $candidates
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);

echo "Built human-review manifest with " . count($candidates) . " candidate(s).\n";
