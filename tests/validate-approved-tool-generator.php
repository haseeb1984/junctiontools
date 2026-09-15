<?php
declare(strict_types=1);

$root=dirname(__DIR__); $tool=$root.'/tools/generate-approved-tools.php';
if (!is_file($tool)) { fwrite(STDERR,"Approved tool generator missing.\n"); exit(1); }
$dir=sys_get_temp_dir().'/jt-generated-'.bin2hex(random_bytes(4)); mkdir($dir,0775,true);
$spec=tempnam(sys_get_temp_dir(),'jt-spec-'); $approval=tempnam(sys_get_temp_dir(),'jt-approval-');
file_put_contents($spec,json_encode(['specifications'=>[[
 'spec_status'=>'draft','generation_eligible'=>false,
 'seo'=>['description'=>'Free age calculator.'],
 'tool'=>['name'=>'Age Calculator','slug'=>'age-calculator']
]]],JSON_PRETTY_PRINT));
file_put_contents($approval,json_encode(['approvals'=>[['slug'=>'age-calculator','approved'=>true,'approved_by'=>'ci-test','approved_at'=>'2026-09-15T00:00:00Z']]],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($dir); exec($cmd,$lines,$status);
$file=$dir.'/age-calculator.html';
if($status!==0 || !is_file($file)){fwrite(STDERR,"Approved generation failed.\n");exit(1);}
$html=(string)file_get_contents($file);
foreach(['<title>Age Calculator | Free Online Tool | JunctionTools</title>','<link rel="canonical" href="https://junctiontools.com/age-calculator">','Date of Birth','Calculate Age On','ageCalculator()'] as $needle){if(strpos($html,$needle)===false){fwrite(STDERR,"Generated page missing: {$needle}\n");exit(1);}}
foreach(['eval(','fetch(','XMLHttpRequest','<script src='] as $forbidden){if(stripos($html,$forbidden)!==false){fwrite(STDERR,"Forbidden generated pattern: {$forbidden}\n");exit(1);}}
$empty=tempnam(sys_get_temp_dir(),'jt-empty-'); file_put_contents($empty,json_encode(['approvals'=>[]])); $emptyDir=$dir.'/empty'; mkdir($emptyDir);
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($tool).' '.escapeshellarg($spec).' '.escapeshellarg($empty).' '.escapeshellarg($emptyDir); exec($cmd,$lines2,$status2);
if($status2!==0 || count(glob($emptyDir.'/*.html'))!==0){fwrite(STDERR,"Unapproved specification was generated.\n");exit(1);}
@unlink($spec); @unlink($approval); @unlink($empty); @unlink($file); @rmdir($emptyDir); @rmdir($dir);
echo "Approval-gated tool generator validation: PASS\n";
