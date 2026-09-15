<?php
declare(strict_types=1);

$root=dirname(__DIR__); $generator=$root.'/tools/generate-approved-tools.php';
if (!is_file($generator)) { fwrite(STDERR,"Generator missing.\n"); exit(1); }
$spec=tempnam(sys_get_temp_dir(),'jt-g-spec-'); $approval=tempnam(sys_get_temp_dir(),'jt-g-app-');
$out=sys_get_temp_dir().'/jt-generated-'.bin2hex(random_bytes(4)); mkdir($out,0775,true);
$specData=['specifications'=>[[
 'spec_status'=>'draft','generation_eligible'=>false,
 'seo'=>['description'=>'Free age calculator.'],
 'tool'=>['name'=>'Age Calculator','slug'=>'age-calculator']
]]];
file_put_contents($spec,json_encode($specData,JSON_PRETTY_PRINT));
file_put_contents($approval,json_encode(['approvals'=>[['slug'=>'age-calculator','approved'=>true]]],JSON_PRETTY_PRINT));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($generator).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($out);
exec($cmd,$lines,$status);
$file=$out.'/age-calculator.html';
if($status!==0 || !is_file($file)){fwrite(STDERR,"Generated tool was not created.\n");exit(1);}
$html=(string)file_get_contents($file);
$checks=[
 '<!doctype html>'=>'document structure',
 '<meta name="viewport"'=>'viewport metadata',
 '<title>Age Calculator | Free Online Tool | JunctionTools</title>'=>'title',
 '<meta name="description"'=>'description',
 '<link rel="canonical" href="https://junctiontools.com/age-calculator">'=>'canonical',
 '<h1>Age Calculator</h1>'=>'H1',
 'id="birthDate"'=>'birth date input',
 'id="asOfDate"'=>'calculation date input',
 'id="calculate"'=>'calculate control',
 'aria-live="polite"'=>'accessible result region',
 'function ageCalculator()'=>'tool logic',
 'Date.UTC('=>'deterministic day calculation'
];
foreach($checks as $needle=>$label){if(strpos($html,$needle)===false){fwrite(STDERR,"Missing {$label}.\n");exit(1);}}
$forbidden=['eval(','new Function(','document.write(','innerHTML =','fetch(','XMLHttpRequest','WebSocket(','<script src=','http://','https://'];
foreach($forbidden as $needle){if(stripos($html,$needle)!==false){fwrite(STDERR,"Forbidden generated pattern: {$needle}\n");exit(1);}}
if(substr_count($html,'<script>')!==1 || substr_count($html,'</script>')!==1){fwrite(STDERR,"Unexpected script structure.\n");exit(1);}
if(substr_count($html,'<title>')!==1 || substr_count($html,'rel="canonical"')!==1){fwrite(STDERR,"Duplicate SEO metadata.\n");exit(1);}

// Generation must remain approval-gated.
$empty=tempnam(sys_get_temp_dir(),'jt-g-empty-'); $emptyOut=$out.'/unapproved'; mkdir($emptyOut);
file_put_contents($empty,json_encode(['approvals'=>[]]));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($generator).' '.escapeshellarg($spec).' '.escapeshellarg($empty).' '.escapeshellarg($emptyOut); exec($cmd,$ignored,$emptyStatus);
if($emptyStatus!==0 || count(glob($emptyOut.'/*.html'))!==0){fwrite(STDERR,"Unapproved generation bypass detected.\n");exit(1);}

@unlink($spec); @unlink($approval); @unlink($empty); @unlink($file); @rmdir($emptyOut); @rmdir($out);
echo "Generated tool validation: PASS\n";
