<?php
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/evaluate-automatic-publishing.php <deployment-plan.json> <policy.json> [output.json]\n");
    exit(2);
}
$planFile=$argv[1]; $policyFile=$argv[2]; $output=$argv[3] ?? dirname(__DIR__).'/config/automatic-publishing-decision.json';
foreach([$planFile,$policyFile] as $file){if(!is_file($file)){fwrite(STDERR,"File not found: {$file}\n");exit(1);}}
$plan=json_decode((string)file_get_contents($planFile),true); $policy=json_decode((string)file_get_contents($policyFile),true);
if(!is_array($plan)||!is_array($policy)||!isset($plan['tools'])||!is_array($plan['tools'])){fwrite(STDERR,"Invalid deployment plan or policy.\n");exit(1);}
$min=(int)($policy['automatic_publish']['minimum_score']??90); $enabled=(bool)($policy['automatic_publish']['enabled']??false);
$decisions=[];
foreach($plan['tools'] as $tool){
  if(!is_array($tool)) continue;
  $slug=(string)($tool['slug']??''); $score=(int)($tool['priority_score']??0);
  $eligible=$enabled && $score >= $min && ($tool['generation_eligible']??false) === true && ($tool['generated_quality']??false) === true && ($tool['generated_runtime']??false) === true && ($tool['human_publication_review']??false) === true && ($tool['registry_validation']??false) === true && ($tool['sitemap_validation']??false) === true && ($tool['post_publish_validation']??false) === true;
  $reasons=[];
  if(!$enabled)$reasons[]='automatic_publish_disabled';
  if($score<$min)$reasons[]='score_below_threshold';
  foreach(['generation_eligible','generated_quality','generated_runtime','human_publication_review','registry_validation','sitemap_validation','post_publish_validation'] as $gate){if(($tool[$gate]??false)!==true)$reasons[]=$gate.'_required';}
  $decisions[]=['slug'=>$slug,'decision'=>$eligible?'auto-publish-eligible':'review-required','automatic_publish'=>$eligible,'priority_score'=>$score,'reasons'=>$reasons];
}
$result=['schema_version'=>'1.0.0','generated_at'=>gmdate('Y-m-d'),'policy_mode'=>$policy['mode']??'unknown','automatic_publish_enabled'=>$enabled,'minimum_score'=>$min,'decisions'=>$decisions];
if(file_put_contents($output,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)===false){fwrite(STDERR,"Unable to write output.\n");exit(1);} echo 'Evaluated '.count($decisions)." publishing decisions.\n";
