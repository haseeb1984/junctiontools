<?php
declare(strict_types=1);

if ($argc < 3) { fwrite(STDERR, "Usage: php tools/build-search-console-feedback.php <registry.json> <feedback.json> [output.json]\n"); exit(2); }
$registryFile=$argv[1]; $feedbackFile=$argv[2]; $output=$argv[3] ?? dirname(__DIR__).'/config/search-console-opportunities.json';
$registry=json_decode((string)file_get_contents($registryFile),true); $feedback=json_decode((string)file_get_contents($feedbackFile),true);
if (!is_array($registry)||!is_array($feedback)||!isset($registry['tools'])||!isset($feedback['rows'])) { fwrite(STDERR,"Invalid registry or feedback JSON.\n"); exit(1); }
$tools=$registry['tools']; $out=[];
$slugify=static fn(string $s): string => trim(preg_replace('/[^a-z0-9]+/i','-',strtolower($s)),'-');
foreach ($feedback['rows'] as $row) {
    if (!is_array($row)||trim((string)($row['query']??''))==='') continue;
    $query=trim((string)$row['query']); $q=$slugify($query); $match=null;
    foreach ($tools as $tool) { $name=$slugify((string)($tool['name']??'')); $slug=(string)($tool['slug']??''); if ($q===$name||$q===$slug||str_contains($q,$name)||str_contains($q,$slug)) { $match=$tool; break; } }
    $impressions=(float)($row['impressions']??0); $clicks=(float)($row['clicks']??0); $ctr=$row['ctr']===null?null:(float)$row['ctr']; $position=$row['position']===null?null:(float)$row['position'];
    $opportunity='observe'; $priority=0;
    if ($impressions>=100 && $position!==null && $position>=8 && $position<=30) { $opportunity='optimize-ranking'; $priority=80; }
    elseif ($impressions>=100 && $ctr!==null && $ctr<2 && $position!==null && $position<=10) { $opportunity='optimize-snippet'; $priority=75; }
    elseif ($impressions>=50 && $clicks===0) { $opportunity='investigate-no-click'; $priority=60; }
    if ($match===null && $impressions>=50) { $opportunity='new-tool-or-content-candidate'; $priority=max($priority,65); }
    if ($opportunity==='observe') continue;
    $out[]=['query'=>$query,'matched_tool_slug'=>$match['slug']??null,'matched_tool_name'=>$match['name']??null,'opportunity'=>$opportunity,'priority_score'=>$priority,'clicks'=>$clicks,'impressions'=>$impressions,'ctr'=>$ctr,'position'=>$position,'country'=>$row['country']??null,'device'=>$row['device']??null,'page'=>$row['page']??null,'source'=>'google-search-console'];
}
usort($out,static fn(array $a,array $b): int => ($b['priority_score']<=>$a['priority_score']) ?: ($b['impressions']<=>$a['impressions']) ?: strcasecmp($a['query'],$b['query']));
$result=['schema_version'=>'1.0.0','source'=>'google-search-console','generated_at'=>gmdate('Y-m-d'),'policy'=>'Feedback is advisory only. It never changes tools, registry, sitemap, approvals, or publication state automatically.','opportunities'=>$out];
if(file_put_contents($output,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)===false){fwrite(STDERR,"Unable to write output.\n");exit(1);} echo 'Built '.count($out)." Search Console opportunities.\n";
