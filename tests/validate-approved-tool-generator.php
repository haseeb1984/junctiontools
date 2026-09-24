<?php
declare(strict_types=1);

$root=dirname(__DIR__); $tool=$root.'/tools/generate-approved-tools.php'; require_once $root.'/security/build-security-gate.php';
if (!is_file($tool)) { fwrite(STDERR,"Approved tool generator missing.\n"); exit(1); }
$dir=sys_get_temp_dir().'/jt-generated-'.bin2hex(random_bytes(4)); mkdir($dir,0775,true);
$spec=tempnam(sys_get_temp_dir(),'jt-spec-'); $approval=tempnam(sys_get_temp_dir(),'jt-approval-');
file_put_contents($spec,json_encode(['specifications'=>[[
 'spec_status'=>'draft','generation_eligible'=>false,
 'seo'=>['description'=>'Free age calculator.','title'=>'Age Calculator | Free Online Tool | JunctionTools'],
 'tool'=>['name'=>'Age Calculator','slug'=>'age-calculator','implementation_template'=>'date-age-calculator'],
 'inputs'=>['fields'=>[['name'=>'birth_date','type'=>'date'],['name'=>'as_of_date','type'=>'date']]],
 'content'=>['how_to_use'=>['Enter your birth date.','Choose the calculation date.','Click Calculate Age.']],
 'privacy_security'=>['processing'=>'browser_only','network_requests'=>false,'external_dependencies'=>false,'security_requirements'=>['No network access.','No server-side storage.']]
]]],JSON_PRETTY_PRINT));
file_put_contents($approval,json_encode(['approvals'=>[['slug'=>'age-calculator','approved'=>true,'approved_by'=>'ci-test','approved_at'=>'2026-09-15T00:00:00Z']]],JSON_PRETTY_PRINT));
$securityDecision=tempnam(sys_get_temp_dir(),'jt-security-decision-');
$testRegistry=tempnam(sys_get_temp_dir(),'jt-registry-');
file_put_contents($testRegistry,json_encode(['tools'=>[]],JSON_PRETTY_PRINT));
$specData=json_decode((string)file_get_contents($spec),true);
$policy=(string)file_get_contents($root.'/config/build-security-gate.json');
$policyData=json_decode($policy,true);
$conditions=$policyData['required_conditions'];
$decision=['schema_version'=>'1.0.0','policy_version'=>$policyData['policy_version'],'evaluated_at'=>gmdate('Y-m-d\\TH:i:s\\Z'),'evaluator_id'=>BUILD_SECURITY_GATE_EVALUATOR,'source'=>['opportunity_id'=>'ci-test','specification_slug'=>'age-calculator'],'decision'=>'allow','type'=>'new_tool','conditions'=>$conditions,'blocked_conditions'=>[],'safe_to_build'=>true,'approval_requirements'=>['generation_approval'=>true,'enhancement_approval'=>false],'evidence'=>['spec_sha256'=>bsg_sha256($specData['specifications'][0]),'policy_sha256'=>bsg_sha256($policyData)]];
file_put_contents($securityDecision,json_encode(['schema_version'=>'1.0.0','policy_version'=>$policyData['policy_version'],'decisions'=>[$decision]],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($dir).' '.escapeshellarg($root.'/config/build-security-gate.json').' '.escapeshellarg($securityDecision).' '.escapeshellarg($testRegistry); exec($cmd,$lines,$status);
$file=$dir.'/age-calculator.html';
if($status!==0 || !is_file($file)){fwrite(STDERR,"Approved generation failed.\n");exit(1);}
$html=(string)file_get_contents($file);
foreach(['<title>Age Calculator | Free Online Tool | JunctionTools</title>','<link rel="canonical" href="https://junctiontools.com/age-calculator">','Date of Birth','Calculate Age On','id="birth_date"','id="as_of_date"'] as $needle){if(strpos($html,$needle)===false){fwrite(STDERR,"Generated page missing: {$needle}\n");exit(1);}}
foreach(['eval(','fetch(','XMLHttpRequest'] as $forbidden){if(stripos($html,$forbidden)!==false){fwrite(STDERR,"Forbidden generated pattern: {$forbidden}\n");exit(1);}}
$scriptPattern="~<script\\s+src=[\"']([^\"']+)[\"']~i";
if(preg_match_all($scriptPattern,$html,$scriptMatches)){
  $allowedScripts=['https://cdn.tailwindcss.com','https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js'];
  foreach($scriptMatches[1] as $src){
    if(!in_array($src,$allowedScripts,true)){
      fwrite(STDERR,"Unapproved generated script source: {$src}\\n");
      exit(1);
    }
  }
}
$traversalSpec=tempnam(sys_get_temp_dir(),'jt-traversal-spec-');
$traversalApproval=tempnam(sys_get_temp_dir(),'jt-traversal-approval-');
$traversalDir=$dir.'/safe-output'; mkdir($traversalDir);
file_put_contents($traversalSpec,json_encode(['specifications'=>[[
 'spec_status'=>'draft','generation_eligible'=>false,
 'seo'=>['description'=>'Traversal test','title'=>'Traversal Test'],
 'tool'=>['name'=>'Traversal Test','slug'=>'../outside-generated','implementation_template'=>'generic-form'],
 'inputs'=>['fields'=>[['name'=>'input','type'=>'text']]],
 'content'=>['how_to_use'=>['Step one','Step two','Step three']]
]]],JSON_PRETTY_PRINT));
file_put_contents($traversalApproval,json_encode(['approvals'=>[['slug'=>'../outside-generated','approved'=>true]]],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($traversalSpec).' '.escapeshellarg($traversalApproval).' '.escapeshellarg($traversalDir).' '.escapeshellarg($root.'/config/build-security-gate.json').' '.escapeshellarg($securityDecision).' '.escapeshellarg($testRegistry);
exec($cmd,$traversalLines,$traversalStatus);
if($traversalStatus===0 || is_file($dir.'/outside-generated.html')){fwrite(STDERR,"Path traversal slug was accepted.\n");exit(1);}
@unlink($traversalSpec); @unlink($traversalApproval); @rmdir($traversalDir);

$empty=tempnam(sys_get_temp_dir(),'jt-empty-'); file_put_contents($empty,json_encode(['approvals'=>[]])); $emptyDir=$dir.'/empty'; mkdir($emptyDir);
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($spec).' '.escapeshellarg($empty).' '.escapeshellarg($emptyDir).' '.escapeshellarg($root.'/config/build-security-gate.json').' '.escapeshellarg($securityDecision).' '.escapeshellarg($testRegistry); exec($cmd,$lines2,$status2);
if($status2!==0 || count(glob($emptyDir.'/*.html'))!==0){fwrite(STDERR,"Unapproved specification was generated.\n");exit(1);}
// Prove the real registry duplicate guard rejects an existing tool.
$duplicateDir=$dir.'/duplicate-check'; mkdir($duplicateDir);
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($duplicateDir).' '.escapeshellarg($root.'/config/build-security-gate.json').' '.escapeshellarg($securityDecision).' '.escapeshellarg($root.'/config/tools.json');
exec($cmd,$duplicateLines,$duplicateStatus);
if($duplicateStatus===0 || is_file($duplicateDir.'/age-calculator.html')){fwrite(STDERR,"Existing registry duplicate was generated.\n");exit(1);}
if(strpos(implode("\n",$duplicateLines),'Duplicate existing tool rejected: age-calculator')===false){fwrite(STDERR,"Duplicate registry rejection message missing.\n");exit(1);}
@unlink($spec); @unlink($approval); @unlink($securityDecision); @unlink($empty); @unlink($testRegistry); @unlink($file); @rmdir($emptyDir); @rmdir($duplicateDir); @rmdir($dir);
echo "Approval-gated tool generator validation: PASS\n";
