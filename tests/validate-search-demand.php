<?php
declare(strict_types=1);

$path = __DIR__ . '/../config/search-demand.json';
if (!is_file($path)) {
    fwrite(STDERR, "Search-demand registry missing: {$path}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data)) {
    fwrite(STDERR, "Search-demand registry is not valid JSON.\n");
    exit(1);
}

if (($data['schema_version'] ?? null) !== '1.0.0') {
    fwrite(STDERR, "Unsupported search-demand schema version.\n");
    exit(1);
}

if (!is_array($data['methodology'] ?? null)) {
    fwrite(STDERR, "Search-demand methodology is required.\n");
    exit(1);
}

$clusters = $data['clusters'] ?? null;
if (!is_array($clusters) || $clusters === []) {
    fwrite(STDERR, "Search-demand clusters must be a non-empty array.\n");
    exit(1);
}

$allowedSources = ['ahrefstop', 'seodata', 'kdroi'];
$clusterIds = [];
$signalKeys = [];
$signalCount = 0;
$totalReportedVolume = 0;

foreach ($clusters as $index => $cluster) {
    if (!is_array($cluster)) {
        fwrite(STDERR, "Cluster #{$index} must be an object.\n");
        exit(1);
    }

    $id = $cluster['id'] ?? null;
    $coreQuery = $cluster['core_query'] ?? null;
    $intent = $cluster['intent'] ?? null;
    $signals = $cluster['signals'] ?? null;

    if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id)) {
        fwrite(STDERR, "Invalid cluster id at index {$index}.\n");
        exit(1);
    }
    if (isset($clusterIds[$id])) {
        fwrite(STDERR, "Duplicate cluster id: {$id}\n");
        exit(1);
    }
    $clusterIds[$id] = true;

    if (!is_string($coreQuery) || trim($coreQuery) === '') {
        fwrite(STDERR, "Cluster {$id} requires a non-empty core_query.\n");
        exit(1);
    }
    if (!is_string($intent) || trim($intent) === '') {
        fwrite(STDERR, "Cluster {$id} requires a non-empty intent.\n");
        exit(1);
    }
    if (!is_array($signals) || $signals === []) {
        fwrite(STDERR, "Cluster {$id} requires at least one signal.\n");
        exit(1);
    }

    foreach ($signals as $signalIndex => $signal) {
        if (!is_array($signal)) {
            fwrite(STDERR, "Signal {$id}#{$signalIndex} must be an object.\n");
            exit(1);
        }

        foreach (['query', 'country', 'source', 'source_date', 'evidence_url'] as $field) {
            if (!isset($signal[$field]) || !is_string($signal[$field]) || trim($signal[$field]) === '') {
                fwrite(STDERR, "Signal {$id}#{$signalIndex} requires {$field}.\n");
                exit(1);
            }
        }

        if (!in_array($signal['source'], $allowedSources, true)) {
            fwrite(STDERR, "Unsupported evidence source {$signal['source']} in {$id}#{$signalIndex}.\n");
            exit(1);
        }
        if (!filter_var($signal['evidence_url'], FILTER_VALIDATE_URL) || !str_starts_with(strtolower($signal['evidence_url']), 'https://')) {
            fwrite(STDERR, "Evidence URL must be HTTPS in {$id}#{$signalIndex}.\n");
            exit(1);
        }
        if (!preg_match('/^\d{4}-(?:\d{2}|\d{2}-\d{2})$/', $signal['source_date'])) {
            fwrite(STDERR, "Invalid source_date in {$id}#{$signalIndex}.\n");
            exit(1);
        }

        $volume = $signal['search_volume'] ?? null;
        if ($volume !== null && (!is_int($volume) || $volume <= 0)) {
            fwrite(STDERR, "search_volume must be null or a positive integer in {$id}#{$signalIndex}.\n");
            exit(1);
        }

        $key = strtolower(trim($signal['query'])) . '|' . strtolower(trim($signal['country'])) . '|' . strtolower(trim($signal['source']));
        if (isset($signalKeys[$key])) {
            fwrite(STDERR, "Duplicate signal key: {$key}\n");
            exit(1);
        }
        $signalKeys[$key] = true;

        if ($volume !== null) {
            $totalReportedVolume += $volume;
        }
        $signalCount++;
    }
}

echo 'Search-demand validation passed: ' . count($clusters) . ' clusters, ' . $signalCount . ' evidence signals, ' . number_format($totalReportedVolume) . ' total reported search volume.\n';
