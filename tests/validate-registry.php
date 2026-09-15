<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$registryPath = $root . '/config/tools.json';

if (!is_file($registryPath)) { fwrite(STDERR, "Registry missing: {$registryPath}\n"); exit(1); }
$raw = file_get_contents($registryPath);
if ($raw === false) { fwrite(STDERR, "Unable to read registry.\n"); exit(1); }
$data = json_decode($raw, true);
if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) { fwrite(STDERR, 'Invalid registry JSON: ' . json_last_error_msg() . "\n"); exit(1); }
$tools = $data['tools'] ?? null;
if (!is_array($tools)) { fwrite(STDERR, "Registry tools array missing.\n"); exit(1); }
if (count($tools) < 35) { fwrite(STDERR, 'Expected at least 35 tools, found ' . count($tools) . ".\n"); exit(1); }

$ids = [];
$slugs = [];
$errors = [];
$allowedTypes = ['client_side', 'external_api', 'scanner'];
$allowedStatuses = $data['status_values'] ?? ['active', 'draft', 'deprecated'];
$allowedEligibility = $data['generation_eligibility_values'] ?? ['eligible', 'review', 'blocked'];

foreach ($tools as $index => $tool) {
    $label = 'tool #' . ($index + 1);
    foreach (['id', 'name', 'slug', 'category', 'type', 'input', 'output', 'frontend', 'status', 'test_coverage', 'generation_eligibility'] as $field) {
        if (!array_key_exists($field, $tool) || $tool[$field] === '') $errors[] = "{$label}: missing {$field}";
    }
    if (isset($tool['id'])) { if (isset($ids[$tool['id']])) $errors[] = "duplicate id: {$tool['id']}"; $ids[$tool['id']] = true; }
    if (isset($tool['slug'])) { if (isset($slugs[$tool['slug']])) $errors[] = "duplicate slug: {$tool['slug']}"; $slugs[$tool['slug']] = true; }
    if (isset($tool['type']) && !in_array($tool['type'], $allowedTypes, true)) $errors[] = "{$label}: unsupported type {$tool['type']}";
    if (isset($tool['status']) && !in_array($tool['status'], $allowedStatuses, true)) $errors[] = "{$label}: unsupported status {$tool['status']}";
    if (isset($tool['generation_eligibility']) && !in_array($tool['generation_eligibility'], $allowedEligibility, true)) $errors[] = "{$label}: unsupported generation eligibility {$tool['generation_eligibility']}";
    $frontend = $tool['frontend'] ?? '';
    if ($frontend !== '' && !is_file($root . '/' . $frontend)) $errors[] = "{$label}: frontend file not found: {$frontend}";
    if (($tool['type'] ?? '') === 'scanner') {
        if (($tool['backend'] ?? null) !== 'scanner.php') $errors[] = "{$label}: scanner backend must be scanner.php";
        if (empty($tool['scanner_type'])) $errors[] = "{$label}: scanner_type required for scanner tools";
    }
    if (($tool['type'] ?? '') !== 'scanner' && !empty($tool['scanner_type'])) $errors[] = "{$label}: scanner_type is only valid for scanner tools";
}

if ($errors !== []) {
    fwrite(STDERR, "Registry validation failed:\n");
    foreach ($errors as $error) fwrite(STDERR, "- {$error}\n");
    exit(1);
}

echo 'Tool registry validation passed: ' . count($tools) . ' tools, unique IDs/slugs, frontend files present, and type contracts valid.' . PHP_EOL;
