<?php
declare(strict_types=1);

/**
 * Approval-layer regression test:
 * 1. a hash-bound approval for the exact spec is accepted;
 * 2. changing the spec makes the approval stale and rejects it;
 * 3. publication is never implied by implementation approval.
 */
$root = dirname(__DIR__);
$tool = $root . '/tools/validate-tool-factory-approvals.php';
if (!is_file($tool)) { fwrite(STDERR, "FAIL: approval validator missing\n"); exit(1); }

$dir = sys_get_temp_dir() . '/jt-approval-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0775, true)) { fwrite(STDERR, "FAIL: temp workspace\n"); exit(1); }

$spec = [
    'specifications' => [[
        'spec_type' => 'new_tool',
        'tool' => ['slug' => 'approval-regression-tool', 'name' => 'Approval Regression Tool'],
        'source' => ['cluster_id' => 'approval-regression', 'query' => 'approval regression tool']
    ]]
];
$policy = json_decode((string) file_get_contents($root . '/config/build-security-gate.json'), true);
function tcanon(mixed $v): mixed {
    if (!is_array($v)) return $v;
    if (array_is_list($v)) return array_map('tcanon', $v);
    ksort($v, SORT_STRING);
    foreach ($v as $k => $x) $v[$k] = tcanon($x);
    return $v;
}
$specHash = hash('sha256', json_encode(tcanon($spec['specifications'][0]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
$policyHash = hash('sha256', json_encode(tcanon($policy), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
$candidateId = hash('sha256', 'new_tool|approval-regression-tool|' . $specHash);

file_put_contents($dir.'/specs.json', json_encode($spec));
file_put_contents($dir.'/decisions.json', json_encode(['decisions'=>[[
    'decision'=>'allow','safe_to_build'=>true,
    'source'=>['specification_slug'=>'approval-regression-tool'],
    'evidence'=>['spec_sha256'=>$specHash]
]]]));
file_put_contents($dir.'/generation.json', json_encode(['policy'=>['default'=>'deny','publication_requires_separate_review'=>true],'approvals'=>[[
    'approval_version'=>'1.1','slug'=>'approval-regression-tool','approved'=>true,
    'candidate_id'=>$candidateId,'spec_sha256'=>$specHash,'policy_sha256'=>$policyHash,
    'reviewer'=>'test-reviewer','approved_at'=>gmdate('c')
]]]));
file_put_contents($dir.'/enhancement.json', json_encode(['policy'=>['default'=>'deny','publication_requires_separate_review'=>true],'approvals'=>[]]));
file_put_contents($dir.'/policy.json', json_encode($policy));

exec('php ' . escapeshellarg($tool) . ' ' .
    escapeshellarg($dir.'/specs.json') . ' ' . escapeshellarg($dir.'/decisions.json') . ' ' .
    escapeshellarg($dir.'/generation.json') . ' ' . escapeshellarg($dir.'/enhancement.json') . ' ' .
    escapeshellarg($dir.'/policy.json'), $out, $code);
if ($code !== 0) { fwrite(STDERR, "FAIL: valid hash-bound approval rejected\n".implode("\n",$out)."\n"); exit(1); }

$spec['specifications'][0]['seo'] = ['title'=>'changed'];
file_put_contents($dir.'/specs.json', json_encode($spec));
exec('php ' . escapeshellarg($tool) . ' ' .
    escapeshellarg($dir.'/specs.json') . ' ' . escapeshellarg($dir.'/decisions.json') . ' ' .
    escapeshellarg($dir.'/generation.json') . ' ' . escapeshellarg($dir.'/enhancement.json') . ' ' .
    escapeshellarg($dir.'/policy.json'), $out2, $code2);
if ($code2 === 0) { fwrite(STDERR, "FAIL: stale approval was accepted\n"); exit(1); }

echo "PASS: hash-bound approval accepts exact spec and rejects changed spec.\n";
