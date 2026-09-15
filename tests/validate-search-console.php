<?php
declare(strict_types=1);
$root=dirname(__DIR__); $tmp=sys_get_temp_dir().'/jt-gsc-'.bin2hex(random_bytes(4)); mkdir($tmp,0700,true);
$csv=$tmp.'/gsc.csv'; $feedback=$tmp.'/feedback.json'; $opps=$tmp.'/opps.json'; $queue=$tmp.'/queue.json';
file_put_contents($csv,"Query,Page,Clicks,Impressions,CTR,Position,Country,Device,Date\nAge Calculator,https://junctiontools.com/age-calculator,4,500,0.8,12.4,Pakistan,DESKTOP,2026-09-14\nnew utility,https://junctiontools.com/new,0,200,0,22,Pakistan,MOBILE,2026-09-14\n");
$run=static function(string $cmd): void { passthru(PHP_BINARY.' '.$cmd,$code); if($code!==0) exit($code); };
$run(escapeshellarg($root.'/tools/import-search-console.php').' '.escapeshellarg($csv).' '.escapeshellarg($feedback));
$data=json_decode((string)file_get_contents($feedback),true); if(!is_array($data)||count($data['rows']??[])!==2) {fwrite(STDERR,"Importer validation failed.\n");exit(1);}
$run(escapeshellarg($root.'/tools/build-search-console-feedback.php').' '.escapeshellarg($root.'/config/tools.json').' '.escapeshellarg($feedback).' '.escapeshellarg($opps));
$out=json_decode((string)file_get_contents($opps),true); if(!is_array($out)||count($out['opportunities']??[])!==2) {fwrite(STDERR,"Feedback builder validation failed.\n");exit(1);}
$first=$out['opportunities'][0]; if(($first['matched_tool_slug']??null)!=='age-calculator'||($first['opportunity']??'')!=='optimize-ranking') {fwrite(STDERR,"Existing-tool ranking opportunity failed.\n");exit(1);}
$second=$out['opportunities'][1]; if(($second['opportunity']??'')!=='new-tool-or-content-candidate') {fwrite(STDERR,"New opportunity classification failed.\n");exit(1);}
$run(escapeshellarg($root.'/tools/build-search-console-generation-queue.php').' '.escapeshellarg($opps).' '.escapeshellarg($root.'/config/tools.json').' '.escapeshellarg($queue));
$routed=json_decode((string)file_get_contents($queue),true); if(!is_array($routed)||count($routed['existing_tool_actions']??[])!==1||count($routed['new_tool_or_content_candidates']??[])!==1) {fwrite(STDERR,"Search Console routing validation failed.\n");exit(1);}
$action=$routed['existing_tool_actions'][0]; if(($action['target_tool_slug']??null)!=='age-calculator'||($action['action']??'')!=='optimize-ranking'||($action['automatic_change_allowed']??true)!==false) {fwrite(STDERR,"Existing-tool routing policy failed.\n");exit(1);}
$candidate=$routed['new_tool_or_content_candidates'][0]; if(($candidate['query']??'')!=='new utility'||($candidate['generation_eligible']??true)!==false||($candidate['automatic_publication_allowed']??true)!==false) {fwrite(STDERR,"New-candidate routing policy failed.\n");exit(1);}
if(($out['policy']??'')===''||($routed['policy']??'')==='') {fwrite(STDERR,"Publication policy missing.\n");exit(1);}
foreach([$csv,$feedback,$opps,$queue] as $file) @unlink($file); @rmdir($tmp); echo "Search Console feedback pipeline validation passed.\n";
