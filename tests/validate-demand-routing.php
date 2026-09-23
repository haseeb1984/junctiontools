<?php
declare(strict_types=1);

/** Proves search demand for an existing tool is routed to enhancement, not new-tool generation. */
$root=dirname(__DIR__);
$script=$root.'/tools/build-generation-queue.php';
$registry=$root.'/config/tools.json';
if(!is_file($script)||!is_file($registry)){fwrite(STDERR,"Required routing files missing.\n");exit(3);}
$base=sys_get_temp_dir().'/jt-routing-'.bin2hex(random_bytes(4));
if(!mkdir($base,0700,true)){fwrite(STDERR,"Unable to create temp directory.\n");exit(3);}
$opportunities=[
 'opportunities'=>[
  ['query'=>'free online word counter','normalized_query'=>'free-online-word-counter','score'=>80,'search_volume'=>10000,'country'=>'US','language'=>'en','confidence'=>'high'],
  ['query'=>'unix timestamp converter','normalized_query'=>'unix-timestamp-converter','score'=>60,'search_volume'=>5000,'country'=>'US','language'=>'en','confidence'=>'medium']
 ]
];
file_put_contents($base.'/opportunities.json',json_encode($opportunities));
$out=$base.'/queue.json';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($base.'/opportunities.json').' '.escapeshellarg($registry).' '.escapeshellarg($out).' 2>&1';
exec($cmd,$lines,$status);
if($status!==0){fwrite(STDERR,"Routing script failed: ".implode(PHP_EOL,$lines)."\n");exit(1);}
$data=json_decode((string)file_get_contents($out),true);
if(!is_array($data)||!is_array($data['queue']??null)){fwrite(STDERR,"Invalid routing output.\n");exit(1);}
$word=null;$new=null;
foreach($data['queue'] as $row){if(($row['core_query']??'')==='free online word counter')$word=$row;if(($row['core_query']??'')==='unix timestamp converter')$new=$row;}
if(!is_array($word)||($word['decision']??'')!=='enhancement'||($word['target_tool_slug']??'')!=='word-counter'||($word['implementation']??'')!=='enhance-existing-tool'){fwrite(STDERR,"Word Counter demand was not routed to existing-tool enhancement.\n");exit(1);}
if(($word['enhancement_scope']['type']??'')!=='seo'||($word['enhancement_scope']['preserve_existing_functionality']??false)!==true){fwrite(STDERR,"Word Counter SEO enhancement scope is invalid.\n");exit(1);}
if(!is_array($new)||($new['decision']??'')!=='candidate'||($new['target_tool_slug']??null)!==null){fwrite(STDERR,"New capability was not kept as a new-tool candidate.\n");exit(1);}
echo "[PASS] Existing Word Counter demand routes to SEO enhancement.\n";
echo "[PASS] No duplicate new-tool route is produced for Word Counter.\n";
echo "[PASS] Unmatched demand remains a new-tool candidate.\n";
exit(0);
