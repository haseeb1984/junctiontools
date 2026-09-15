<?php
declare(strict_types=1);

$path = __DIR__ . '/../config/tool-opportunities.json';
if (!is_file($path)) {
    fwrite(STDERR, "Missing config/tool-opportunities.json\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data) || !isset($data['opportunities']) || !is_array($data['opportunities'])) {
    fwrite(STDERR, "Opportunity registry JSON is invalid.\n");
    exit(1);
}

$required = [
    'query', 'normalized_query', 'category', 'intent', 'demand_signal',
    'utility_intent', 'coverage_gap', 'feasibility', 'automation_safety',
    'competition', 'score', 'implementation', 'status', 'evidence'
];
$queries = [];
$normalized = [];

foreach ($data['opportunities'] as $index => $item) {
    if (!is_array($item)) {
        throw new RuntimeException("Opportunity #{$index} is not an object.");
    }
    foreach ($required as $field) {
        if (!array_key_exists($field, $item)) {
            throw new RuntimeException("Opportunity #{$index} is missing {$field}.");
        }
    }

    $query = trim((string) $item['query']);
    $slug = trim((string) $item['normalized_query']);
    if ($query === '' || $slug === '') {
        throw new RuntimeException("Opportunity #{$index} has an empty query or normalized query.");
    }
    if (isset($queries[$query]) || isset($normalized[$slug])) {
        throw new RuntimeException("Duplicate opportunity query/normalized query: {$query}");
    }
    $queries[$query] = true;
    $normalized[$slug] = true;

    foreach (['demand_signal', 'utility_intent', 'coverage_gap', 'feasibility', 'automation_safety', 'competition'] as $factor) {
        $value = $item[$factor];
        if (!is_int($value) || $value < 1 || $value > 5) {
            throw new RuntimeException("{$query}: {$factor} must be an integer from 1 to 5.");
        }
    }

    $raw = ($item['demand_signal'] * 6)
        + ($item['utility_intent'] * 4)
        + ($item['coverage_gap'] * 5)
        + ($item['feasibility'] * 4)
        + ($item['automation_safety'] * 3)
        - ($item['competition'] * 3);
    $expectedScore = (int) round($raw * 100 / 107);
    if ((int) $item['score'] !== $expectedScore) {
        throw new RuntimeException("{$query}: score {$item['score']} does not match expected {$expectedScore}.");
    }
    if ((int) $item['score'] < 0 || (int) $item['score'] > 100) {
        throw new RuntimeException("{$query}: score must be 0-100.");
    }

    if (!in_array($item['status'], ['candidate', 'research', 'blocked'], true)) {
        throw new RuntimeException("{$query}: invalid status.");
    }
    if (!in_array($item['implementation'], ['client_side', 'client_or_wasm', 'server_side'], true)) {
        throw new RuntimeException("{$query}: invalid implementation type.");
    }
    if (!is_array($item['evidence']) || $item['evidence'] === []) {
        throw new RuntimeException("{$query}: evidence must be a non-empty array.");
    }
}

if (count($data['opportunities']) < 10) {
    throw new RuntimeException('Opportunity registry must contain at least 10 candidates.');
}

printf(
    "Opportunity registry validation passed: %d unique candidates with valid factors, scores, implementation types, and evidence.\n",
    count($data['opportunities'])
);
