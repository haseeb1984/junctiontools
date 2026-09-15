<?php
declare(strict_types=1);

$path = __DIR__ . '/../config/tool-generation-queue.json';
if (!is_file($path)) {
    fwrite(STDERR, "Missing tool-generation-queue.json\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);
if (!is_array($data)) {
    fwrite(STDERR, "Generation queue JSON is invalid.\n");
    exit(1);
}

if (($data['schema_version'] ?? null) !== '1.0.0') {
    fwrite(STDERR, "Unsupported generation queue schema version.\n");
    exit(1);
}

$queue = $data['queue'] ?? null;
if (!is_array($queue) || $queue === []) {
    fwrite(STDERR, "Generation queue must contain at least one entry.\n");
    exit(1);
}

$allowedDecisions = ['build', 'research', 'hold', 'blocked'];
$allowedRanks = range(1, count($queue));
$seenClusters = [];
$seenRanks = [];
$previousScore = null;

foreach ($queue as $index => $entry) {
    if (!is_array($entry)) {
        fwrite(STDERR, "Queue entry {$index} must be an object.\n");
        exit(1);
    }

    foreach (['rank', 'cluster_id', 'core_query', 'decision', 'priority_score', 'demand_signal', 'signal_count', 'implementation', 'recommended_slug', 'rationale'] as $field) {
        if (!array_key_exists($field, $entry)) {
            fwrite(STDERR, "Queue entry {$index} missing {$field}.\n");
            exit(1);
        }
    }

    $rank = $entry['rank'];
    if (!is_int($rank) || !in_array($rank, $allowedRanks, true) || isset($seenRanks[$rank])) {
        fwrite(STDERR, "Invalid or duplicate rank at queue entry {$index}.\n");
        exit(1);
    }
    $seenRanks[$rank] = true;

    $cluster = $entry['cluster_id'];
    if (!is_string($cluster) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $cluster) || isset($seenClusters[$cluster])) {
        fwrite(STDERR, "Invalid or duplicate cluster_id at queue entry {$index}.\n");
        exit(1);
    }
    $seenClusters[$cluster] = true;

    if (!is_string($entry['core_query']) || trim($entry['core_query']) === '') {
        fwrite(STDERR, "Empty core_query at queue entry {$index}.\n");
        exit(1);
    }

    if (!in_array($entry['decision'], $allowedDecisions, true)) {
        fwrite(STDERR, "Invalid decision at queue entry {$index}.\n");
        exit(1);
    }

    if (!is_int($entry['priority_score']) || $entry['priority_score'] < 0 || $entry['priority_score'] > 100) {
        fwrite(STDERR, "Priority score must be an integer from 0 to 100 at queue entry {$index}.\n");
        exit(1);
    }

    if ($previousScore !== null && $entry['priority_score'] > $previousScore) {
        fwrite(STDERR, "Queue is not sorted by descending priority_score.\n");
        exit(1);
    }
    $previousScore = $entry['priority_score'];

    foreach (['demand_signal', 'signal_count'] as $numericField) {
        if (!is_int($entry[$numericField]) || $entry[$numericField] < 0) {
            fwrite(STDERR, "{$numericField} must be a non-negative integer at queue entry {$index}.\n");
            exit(1);
        }
    }

    if (!is_string($entry['implementation']) || trim($entry['implementation']) === '') {
        fwrite(STDERR, "Empty implementation at queue entry {$index}.\n");
        exit(1);
    }

    if (!is_string($entry['recommended_slug']) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry['recommended_slug'])) {
        fwrite(STDERR, "Invalid recommended_slug at queue entry {$index}.\n");
        exit(1);
    }

    if (!is_array($entry['seo_page_cluster'] ?? null) || $entry['seo_page_cluster'] === []) {
        fwrite(STDERR, "seo_page_cluster must be a non-empty array at queue entry {$index}.\n");
        exit(1);
    }

    if (!is_string($entry['rationale']) || trim($entry['rationale']) === '') {
        fwrite(STDERR, "Empty rationale at queue entry {$index}.\n");
        exit(1);
    }
}

if ($seenRanks !== array_fill_keys($allowedRanks, true)) {
    fwrite(STDERR, "Queue ranks must be contiguous from 1 to the queue length.\n");
    exit(1);
}

echo sprintf(
    "Generation queue validation passed: %d ranked opportunities, descending priority, valid decisions and implementation contracts.\n",
    count($queue)
);
