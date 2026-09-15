<?php
declare(strict_types=1);

$queuePath = __DIR__ . '/../config/tool-generation-queue.json';
$registryPath = __DIR__ . '/../config/tools.json';

if (!is_file($queuePath)) { fwrite(STDERR, "Missing tool-generation-queue.json\n"); exit(1); }
if (!is_file($registryPath)) { fwrite(STDERR, "Missing tools.json\n"); exit(1); }

$data = json_decode((string) file_get_contents($queuePath), true);
$registry = json_decode((string) file_get_contents($registryPath), true);
if (!is_array($data)) { fwrite(STDERR, "Generation queue JSON is invalid.\n"); exit(1); }
if (!is_array($registry) || !is_array($registry['tools'] ?? null)) { fwrite(STDERR, "Tool registry JSON is invalid.\n"); exit(1); }
if (($data['schema_version'] ?? null) !== '1.0.0') { fwrite(STDERR, "Unsupported generation queue schema version.\n"); exit(1); }

$queue = $data['queue'] ?? null;
if (!is_array($queue) || $queue === []) { fwrite(STDERR, "Generation queue must contain at least one entry.\n"); exit(1); }

$allowedDecisions = ['build', 'research', 'hold', 'blocked'];
$allowedRanks = range(1, count($queue));
$seenClusters = [];
$seenRanks = [];
$seenSlugs = [];
$previousScore = null;
$registryById = [];
$registrySlugs = [];
$registryNames = [];

foreach ($registry['tools'] as $toolIndex => $tool) {
    if (!is_array($tool) || !is_string($tool['id'] ?? null) || !is_string($tool['slug'] ?? null)) {
        fwrite(STDERR, "Invalid tool registry entry at index {$toolIndex}.\n"); exit(1);
    }
    $registryById[$tool['id']] = $tool;
    $registrySlugs[strtolower($tool['slug'])] = $tool['id'];
    if (is_string($tool['name'] ?? null)) $registryNames[strtolower($tool['name'])] = $tool['id'];
}

foreach ($queue as $index => $entry) {
    if (!is_array($entry)) { fwrite(STDERR, "Queue entry {$index} must be an object.\n"); exit(1); }
    foreach (['rank','cluster_id','core_query','decision','priority_score','demand_signal','signal_count','existing_tool_match','implementation','recommended_slug','rationale'] as $field) {
        if (!array_key_exists($field, $entry)) { fwrite(STDERR, "Queue entry {$index} missing {$field}.\n"); exit(1); }
    }
    $rank = $entry['rank'];
    if (!is_int($rank) || !in_array($rank, $allowedRanks, true) || isset($seenRanks[$rank])) { fwrite(STDERR, "Invalid or duplicate rank at queue entry {$index}.\n"); exit(1); }
    $seenRanks[$rank] = true;
    $cluster = $entry['cluster_id'];
    if (!is_string($cluster) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $cluster) || isset($seenClusters[$cluster])) { fwrite(STDERR, "Invalid or duplicate cluster_id at queue entry {$index}.\n"); exit(1); }
    $seenClusters[$cluster] = true;
    if (!is_string($entry['core_query']) || trim($entry['core_query']) === '') { fwrite(STDERR, "Empty core_query at queue entry {$index}.\n"); exit(1); }
    if (!in_array($entry['decision'], $allowedDecisions, true)) { fwrite(STDERR, "Invalid decision at queue entry {$index}.\n"); exit(1); }
    if (!is_int($entry['priority_score']) || $entry['priority_score'] < 0 || $entry['priority_score'] > 100) { fwrite(STDERR, "Priority score must be an integer from 0 to 100 at queue entry {$index}.\n"); exit(1); }
    if ($previousScore !== null && $entry['priority_score'] > $previousScore) { fwrite(STDERR, "Queue is not sorted by descending priority_score.\n"); exit(1); }
    $previousScore = $entry['priority_score'];
    foreach (['demand_signal','signal_count'] as $numericField) {
        if (!is_int($entry[$numericField]) || $entry[$numericField] < 0) { fwrite(STDERR, "{$numericField} must be a non-negative integer at queue entry {$index}.\n"); exit(1); }
    }
    if ($entry['existing_tool_match'] !== null) {
        if (!is_string($entry['existing_tool_match']) || !isset($registryById[$entry['existing_tool_match']])) { fwrite(STDERR, "existing_tool_match must reference an existing registry tool at queue entry {$index}.\n"); exit(1); }
        fwrite(STDERR, "Duplicate-tool prevention failed: queue entry {$index} is marked for generation but matches an existing tool.\n"); exit(1);
    }
    if (!is_string($entry['implementation']) || trim($entry['implementation']) === '') { fwrite(STDERR, "Empty implementation at queue entry {$index}.\n"); exit(1); }
    if (!is_string($entry['recommended_slug']) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $entry['recommended_slug'])) { fwrite(STDERR, "Invalid recommended_slug at queue entry {$index}.\n"); exit(1); }

    $slugKey = strtolower($entry['recommended_slug']);
    if (isset($registrySlugs[$slugKey])) {
        $matchedTool = $registryById[$registrySlugs[$slugKey]];
        $isCompletedBuild = $entry['decision'] === 'build'
            && strtolower(trim($entry['core_query'])) === strtolower(trim((string) ($matchedTool['name'] ?? '')))
            && strtolower((string) ($matchedTool['slug'] ?? '')) === $slugKey;
        if (!$isCompletedBuild) { fwrite(STDERR, "Duplicate-tool prevention failed: recommended_slug '{$entry['recommended_slug']}' already exists in the tool registry.\n"); exit(1); }
    }
    if (isset($seenSlugs[$slugKey])) { fwrite(STDERR, "Duplicate recommended_slug at queue entry {$index}.\n"); exit(1); }
    $seenSlugs[$slugKey] = true;
    if (!is_array($entry['seo_page_cluster'] ?? null) || $entry['seo_page_cluster'] === []) { fwrite(STDERR, "seo_page_cluster must be a non-empty array at queue entry {$index}.\n"); exit(1); }
    if (!is_string($entry['rationale']) || trim($entry['rationale']) === '') { fwrite(STDERR, "Empty rationale at queue entry {$index}.\n"); exit(1); }
}

