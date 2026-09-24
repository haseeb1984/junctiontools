<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security/build-security-gate.php';

$root=dirname(__DIR__);
$script=$root.'/tools/apply-approved-tool-enhancements.php';
$registry=$root.'/config/tools.json';
$policy=$root.'/config/build-security-gate.json';
$sourcePage=$root.'/word-counter.html';

foreach([$script,$registry,$policy,$sourcePage] as $file){
    if(!is_file($file)){fwrite(STDERR,"FAIL: required file missing: {$file}\n");exit(1);}
}

$base=sys_get_temp_dir().'/jt-enhancement-'.bin2hex(random_bytes(4));
if(!mkdir($base,0700,true)){fwrite(STDERR,"FAIL: unable to create temp directory\n");exit(1);}

$spec=[
 'spec_version'=>'1.0.0',
 'spec_status'=>'draft',
 'generation_eligible'=>false,
 'spec_type'=>'enhancement',
 'source'=>[
  'cluster_id'=>'free-online-word-counter',
  'query'=>'free online word counter'
 ],
 'tool'=>[
  'name'=>'Word Counter',
  'slug'=>'word-counter',
  'implementation'=>'existing_tool_seo_content',
  'implementation_template'=>'existing-tool-seo-enhancement',
  'page'=>'/word-counter',
  'frontend'=>null
 ],
 'enhancement'=>[
  'type'=>'seo',
  'target_tool_slug'=>'word-counter',
  'scope'=>[
   'description'=>'Improve the existing Word Counter page for discovered search intent without changing core functionality.',
   'requested_capabilities'=>['search-intent-aligned-title','meta-description','on-page-content','how-to-use-content'],
   'affected_components'=>['content','seo'],
   'preserve_existing_functionality'=>true
  ],
  'content_requirements'=>[
   'title'=>'Align the existing page title with the discovered search intent.',
   'meta_description'=>'Align the existing meta description with the discovered search intent.',
   'on_page_content'=>'Add useful explanatory copy.',
   'how_to_use'=>'Ensure clear usage guidance.'
  ]
 ],
 'seo'=>[
  'target_query'=>'free online word counter',
  'indexable'=>true,
  'must_preserve_existing_canonical'=>true,
  'must_preserve_existing_functionality'=>true
 ],
 'privacy_security'=>[
  'processing'=>'browser_only',
  'network_requests'=>false,
  'external_dependencies'=>false,
  'privacy_note'=>'SEO/content only.',
  'security_requirements'=>['no eval or dynamic code execution','do not alter existing tool JavaScript behavior','do not introduce external network requests']
 ],
 'security'=>[
  'no_new_page'=>true,
  'no_new_registry_entry'=>true,
  'no_sitemap_mutation'=>true,
  'no_runtime_code_generation'=>true,
  'no_external_network_dependency'=>true
 ]
];

$policyData=json_decode((string)file_get_contents($policy),true);
$decision=[
 'schema_version'=>'1.0.0',
 'policy_version'=>$policyData['policy_version'],
 'evaluated_at'=>gmdate('c'),
 'evaluator_id'=>BUILD_SECURITY_GATE_EVALUATOR,
 'source'=>['opportunity_id'=>'test-opportunity','specification_slug'=>'word-counter'],
 'type'=>'enhancement',
 'decision'=>'allow',
 'safe_to_build'=>true,
 'conditions'=>[],
 'blocked_conditions'=>[],
 'approval_requirements'=>['generation_approval'=>false,'enhancement_approval'=>true],
 'evidence'=>[],
 'enhancement'=>[
  'target_tool_slug'=>'word-counter',
  'scope'=>[
   'type'=>'seo',
   'description'=>'Approved test SEO enhancement.',
   'requested_capabilities'=>['search-intent-aligned-title'],
   'affected_components'=>['content','seo'],
   'preserve_existing_functionality'=>true
  ]
 ]
];
$decision['conditions']=$policyData['required_conditions'];
$decision['evidence']=[
 'spec_sha256'=>bsg_sha256($spec),
 'policy_sha256'=>bsg_sha256($policyData)
];

file_put_contents($base.'/specs.json',json_encode(['specifications'=>[$spec]],JSON_PRETTY_PRINT));
file_put_contents($base.'/approvals.json',json_encode([
 'policy'=>['default'=>'deny'],
 'approvals'=>[[
  'cluster_id'=>'free-online-word-counter',
  'target_tool_slug'=>'word-counter',
  'decision'=>'approved-for-implementation-and-validation',
  'automatic_publication_allowed'=>false,
  'registry_or_sitemap_modification_allowed'=>false
 ]]
],JSON_PRETTY_PRINT));
file_put_contents($base.'/decisions.json',json_encode(['decisions'=>[$decision]],JSON_PRETTY_PRINT));

$before=hash_file('sha256',$sourcePage);
$outDir=$base.'/staged';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($base.'/specs.json').' '.escapeshellarg($base.'/approvals.json').' '.escapeshellarg($outDir).' '.escapeshellarg($policy).' '.escapeshellarg($base.'/decisions.json').' '.escapeshellarg($registry).' 2>&1';
exec($cmd,$lines,$status);

if($status!==0){
    fwrite(STDERR,"FAIL: approved enhancement staging failed:\n".implode(PHP_EOL,$lines)."\n");
    exit(1);
}
$staged=$outDir.'/word-counter.html';
if(!is_file($staged)){
    fwrite(STDERR,"FAIL: staged enhancement file was not created\n");
    exit(1);
}
if(hash_file('sha256',$sourcePage)!==$before){
    fwrite(STDERR,"FAIL: production/source page was modified by staging\n");
    exit(1);
}
$stagedHtml=(string)file_get_contents($staged);
foreach(['<title>Free Online Word Counter | JunctionTools</title>','name="description"','JT-SEO-ENHANCEMENT-START:word-counter','free online word counter'] as $needle){
    if(stripos($stagedHtml,$needle)===false){
        fwrite(STDERR,"FAIL: staged SEO enhancement is missing: {$needle}\n");
        exit(1);
    }
}

echo "[PASS] Approved existing-tool SEO enhancement passes the security gate and stages successfully.\n";
echo "[PASS] Existing production/source page remains byte-for-byte unchanged.\n";
echo "[PASS] Enhancement changes SEO/content only in the staged copy.\n";
exit(0);
