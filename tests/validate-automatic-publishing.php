<?php
declare(strict_types=1);
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/jt-auto-'.bin2hex(random_bytes(4)); mkdir($tmp,0700,true);
$plan=$tmp.'/plan.json'; $policy=$tmp.'/policy.json'; $out=$tmp.'/decision.json';
file_put_contents($plan,json_encode(['schema_version'=>'1.0.0','tools'=>[
 ['slug'=>'safe-tool','priority_score'=>95,'generation_eligible'=>true,'generated_quality'=>true,'generated_runtime'=>true,'human_publication_review'=>true,'registry_validation'=>true,'sitemap_validation'=>true,'post_publish_validation'=>true],
 ['slug'=>'unsafe-tool','priority_score'=>99,'generation_eligible'=>true,'generated_quality'=>true,'generated_runtime'=>false,'human_publication_review'=>true,'registry_validation'=>true,'sitemap_validation'=>true,'post_publish_validation'=>true]
]]));
file_put_contents($policy,json_encode(['schema_version'=>'1.0.0','mode'=>'high-confidence-only','automatic_publish'=>['enabled'=>false,'minimum_score'=>90]]));
$run=static function(string $cmd):void{passthru(PHP_BINARY.' '.$cmd,$code);if($code!==0)exit($code);};
$run(escapeshellarg($root.'/tools/evaluate-automatic-publishing.php').' '.escapeshellarg($plan).' '.escapeshellarg($policy).' '.escapeshellarg($out));
$data=json_decode((string)file_get_contents($out),true); if(!is_array($data)||count($data['decisions']??[])!==2){fwrite(STDERR,"Decision validation failed.\n");exit(1);}
foreach($data['decisions'] as $d){if(($d['automatic_publish']??true)!==false||($d['decision']??'')!=='review-required'){fwrite(STDERR,"Default-deny automatic publishing failed.\n");exit(1);}}
$policyData=json_decode((string)file_get_contents($policy),true);$policyData['automatic_publish']['enabled']=true;file_put_contents($policy,json_encode($policyData));
$run(escapeshellarg($root.'/tools/evaluate-automatic-publishing.php').' '.escapeshellarg($plan).' '.escapeshellarg($policy).' '.escapeshellarg($out));
$data=json_decode((string)file_get_contents($out),true);$by=[];foreach($data['decisions'] as $d)$by[$d['slug']]=$d;
if(($by['safe-tool']['automatic_publish']??false)!==true||($by['safe-tool']['decision']??'')!=='auto-publish-eligible'){fwrite(STDERR,"Eligible tool was not accepted.\n");exit(1);}
if(($by['unsafe-tool']['automatic_publish']??true)!==false){fwrite(STDERR,"Runtime failure bypassed publishing gate.\n");exit(1);}
foreach([$plan,$policy,$out] as $f)@unlink($f);@rmdir($tmp);echo "Automatic publishing gate validation passed.\n";
