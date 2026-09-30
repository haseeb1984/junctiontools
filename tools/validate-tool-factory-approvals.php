<?php
declare(strict_types=1);

/**
 * Validate repository approval records against the exact current Tool Factory
 * specifications. New approvals are hash-bound so an approval cannot silently
 * authorize a changed specification.
 *
 * Legacy explicit approvals are accepted only when approval_version is absent;
 * they are reported as legacy and never authorize publication.
 */
if ($argc < 5) {
    fwrite(STDERR, "Usage: php tools/validate-tool-factory-approvals.php <specs.json> <security-decisions.json> <generation-approvals.json> <enhancement-approvals.json> [policy.json]\n");
    exit(2);
}

[$specFile, $decisionFile, $generationFile, $enhancementFile] = array_slice($argv, 1, 4);
$policyFile = $argv[5] ?? dirname(__DIR__) . '/config/build-security-gate.json';

foreach ([$specFile, $decisionFile, $generationFile, $enhancementFile, $policyFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "Approval validation input missing: {$file}\n");
        exit(1);
    }
}

$specs = json_decode((string) file_get_contents($specFile), true);
$decisions = json_decode((string) file_get_contents($decisionFile), true);
$generation = json_decode((string) file_get_contents($generationFile), true);
$enhancement = json_decode((string) file_get_contents($enhancementFile), true);
$policy = json_decode((string) file_get_contents($policyFile), true);

if (!is_array($specs) || !is_array($specs['specifications'] ?? null) ||
    !is_array($decisions) || !is_array($decisions['decisions'] ?? null) ||
    !is_array($generation) || !is_array($generation['approvals'] ?? null) ||
    !is_array($enhancement) || !is_array($enhancement['approvals'] ?? null) ||
    !is_array($policy)) {
    fwrite(STDERR, "Invalid approval validation schema.\n");
    exit(1);
}
if (($generation['policy']['default'] ?? 'deny') !== 'deny' ||
    ($enhancement['policy']['default'] ?? 'deny') !== 'deny') {
    fwrite(STDERR, "FAIL: approval policies must default to deny.\n");
    exit(1);
}
if (($generation['policy']['publication_requires_separate_review'] ?? true) !== true ||
    ($enhancement['policy']['publication_requires_separate_review'] ?? true) !== true) {
    fwrite(STDERR, "FAIL: implementation approvals must require separate publication review.\n");
    exit(1);
}

function approval_canonical(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('approval_canonical', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = approval_canonical($item);
    return $value;
}
function approval_spec_hash(array $spec): string {
    return hash('sha256', json_encode(approval_canonical($spec), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}
function approval_candidate_id(string $type, string $slug, string $specHash): string {
    return hash('sha256', $type . '|' . $slug . '|' . $specHash);
}
function approval_policy_hash(array $policy): string {
    return hash('sha256', json_encode(approval_canonical($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
}
function approval_time_valid(string $value): bool {
    return $value !== '' && strtotime($value) !== false;
}

$specBySlug = [];
foreach ($specs['specifications'] as $spec) {
    if (!is_array($spec)) continue;
    $slug = strtolower(trim((string) ($spec['tool']['slug'] ?? '')));
    if ($slug !== '') $specBySlug[$slug] = $spec;
}

$securityBySlug = [];
foreach ($decisions['decisions'] as $decision) {
    if (!is_array($decision)) continue;
    $slug = strtolower(trim((string) ($decision['source']['specification_slug'] ?? '')));
    if ($slug !== '') $securityBySlug[$slug] = $decision;
}

$errors = [];
$legacy = [];
$checked = 0;

$validate = static function(array $item, string $expectedType) use (&$errors, &$legacy, &$checked, $specBySlug, $securityBySlug, $policy): void {
    $slug = strtolower(trim((string) ($item['slug'] ?? $item['target_tool_slug'] ?? '')));
    if ($slug === '') {
        $errors[] = $expectedType . ': approval is missing slug/target_tool_slug';
        return;
    }
    if (!isset($specBySlug[$slug])) {
        // Approval registries may contain standing approvals for candidates that
        // are not present in today's demand snapshot. They are inert until the
        // exact candidate reappears; never treat absence from today's snapshot
        // as a stale approval by itself.
        return;
    }

    $spec = $specBySlug[$slug];
    $actualType = (($spec['spec_type'] ?? '') === 'enhancement') ? 'enhancement' : 'new_tool';
    if ($actualType !== $expectedType) {
        $errors[] = "{$expectedType}: approval type mismatch for {$slug}; current spec is {$actualType}";
        return;
    }

    $checked++;
    $specHash = approval_spec_hash($spec);
    $policyHash = approval_policy_hash($policy);
    $candidateId = approval_candidate_id($actualType, $slug, $specHash);
    $approved = ($item['approved'] ?? false) === true ||
        ($item['decision'] ?? '') === 'approved-for-implementation-and-validation';

    if (!$approved) return;

    $hasBinding = isset($item['approval_version'], $item['candidate_id'], $item['spec_sha256'], $item['policy_sha256']);
    if (!$hasBinding) {
        $legacy[] = "{$expectedType}: {$slug}";
        return;
    }

    if ((string) $item['approval_version'] !== '1.1') $errors[] = "{$expectedType}: unsupported approval_version for {$slug}";
    if ((string) $item['candidate_id'] !== $candidateId) $errors[] = "{$expectedType}: candidate_id mismatch for {$slug}";
    if (!hash_equals($specHash, (string) ($item['spec_sha256'] ?? ''))) $errors[] = "{$expectedType}: spec_sha256 mismatch/stale approval for {$slug}";
    if (!hash_equals($policyHash, (string) ($item['policy_sha256'] ?? ''))) $errors[] = "{$expectedType}: policy_sha256 mismatch for {$slug}";
    if (trim((string) ($item['reviewer'] ?? '')) === '') $errors[] = "{$expectedType}: reviewer is required for {$slug}";
    if (!approval_time_valid((string) ($item['approved_at'] ?? ''))) $errors[] = "{$expectedType}: approved_at is required for {$slug}";
    if (isset($item['expires_at']) && trim((string) $item['expires_at']) !== '') {
        $expiry = strtotime((string) $item['expires_at']);
        if ($expiry === false || $expiry <= time()) $errors[] = "{$expectedType}: approval expired for {$slug}";
    }

    $security = $securityBySlug[$slug] ?? null;
    if (!is_array($security) || ($security['decision'] ?? '') !== 'allow' || ($security['safe_to_build'] ?? false) !== true) {
        $errors[] = "{$expectedType}: security approval is not currently allow/safe_to_build for {$slug}";
    }
    $evidence = is_array($security['evidence'] ?? null) ? $security['evidence'] : [];
    if (!hash_equals($specHash, (string) ($evidence['spec_sha256'] ?? ''))) {
        $errors[] = "{$expectedType}: security evidence spec hash does not match {$slug}";
    }
};

foreach ($generation['approvals'] as $item) {
    if (is_array($item)) $validate($item, 'new_tool');
}
foreach ($enhancement['approvals'] as $item) {
    if (is_array($item)) $validate($item, 'enhancement');
}

if ($errors) {
    foreach ($errors as $error) fwrite(STDERR, "FAIL: {$error}\n");
    exit(1);
}

echo "PASS: approval layer validated {$checked} current candidate approval(s).\n";
if ($legacy) {
    echo "WARNING: legacy approvals without hash binding: " . implode(', ', $legacy) . "\n";
}
echo "Production publication remains separately gated and disabled by default.\n";
