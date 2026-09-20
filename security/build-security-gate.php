<?php
declare(strict_types=1);

/**
 * Pre-build Security Gate. This is an enforcement boundary, not a reporting-only check.
 */
const BUILD_SECURITY_GATE_EVALUATOR = 'build-security-gate-v1';

function bsg_canonicalize(mixed $value): mixed {
    if (is_array($value)) {
        if (array_is_list($value)) {
            return array_map('bsg_canonicalize', $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = bsg_canonicalize($item);
        }
    }
    return $value;
}

function bsg_canonical_json(mixed $value): string {
    $json = json_encode(bsg_canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to canonicalize security-gate input.');
    }
    return $json;
}

function bsg_sha256(mixed $value): string {
    return hash('sha256', bsg_canonical_json($value));
}

function bsg_reject(string $code, string $message): array {
    return ['allowed' => false, 'error_code' => $code, 'message' => $message];
}

function bsg_decision_for(array $decisions, string $slug): ?array {
    $found = null;
    foreach ($decisions as $decision) {
        if (!is_array($decision)) {
            continue;
        }
        $decisionSlug = strtolower(trim((string)($decision['source']['specification_slug'] ?? '')));
        if ($decisionSlug !== $slug) {
            continue;
        }
        if ($found !== null) {
            throw new RuntimeException("Duplicate security-gate decision for {$slug}.");
        }
        $found = $decision;
    }
    return $found;
}

function bsg_validate_timestamp(string $value): bool {
    if ($value === '') return false;
    try {
        $time = new DateTimeImmutable($value);
    } catch (Throwable) {
        return false;
    }
    return $time <= new DateTimeImmutable('now');
}

function bsg_allowed_templates(array $policy): array {
    $templates = $policy['approved_templates'] ?? [];
    return is_array($templates) ? array_values(array_filter($templates, 'is_string')) : [];
}

function bsg_evaluate(array $spec, array $decision, array $policy): array {
    $tool = $spec['tool'] ?? null;
    if (!is_array($tool)) return bsg_reject('security_gate_rejected', 'Tool specification is invalid.');
    $slug = strtolower(trim((string)($tool['slug'] ?? '')));
    if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return bsg_reject('security_gate_rejected', 'Unsafe or invalid tool slug.');
    }

    if (($decision['decision'] ?? null) !== 'allow') {
        return bsg_reject(
            ($decision['decision'] ?? null) === 'reject' ? 'security_gate_rejected' : 'missing_security_gate_decision',
            'Pre-build Security Gate did not authorize generation.'
        );
    }

    $source = $decision['source'] ?? [];
    if (!is_array($source) || strtolower(trim((string)($source['specification_slug'] ?? ''))) !== $slug) {
        return bsg_reject('security_gate_rejected', 'Security-gate decision is not bound to this specification.');
    }

    $allowedEvaluators = $policy['allowed_evaluators'] ?? [];
    if (!is_array($allowedEvaluators) || !in_array((string)($decision['evaluator_id'] ?? ''), $allowedEvaluators, true)) {
        return bsg_reject('security_gate_unknown_evaluator', 'Security-gate evaluator is not trusted.');
    }

    $policyVersion = (string)($policy['policy_version'] ?? '');
    if ($policyVersion === '' || (string)($decision['policy_version'] ?? '') !== $policyVersion) {
        return bsg_reject('security_gate_policy_version_mismatch', 'Security-gate policy version mismatch.');
    }

    if (!bsg_validate_timestamp((string)($decision['evaluated_at'] ?? ''))) {
        return bsg_reject('security_gate_invalid_evaluated_at', 'Security-gate evaluation timestamp is invalid or in the future.');
    }

    $evidence = $decision['evidence'] ?? [];
    if (!is_array($evidence)) {
        return bsg_reject('security_gate_rejected', 'Security-gate evidence is missing.');
    }
    $specHash = (string)($evidence['spec_sha256'] ?? '');
    $policyHash = (string)($evidence['policy_sha256'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $specHash) || !preg_match('/^[a-f0-9]{64}$/', $policyHash)) {
        return bsg_reject('security_gate_rejected', 'Security-gate evidence hashes are invalid.');
    }
    if (!hash_equals($specHash, bsg_sha256($spec))) {
        return bsg_reject('security_gate_spec_hash_mismatch', 'Security-gate approval is stale for the current specification.');
    }
    if (!hash_equals($policyHash, bsg_sha256($policy))) {
        return bsg_reject('security_gate_policy_hash_mismatch', 'Security-gate approval is stale for the current policy.');
    }

    $conditions = $decision['conditions'] ?? [];
    $required = $policy['required_conditions'] ?? [];
    if (!is_array($conditions) || !is_array($required)) {
        return bsg_reject('security_gate_rejected', 'Security-gate conditions are missing.');
    }
    foreach ($required as $name => $expected) {
        if (($conditions[$name] ?? null) !== $expected) {
            return bsg_reject('security_gate_rejected', "Required security condition failed: {$name}.");
        }
    }

    $blocked = $decision['blocked_conditions'] ?? [];
    if (!is_array($blocked) || count($blocked) !== 0) {
        return bsg_reject('security_gate_rejected', 'Security-gate decision contains blocked conditions.');
    }

    if (($decision['safe_to_build'] ?? false) !== true) {
        return bsg_reject('security_gate_rejected', 'Security-gate decision does not mark the specification safe to build.');
    }

    $template = (string)($tool['implementation_template'] ?? '');
    if (!in_array($template, bsg_allowed_templates($policy), true)) {
        return bsg_reject('security_gate_rejected', 'Implementation template is not approved by the build policy.');
    }

    $privacy = $spec['privacy_security'] ?? [];
    if (!is_array($privacy) || ($privacy['processing'] ?? null) !== 'browser_only' ||
        ($privacy['network_requests'] ?? null) !== false ||
        ($privacy['external_dependencies'] ?? null) !== false ||
        !is_array($privacy['security_requirements'] ?? null) ||
        count($privacy['security_requirements']) === 0) {
        return bsg_reject('security_gate_rejected', 'Privacy/security contract does not satisfy the build policy.');
    }

    $decisionType = (string)($decision['type'] ?? 'new_tool');
    if ($decisionType === 'new_tool') {
        if (($decision['approval_requirements']['generation_approval'] ?? false) !== true ||
            ($decision['approval_requirements']['enhancement_approval'] ?? true) !== false) {
            return bsg_reject('security_gate_rejected', 'New-tool approval requirements are invalid.');
        }
    } elseif ($decisionType === 'enhancement') {
        $target = strtolower(trim((string)($decision['enhancement']['target_tool_slug'] ?? '')));
        $scope = $decision['enhancement']['scope'] ?? [];
        if ($target === '' || !is_array($scope) ||
            ($scope['preserve_existing_functionality'] ?? false) !== true ||
            ($decision['approval_requirements']['enhancement_approval'] ?? false) !== true ||
            ($decision['approval_requirements']['generation_approval'] ?? true) !== false) {
            return bsg_reject('security_gate_rejected', 'Enhancement scope or approval requirements are invalid.');
        }
    } else {
        return bsg_reject('security_gate_rejected', 'Unknown security-gate decision type.');
    }

    return ['allowed' => true, 'error_code' => null, 'message' => 'Pre-build Security Gate passed.'];
}

function bsg_load_and_evaluate(array $spec, string $policyFile, string $decisionFile): array {
    if (!is_file($policyFile) || !is_file($decisionFile)) {
        return bsg_reject('missing_security_gate_decision', 'Security Gate policy or decision artifact is unavailable.');
    }
    $policy = json_decode((string)file_get_contents($policyFile), true);
    $artifact = json_decode((string)file_get_contents($decisionFile), true);
    if (!is_array($policy) || !is_array($artifact) || !is_array($artifact['decisions'] ?? null)) {
        return bsg_reject('security_gate_rejected', 'Security Gate artifacts are invalid.');
    }
    $slug = strtolower(trim((string)($spec['tool']['slug'] ?? '')));
    $decision = bsg_decision_for($artifact['decisions'], $slug);
    if ($decision === null) {
        return bsg_reject('missing_security_gate_decision', "No Security Gate decision exists for {$slug}.");
    }
    return bsg_evaluate($spec, $decision, $policy);
}
