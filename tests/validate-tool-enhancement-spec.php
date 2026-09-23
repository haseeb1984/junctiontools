<?php
declare(strict_types=1);

/** Proves existing-tool enhancement queues compile into enhancement-only SEO specs. */
$root=dirname(__DIR__);
$script=$root.'/tools/build-tool-specs.php';
if(!is_file($script)){fwrite(STDERR,"Required specification builder is missing.\n");exit(3);}

$base=sys_get_temp_dir().'/jt-spec-routing-'.bin2hex(random_bytes(4));
if(!mkdir($base,0700,true)){fwrite(STDERR,"Unable to create temp directory.\n");exit(3);}

$queue=[
 'schema_version'=>'1.0.0',
 'queue'=>[
  [
   'rank'=>1,'cluster_id'=>'free-online-word-counter','core_query'=>'free online word counter',
   'decision'=>'enhancement','target_tool_slug'=>'word-counter','existing_tool_match'=>'word_counter',
   'priority_score'=>80,'demand_signal'=>10000,'country'=>'US','language'=>'en','confidence'=>'high','source'=>'google-ads-keyword-planner',
   'enhancement_scope'=>[
    'type'=>'seo',
    'description'=>'Improve the existing tool page for the discovered search intent without changing its core functionality.',
    'requested_capabilities'=>['search-intent-aligned-title','meta-description','on-page-content','how-to-use-content'],
    'affected_components'=>['content','seo'],
    'preserve_existing_functionality'=>true
   ]
  ],
  [
   'rank'=>2,'cluster_id'=>'mortgage-amortization-calculator','core_query'=>'mortgage amortization calculator',
   'decision'=>'candidate','recommended_slug'=>'mortgage-amortization-calculator',
   'priority_score'=>50,'demand_signal'=>3000,'country'=>'US','language'=>'en','confidence'=>'medium','source'=>'google-ads-keyword-planner'
  ]
 ]
];

file_put_contents($base.'/queue.json',json_encode($queue));
$out=$base.'/specs.json';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($base.'/queue.json').' '.escapeshellarg($out).' 2>&1';
exec($cmd,$lines,$status);
if($status!==0){fwrite(STDERR,"Specification builder failed: ".implode(PHP_EOL,$lines)."\n");exit(1);}

$data=json_decode((string)file_get_contents($out),true);
if(!is_array($data)||!is_array($data['specifications']??null)||count($data['specifications'])!==2){
 fwrite(STDERR,"FAIL: expected one enhancement spec and one new-tool draft.\n");exit(1);
}

$enhancement=null;$candidate=null;
foreach($data['specifications'] as $spec){
 if(($spec['spec_type']??'')==='enhancement')$enhancement=$spec;
 if(($spec['tool']['slug']??'')==='mortgage-amortization-calculator')$candidate=$spec;
}
if(!is_array($enhancement)){
 fwrite(STDERR,"FAIL: existing-tool demand did not produce an enhancement specification.\n");exit(1);
}
if(($enhancement['tool']['slug']??'')!=='word-counter'||($enhancement['enhancement']['target_tool_slug']??'')!=='word-counter'){
 fwrite(STDERR,"FAIL: enhancement target is not the existing Word Counter.\n");exit(1);
}
if(($enhancement['generation_eligible']??true)!==false||($enhancement['security']['no_new_page']??false)!==true||($enhancement['security']['no_new_registry_entry']??false)!==true){
 fwrite(STDERR,"FAIL: enhancement spec permits duplicate/new-tool behavior.\n");exit(1);
}
if(($enhancement['seo']['target_query']??'')!=='free online word counter'||($enhancement['seo']['must_preserve_existing_functionality']??false)!==true){
 fwrite(STDERR,"FAIL: enhancement SEO intent/functionality preservation contract is invalid.\n");exit(1);
}
if(!is_array($candidate)||($candidate['spec_type']??'enhancement')==='enhancement'){
 fwrite(STDERR,"FAIL: genuinely new demand was not kept as a new-tool specification.\n");exit(1);
}

echo "[PASS] Existing demand compiles to an enhancement-only SEO specification.\n";
echo "[PASS] Enhancement spec targets the existing tool and forbids a new page/registry entry.\n";
echo "[PASS] New demand remains a separate draft new-tool specification.\n";
exit(0);