if ($seenRanks !== array_fill_keys($allowedRanks, true)) { fwrite(STDERR, "Queue ranks must be contiguous from 1 to the queue length.\n"); exit(1); }

$enhancements = $data['enhancements'] ?? [];
if (!is_array($enhancements)) { fwrite(STDERR, "enhancements must be an array.\n"); exit(1); }
$seenEnhancementClusters = [];
foreach ($enhancements as $index => $enhancement) {
    if (!is_array($enhancement)) { fwrite(STDERR, "Enhancement entry {$index} must be an object.\n"); exit(1); }
    foreach (['cluster_id','core_query','decision','existing_tool_match','target_tool_slug','recommended_slug','rationale'] as $field) {
        if (!array_key_exists($field, $enhancement)) { fwrite(STDERR, "Enhancement entry {$index} missing {$field}.\n"); exit(1); }
    }
    if ($enhancement['decision'] !== 'enhancement') { fwrite(STDERR, "Enhancement entry {$index} must have decision=enhancement.\n"); exit(1); }
    $cluster = $enhancement['cluster_id'];
    if (!is_string($cluster) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $cluster) || isset($seenEnhancementClusters[$cluster])) { fwrite(STDERR, "Invalid or duplicate enhancement cluster_id at entry {$index}.\n"); exit(1); }
    $seenEnhancementClusters[$cluster] = true;
    if (!is_string($enhancement['core_query']) || trim($enhancement['core_query']) === '') { fwrite(STDERR, "Empty enhancement core_query at entry {$index}.\n"); exit(1); }
    $match = $enhancement['existing_tool_match'];
    $targetSlug = $enhancement['target_tool_slug'];
    if (!is_string($match) || !isset($registryById[$match])) { fwrite(STDERR, "Enhancement entry {$index} must reference an existing registry tool.\n"); exit(1); }
    if (!is_string($targetSlug) || !isset($registrySlugs[strtolower($targetSlug)]) || $registrySlugs[strtolower($targetSlug)] !== $match) { fwrite(STDERR, "Enhancement entry {$index} target_tool_slug does not match existing_tool_match.\n"); exit(1); }
    if (!is_string($enhancement['recommended_slug']) || strtolower($enhancement['recommended_slug']) !== strtolower($targetSlug)) { fwrite(STDERR, "Enhancement entry {$index} must reuse the target tool slug rather than create a new slug.\n"); exit(1); }
    if (isset($seenClusters[$cluster])) { fwrite(STDERR, "Enhancement cluster '{$cluster}' must not also appear in the generation queue.\n"); exit(1); }
}

foreach ($registryNames as $name => $id) {
    foreach ($queue as $entry) {
        if (strtolower($entry['core_query']) === $name) {
            $isCompletedBuild = $entry['decision'] === 'build'
                && strtolower($entry['recommended_slug']) === strtolower((string) ($registryById[$id]['slug'] ?? ''));
            if (!$isCompletedBuild) { fwrite(STDERR, "Duplicate-tool prevention failed: queue query matches existing tool name '{$name}'.\n"); exit(1); }
        }
    }
}

echo sprintf("Generation queue validation passed: %d build/research opportunities, %d enhancements, duplicate-tool prevention enforced.\n", count($queue), count($enhancements));
