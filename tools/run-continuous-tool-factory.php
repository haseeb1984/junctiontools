<?php
declare(strict_types=1);

/**
 * Continuous Tool Factory orchestrator.
 *
 * Runs the non-publishing factory stages in an isolated workspace.
 * Human approvals remain the only authorization for code generation.
 *
 * Usage:
 * php tools/run-continuous-tool-factory.php <google-keyword-planner.json> [workspace]
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/run-continuous-tool-factory.php <google-keyword-planner.json> [workspace]\n");
    exit(2);
}
$input = $argv[1];
$workspace = $argv[2] ?? sys_get_temp_dir() . '/junctiontools-factory-' . bin2hex(random_bytes(5));
$root = dirname(__DIR__);
if (!is_file($input)) {
    fwrite(STDERR, "Demand input not found: {$input}\n");
    exit(1);
}
if (!is_dir($workspace) && !mkdir($workspace, 0775, true) && !is_dir($workspace)) {
    fwrite(STDERR, "Unable to create workspace: {$workspace}\n");
    exit(1);
}

$run = static function(string $script, array $args) use ($root): void {
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.ltrim($script,'/'));
    foreach ($args as $arg) $cmd .= ' '.escapeshellarg((string)$arg);
    passthru($cmd, $code);
    if ($code !== 0) throw new RuntimeException("Stage failed: {$script} (exit {$code})");
};

$normalizedInput = $workspace.'/keyword-planner-input.json';
$rawInput = json_decode((string)file_get_contents($input), true);
if (!is_array($rawInput)) {
    throw new RuntimeException('Demand input is not valid JSON.');
}
if (!isset($rawInput['keywords']) && isset($rawInput['clusters']) && is_array($rawInput['clusters'])) {
    $keywords = [];
    foreach ($rawInput['clusters'] as $cluster) {
        if (!is_array($cluster)) continue;
        foreach (($cluster['signals'] ?? []) as $signal) {
            if (!is_array($signal)) continue;
            $query = trim((string)($signal['query'] ?? $cluster['core_query'] ?? ''));
            $volume = $signal['search_volume'] ?? null;
            if ($query === '' || !is_numeric($volume)) continue;
            $keywords[] = [
                'query'=>$query,
                'country'=>(string)($signal['country'] ?? 'unspecified'),
                'language'=>$signal['language'] ?? null,
                'search_volume'=>(int)$volume,
                'competition'=>$signal['competition'] ?? null,
                'competition_index'=>$signal['competition_index'] ?? null
            ];
        }
    }
    $rawInput = ['keywords'=>$keywords];
}
if (!isset($rawInput['keywords']) || !is_array($rawInput['keywords'])) {
    throw new RuntimeException('Demand input must contain keywords or search-demand clusters.');
}
file_put_contents($normalizedInput, json_encode($rawInput, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);

$demand = $workspace.'/google-demand-opportunities.json';
$queue = $workspace.'/generation-queue.json';
$specs = $workspace.'/tool-specs.json';
$decisions = $workspace.'/security-decisions.json';

try {
    $run('tools/build-demand-opportunities.php', [$normalizedInput, $demand]);
    $run('tools/build-generation-queue.php', [$demand, $root.'/config/tools.json', $queue]);
    $run('tools/build-tool-specs.php', [$queue, $specs]);

    $policy = json_decode((string)file_get_contents($root.'/config/build-security-gate.json'), true);
    $specData = json_decode((string)file_get_contents($specs), true);
    if (!is_array($policy) || !is_array($specData) || !is_array($specData['specifications'] ?? null)) {
        throw new RuntimeException('Invalid security policy or generated specifications.');
    }

    require_once $root.'/security/build-security-gate.php';
    $out = [];
    foreach ($specData['specifications'] as $spec) {
        if (!is_array($spec)) continue;
        $tool = $spec['tool'] ?? [];
        if (!is_array($tool)) continue;
        $slug = strtolower(trim((string)($tool['slug'] ?? '')));
        if ($slug === '') continue;

        $type = (($spec['spec_type'] ?? '') === 'enhancement') ? 'enhancement' : 'new_tool';
        $decision = [
            'decision' => 'allow',
            'type' => $type,
            'safe_to_build' => true,
            'evaluator_id' => 'build-security-gate-v1',
            'policy_version' => (string)($policy['policy_version'] ?? ''),
            'evaluated_at' => gmdate('c'),
            'source' => ['specification_slug' => $slug],
            'conditions' => $policy['required_conditions'] ?? [],
            'blocked_conditions' => [],
            'approval_requirements' => [
                'generation_approval' => $type === 'new_tool',
                'enhancement_approval' => $type === 'enhancement'
            ],
            'evidence' => [
                'spec_sha256' => bsg_sha256($spec),
                'policy_sha256' => bsg_sha256($policy)
            ]
        ];
        if ($type === 'enhancement') {
            $enh = $spec['enhancement'] ?? [];
            $scope = is_array($enh['scope'] ?? null) ? $enh['scope'] : [];
            $decision['enhancement'] = [
                'target_tool_slug' => (string)($enh['target_tool_slug'] ?? $slug),
                'scope' => array_merge($scope, ['preserve_existing_functionality' => true])
            ];
        }
        $check = bsg_evaluate($spec, $decision, $policy);
        if (($check['allowed'] ?? false) !== true) {
            throw new RuntimeException("Security Gate rejected {$slug}: ".($check['message'] ?? 'rejected'));
        }
        $out[] = $decision;
    }

    file_put_contents($decisions, json_encode([
        'schema_version'=>'1.0.0',
        'policy_version'=>(string)($policy['policy_version'] ?? ''),
        'generated_at'=>gmdate('c'),
        'decisions'=>$out
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);

    file_put_contents($workspace.'/factory-report.json', json_encode([
        'schema_version'=>'1.0.0',
        'status'=>'ready-for-human-approval',
        'automatic_production_publish'=>false,
        'human_approval_required'=>true,
        'generated_at'=>gmdate('c'),
        'artifacts'=>[
            'demand'=>$demand,
            'queue'=>$queue,
            'specifications'=>$specs,
            'security_decisions'=>$decisions
        ],
        'approval_sources'=>[
            'new_tools'=>$root.'/config/tool-generation-approvals.json',
            'enhancements'=>$root.'/config/tool-enhancement-approvals.json'
        ]
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);

    echo "Continuous Tool Factory: ready-for-human-approval\n";
    echo "Workspace: {$workspace}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
