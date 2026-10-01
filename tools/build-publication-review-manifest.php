<?php
declare(strict_types=1);

/**
 * Build the final human publication-review handoff AFTER generation and
 * automated validation. No record created here authorizes publication.
 */
if ($argc < 4) {
    fwrite(STDERR, "Usage: php tools/build-publication-review-manifest.php <specs.json> <security-decisions.json> <generated-dir> [output.json]\n");
    exit(2);
}
$specFile=$argv[1]; $decisionFile=$argv[2]; $generatedDir=rtrim($argv[3], '/\\');
$outputFile=$argv[4] ?? dirname(__DIR__).'/config/tool-factory-publication-review.json';
foreach([$specFile,$decisionFile] as $file) if(!is_file($file)){fwrite(STDERR,"Input missing: {$file}\n");exit(1);}
$specs=json_decode((string)file_get_contents($specFile),true);
$decisions=json_decode((string)file_get_contents($decisionFile),true);
if(!is_array($specs)||!is_array($specs['specifications']??null)||!is_array($decisions)||!is_array($decisions['decisions']??null)){fwrite(STDERR,"Invalid publication-review inputs.\n");exit(1);}
$decisionBySlug=[];
foreach($decisions['decisions'] as $d){if(!is_array($d))continue;$s=strtolower(trim((string)($d['source']['specification_slug']??'')));if($s!=='')$decisionBySlug[$s]=$d;}
$items=[];
foreach($specs['specifications'] as $spec){
    if(!is_array($spec))continue;
    $slug=strtolower(trim((string)($spec['tool']['slug']??'')));
    if($slug===''||!isset($decisionBySlug[$slug]))continue;
    $page=$generatedDir.'/'.$slug.'.html';
    if(!is_file($page)) { fwrite(STDERR,"Generated artifact missing: {$slug}\n"); exit(1); }
    $decision=$decisionBySlug[$slug];
    if(($decision['decision']??'')!=='allow'||($decision['safe_to_build']??false)!==true){fwrite(STDERR,"Security Gate not allow/safe_to_build: {$slug}\n");exit(1);}
    $items[]=[
        'candidate_id'=>hash('sha256', (($spec['spec_type']??'new_tool')==='enhancement'?'enhancement':'new_tool').'|'.$slug.'|'.hash('sha256',json_encode($spec,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))),
        'type'=>(($spec['spec_type']??'')==='enhancement'?'enhancement':'new_tool'),
        'slug'=>$slug,
        'name'=>(string)($spec['tool']['name']??$slug),
        'query'=>(string)($spec['source']['query']??$spec['seo']['target_query']??''),
        'spec_sha256'=>(string)($decision['evidence']['spec_sha256']??''),
        'generated_artifact_sha256'=>hash_file('sha256',$page),
        'security_decision'=>'allow',
        'security_evaluator_id'=>(string)($decision['evaluator_id']??''),
        'automated_validation'=>[
            'security_gate'=>'passed',
            'artifact_exists'=>true
        ],
        'review_status'=>'pending-human-publication-review',
        'publication_approval'=>[
            'decision'=>'pending',
            'reviewer'=>null,
            'reviewed_at'=>null,
            'notes'=>null
        ]
    ];
}
$result=[
 'schema_version'=>'2.0.0',
 'generated_at'=>gmdate('c'),
 'status'=>'pending-human-publication-review',
 'automatic_generation_allowed'=>true,
 'automatic_production_publish'=>false,
 'human_approval_scope'=>'publication-only',
 'candidates'=>$items
];
file_put_contents($outputFile,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX);
echo "Built final publication-review manifest for ".count($items)." generated candidate(s). Human approval is publication-only.\n";
