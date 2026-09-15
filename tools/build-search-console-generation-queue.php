<?php
declare(strict_types=1);

/** Route Search Console opportunities into an advisory optimization/new-tool queue. */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/build-search-console-generation-queue.php <search-console-opportunities.json> <tools.json> [output.json]\n");
    exit(2);
}
$opportunityFile = $argv[1];
$registryFile = $argv[2];
$output = $argv[3] ?? dirname(__DIR__) . '/config/search-console-generation-queue.json';

foreach ([$opportunityFile, $registryFile] as $file) {
    if (!is_file($file)) { fwrite(STDERR, "File not found: {$file}\n"); exit(1); }
}
$opportunities = json_decode((string)file_get_contents($opportunityFile), true);
$registry = json_decode((string)file_get_contents($registryFile), true);
if (!is_array($opportunities) || !isset($opportunities['opportunities']) || !is_array($opportunities['opportunities']) || !is_array($registry) || !isset($registry['tools']) || !is_array($registry['tools'])) {
    fwrite(STDERR, "Invalid Search Console opportunity or registry input.\n"); exit(1);
}

$tools = [];
foreach ($registry['tools'] as $tool) {
    if (is_array($tool) && isset($tool['slug'])) $tools[(string)$tool['slug']] = $tool;
}

$actions = [];
$newCandidates = [];
foreach ($opportunities['opportunities'] as $item) {
    if (!is_array($item)) continue;
    $opportunity = (string)($item['opportunity'] ?? '');
    $query = trim((string)($item['query'] ?? ''));
    if ($query === '') continue;

    if (in_array($opportunity, ['optimize-ranking', 'optimize-snippet', 'investigate-no-click'], true)) {
        $slug = $item['matched_tool_slug'] ?? null;
        if ($slug === null || !isset($tools[$slug])) continue;
        $actions[] = [
            'query' => $query,
            'target_tool_slug' => $slug,
            'target_tool_name' => $tools[$slug]['name'] ?? null,
            'action' => $opportunity,
            'priority_score' => (int)($item['priority_score'] ?? 0),
            'clicks' => (float)($item['clicks'] ?? 0),
            'impressions' => (float)($item['impressions'] ?? 0),
            'ctr' => $item['ctr'] ?? null,
            'position' => $item['position'] ?? null,
            'country' => $item['country'] ?? null,
            'device' => $item['device'] ?? null,
            'page' => $item['page'] ?? null,
            'status' => 'review',
            'automatic_change_allowed' => false,
            'source' => 'google-search-console'
        ];
        continue;
    }

    if ($opportunity === 'new-tool-or-content-candidate') {
        $newCandidates[] = [
            'query' => $query,
            'priority_score' => (int)($item['priority_score'] ?? 0),
            'clicks' => (float)($item['clicks'] ?? 0),
            'impressions' => (float)($item['impressions'] ?? 0),
            'ctr' => $item['ctr'] ?? null,
            'position' => $item['position'] ?? null,
            'country' => $item['country'] ?? null,
            'device' => $item['device'] ?? null,
            'page' => $item['page'] ?? null,
            'decision' => 'candidate',
            'status' => 'candidate',
            'generation_eligible' => false,
            'automatic_publication_allowed' => false,
            'source' => 'google-search-console'
        ];
    }
}

usort($actions, static fn(array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score']) ?: ($b['impressions'] <=> $a['impressions']) ?: strcasecmp($a['query'], $b['query']));
usort($newCandidates, static fn(array $a, array $b): int => ($b['priority_score'] <=> $a['priority_score']) ?: ($b['impressions'] <=> $a['impressions']) ?: strcasecmp($a['query'], $b['query']));

$result = [
    'schema_version' => '1.0.0',
    'generated_at' => gmdate('Y-m-d'),
    'source' => 'google-search-console',
    'policy' => 'Search Console feedback is advisory. Existing-tool actions require review, and new candidates remain generation-ineligible and non-publishable until the normal specification, security, runtime, approval, and publication gates pass.',
    'existing_tool_actions' => $actions,
    'new_tool_or_content_candidates' => $newCandidates
];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write output.\n"); exit(1);
}
echo 'Built ' . count($actions) . ' existing-tool actions and ' . count($newCandidates) . " new-tool/content candidates.\n";
