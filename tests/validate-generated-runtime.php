<?php
declare(strict_types=1);

$root=dirname(__DIR__); $generator=$root.'/tools/generate-approved-tools.php';
if(!is_file($generator)){fwrite(STDERR,"Generator missing.\n");exit(1);}
$spec=tempnam(sys_get_temp_dir(),'jt-r-spec-'); $approval=tempnam(sys_get_temp_dir(),'jt-r-app-');
$out=sys_get_temp_dir().'/jt-runtime-'.bin2hex(random_bytes(4)); mkdir($out,0775,true);
$specData=['specifications'=>[
 ['spec_status'=>'draft','generation_eligible'=>false,'seo'=>['description'=>'Free age calculator.','title'=>'Age Calculator | Free Online Tool | JunctionTools'],'tool'=>['name'=>'Age Calculator','slug'=>'age-calculator','implementation_template'=>'date-age-calculator'],'content'=>['how_to_use'=>['Choose your date of birth.','Choose the calculation date.','Review the result.'],'use_cases'=>['Forms','Milestones'],'tips'=>['Use the correct date.','Review the result.']],'inputs'=>['fields'=>[['name'=>'birth_date','type'=>'date'],['name'=>'as_of_date','type'=>'date']]]],
 ['spec_status'=>'draft','generation_eligible'=>false,'seo'=>['description'=>'Free QR code generator.'],'tool'=>['name'=>'QR Code Generator','slug'=>'qr-code-generator','implementation_template'=>'qr-generator'],'content'=>['how_to_use'=>['Enter content.','Choose options.','Generate and download.'],'use_cases'=>['Links','Print materials'],'tips'=>['Test the code.','Use strong contrast.']],'inputs'=>['fields'=>[['name'=>'input','type'=>'text']]]]
]];
file_put_contents($spec,json_encode($specData)); file_put_contents($approval,json_encode(['approvals'=>[['slug'=>'age-calculator','approved'=>true],['slug'=>'qr-code-generator','approved'=>true]]]));
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($generator).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($out); exec($cmd,$lines,$status);
if($status!==0){fwrite(STDERR,"Generation failed.\n");exit(1);}
$files=glob($out.'/*.html') ?: [];
if(count($files)!==2){fwrite(STDERR,"Expected 2 generated artifacts, found ".count($files).".\n");exit(1);}
foreach($files as $file){
  $html=(string)file_get_contents($file);
  if(substr_count($html,'<html')!==1 || substr_count($html,'<script')<2){fwrite(STDERR,"Invalid document structure: {$file}\n");exit(1);}
  // Only the site's declared browser-side CDN dependencies are permitted.
  if(preg_match('/<script[^>]+src=["\'](?!https:\/\/(?:cdn\.tailwindcss\.com(?:\/|["\'])|cdn\.jsdelivr\.net(?:\/|["\'])))/i',$html)){fwrite(STDERR,"Unexpected external script detected.\n");exit(1);}
  foreach(['eval(','new Function(','document.write(','innerHTML =','fetch(','XMLHttpRequest','WebSocket(','localStorage','sessionStorage','cookie','formaction='] as $needle){if(stripos($html,$needle)!==false){fwrite(STDERR,"Unsafe runtime pattern {$needle} in {$file}.\n");exit(1);}}
  if(preg_match('/<(?:iframe|frame|source|video|audio)\b[^>]+src=["\'](?:https?:|\/\/)/i',$html)){fwrite(STDERR,"External network resource detected.\n");exit(1);}
  if(preg_match('/<img\b[^>]+src=["\'](?:https?:|\/\/)/i',$html)){fwrite(STDERR,"External image resource detected.\n");exit(1);}
  if(substr_count($html,'<title>')!==1 || substr_count($html,'rel="canonical"')!==1){fwrite(STDERR,"SEO metadata invalid.\n");exit(1);}
  if(strpos($html,'https://junctiontools.com/')===false || preg_match('/rel="canonical"[^>]+href="[^"]+\.html/i',$html)){fwrite(STDERR,"Clean canonical URL contract failed.\n");exit(1);}
  $titlePos=strpos($html,'<h1');$howPos=strpos($html,'How to Use');$formPos=strpos($html,'<form');$toolSectionPos=strpos($html,'id="run"');
  if($titlePos===false||$howPos===false||($formPos!==false&&$howPos>$formPos)||($toolSectionPos!==false&&$howPos>$toolSectionPos)){fwrite(STDERR,"How to Use is not before the tool form/action.\n");exit(1);}
  if(strpos($html,'id="header-container"')===false||strpos($html,'id="footer-container"')===false||strpos($html,'max-w-4xl')===false){fwrite(STDERR,"Shared JunctionTools layout markers missing.\n");exit(1);}
  if(strpos($html,'aria-live="polite"')===false && strpos($html,'<output')===false){fwrite(STDERR,"No accessible result region.\n");exit(1);}
}
$age=(string)file_get_contents($out.'/age-calculator.html');
foreach(['id="birth_date"','id="as_of_date"','Date.UTC('] as $needle){if(strpos($age,$needle)===false){fwrite(STDERR,"Age calculator runtime contract missing: {$needle}\n");exit(1);}}
$qr=(string)file_get_contents($out.'/qr-code-generator.html');
if(strpos($qr,'id="input"')===false || strpos($qr,'id="run"')===false){fwrite(STDERR,"QR generator runtime contract missing.\n");exit(1);}
@unlink($spec);@unlink($approval);foreach($files as $file)@unlink($file);@rmdir($out);
echo "Generated runtime validation: PASS\n";
