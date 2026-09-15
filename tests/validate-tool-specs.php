<?php
declare(strict_types=1);

$root=dirname(__DIR__); $tool=$root.'/tools/build-tool-specs.php';
if (!is_file($tool)) { fwrite(STDERR,"Tool specification compiler missing.\n"); exit(1); }
$input=tempnam(sys_get_temp_dir(),'jt-spec-q-'); $output=tempnam(sys_get_temp_dir(),'jt-spec-o-');
file_put_contents($input,json_encode(['queue'=>[
 ['rank'=>1,'cluster_id'=>'age-calculator','core_query'=>'age calculator','decision'=>'candidate','priority_score'=>92,'demand_signal'=>220000,'country'=>'global','language'=>null,'confidence'=>'high','source'=>'google-ads-keyword-planner'],
 ['rank'=>2,'cluster_id'=>'qr-code-generator','core_query'=>'qr code generator','decision'=>'candidate','priority_score'=>76,'demand_signal'=>783000,'country'=>'US','language'=>'en','confidence'=>'high','source'=>'google-ads-keyword-planner'],
 ['rank'=>3,'cluster_id'=>'existing-enhancement','core_query'=>'image resizer','decision'=>'enhancement','priority_score'=>90,'demand_signal'=>100000,'country'=>'US','language'=>'en','confidence'=>'medium','source'=>'google-ads-keyword-planner']
]],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($input).' '.escapeshellarg($output); exec($cmd,$lines,$status);
$data=json_decode((string)file_get_contents($output),true); @unlink($input); @unlink($output);
if($status!==0 || !is_array($data) || count($data['specifications']??[])!==2){fwrite(STDERR,"Specification compiler failed.\n");exit(1);}
foreach($data['specifications'] as $spec){
 foreach(['spec_version','spec_status','generation_eligible','source','tool','purpose','inputs','outputs','ux','seo','privacy_security','acceptance_criteria','quality_gates','publication_policy'] as $field){if(!array_key_exists($field,$spec)){fwrite(STDERR,"Missing spec field: {$field}\n");exit(1);}}
 if($spec['spec_status']!=='draft' || $spec['generation_eligible']!==false){fwrite(STDERR,"Spec must remain a non-generation-authorizing draft.\n");exit(1);}
 if(($spec['privacy_security']['processing']??'')!=='browser_only' || ($spec['privacy_security']['network_requests']??true)!==false){fwrite(STDERR,"Privacy/security contract is not browser-only.\n");exit(1);}
 if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$spec['tool']['slug']??'')){fwrite(STDERR,"Invalid generated slug.\n");exit(1);}
 if(!is_array($spec['acceptance_criteria']) || count($spec['acceptance_criteria'])<2){fwrite(STDERR,"Acceptance criteria are incomplete.\n");exit(1);}
}
if(($data['specifications'][0]['tool']['slug']??'')!=='age-calculator'){fwrite(STDERR,"Age calculator specialization missing.\n");exit(1);}
if(($data['specifications'][1]['tool']['slug']??'')!=='qr-code-generator'){fwrite(STDERR,"QR specialization missing.\n");exit(1);}
echo "Tool specification validation: PASS\n";
