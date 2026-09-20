<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security/build-security-gate.php';

/** Generate executable pages only for explicitly approved draft specifications. */
if($argc<3){fwrite(STDERR,"Usage: php tools/generate-approved-tools.php <specs.json> <approvals.json> [output-dir] [security-policy.json] [security-decisions.json]\n");exit(2);}
$specFile=$argv[1];$approvalFile=$argv[2];$outputDir=$argv[3]??dirname(__DIR__).'/generated-tools';
$securityPolicyFile=$argv[4]??dirname(__DIR__).'/config/build-security-gate.json';
$securityDecisionFile=$argv[5]??dirname(__DIR__).'/config/build-security-gate-decisions.json';
$root=dirname(__DIR__);
if(!is_file($specFile)||!is_file($approvalFile)){fwrite(STDERR,"Input file missing.\n");exit(1);}
$specs=json_decode((string)file_get_contents($specFile),true);$approvals=json_decode((string)file_get_contents($approvalFile),true);
if(!is_array($specs)||!is_array($specs['specifications']??null)||!is_array($approvals)||!is_array($approvals['approvals']??null)){fwrite(STDERR,"Invalid generator input.\n");exit(1);}
$approved=[];foreach($approvals['approvals'] as $item){if(!is_array($item))continue;$slug=strtolower(trim((string)($item['slug']??'')));if($slug!==''&&($item['approved']??false)===true)$approved[$slug]=true;}
$header=is_file($root.'/header.html')?(string)file_get_contents($root.'/header.html'):'';
$footer=is_file($root.'/footer.html')?(string)file_get_contents($root.'/footer.html'):'';
$footerScript='';
if($footer!==''){
 if(preg_match('/<script(?:\\s[^>]*)?>(.*?)<\\/script>/is',$footer,$m)){ $footerScript=(string)$m[1]; $footer=preg_replace('/<script(?:\\s[^>]*)?>.*?<\\/script>/is','',$footer,1)??$footer; }
}
if($header===''||$footer===''){fwrite(STDERR,"Shared JunctionTools header/footer are required.\n");exit(1);}
function esc(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function html_shell(string $slug,string $title,string $description,string $name,array $howTo,string $header,string $footer,string $footerScript,string $body,string $script):string{
 $steps='';foreach($howTo as $step)$steps.='<li>'.esc((string)$step).'</li>';
 return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>".esc($title)."</title>\n<meta name=\"description\" content=\"".esc($description)."\">\n<link rel=\"canonical\" href=\"https://junctiontools.com/".esc($slug)."\">\n<meta property=\"og:type\" content=\"website\">\n<meta property=\"og:url\" content=\"https://junctiontools.com/".esc($slug)."\">\n<meta property=\"og:title\" content=\"".esc($title)."\">\n<meta property=\"og:description\" content=\"".esc($description)."\">\n<link rel=\"icon\" type=\"image/x-icon\" href=\"favicon.ico\">\n<style>body{margin:0;background:#090d14;color:#f1f5f9;font-family:system-ui,sans-serif}.max-w-4xl{max-width:56rem}.mx-auto{margin-left:auto;margin-right:auto}.px-6{padding-left:1.5rem;padding-right:1.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.flex{display:flex}.flex-col{flex-direction:column}.flex-grow{flex-grow:1}.w-full{width:100%}.space-y-8>*+*{margin-top:2rem}.text-white{color:#fff}.text-slate-400{color:#94a3b8}.text-sm{font-size:.875rem}.text-3xl{font-size:1.875rem}.font-extrabold{font-weight:800}.tracking-tight{letter-spacing:-.025em}.mt-2{margin-top:.5rem}.p-5{padding:1.25rem}.rounded-xl{border-radius:.75rem}.border{border:1px solid #334155}.leading-relaxed{line-height:1.625}.space-y-1>*+*{margin-top:.25rem}.list-decimal{list-style-type:decimal}.list-inside{list-style-position:inside}.min-h-screen{min-height:100vh}button,input,select,textarea{font:inherit}.text-slate-200{color:#e2e8f0}.text-emerald-400{color:#34d399}</style>\n</head>\n<body class=\"bg-[#090d14] text-slate-100 min-h-screen flex flex-col font-sans\">\n".$header."\n<main class=\"max-w-4xl mx-auto px-6 py-12 flex-grow space-y-8 w-full\">\n<section>\n<h1 class=\"text-3xl font-extrabold text-white tracking-tight\">".esc($name)."</h1>\n<p class=\"text-slate-400 text-sm mt-2\">".esc($description)."</p>\n</section>\n<section class=\"bg-[#0f172a]/60 border border-emerald-900/40 rounded-xl p-5\">\n<h2 class=\"text-sm font-semibold text-slate-200 mb-2\"><i class=\"fa-solid fa-circle-info text-emerald-400 mr-2\"></i>How to Use</h2>\n<ol class=\"list-decimal list-inside text-sm text-slate-400 leading-relaxed space-y-1\">".$steps."</ol>\n</section>\n".$body."\n</main>\n".$footer."\n<script>\n".$script."\n</script>\n</body>\n</html>\n";
}
function jsName(string $name):string{return preg_replace('/[^A-Za-z0-9_]/','_',trim($name))?:'input';}
function renderFields(array $fields):string{$html='';foreach($fields as $field){if(!is_array($field))continue;$name=jsName((string)($field['name']??'input'));$label=ucwords(str_replace('_',' ',strtolower($name)));$type=(string)($field['type']??'text');if($type==='date')$input='<input id="'.$name.'" type="date" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none">';elseif($type==='integer')$input='<input id="'.$name.'" type="number" step="1" min="1" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none">';elseif($type==='enum'){ $input='<select id="'.$name.'" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none">';foreach(($field['values']??[]) as $value)$input.='<option value="'.esc((string)$value).'">'.esc(ucwords(str_replace('_',' ',(string)$value))).'</option>'; $input.='</select>';}else $input='<input id="'.$name.'" type="text" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none">';$html.='<div><label for="'.$name.'" class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">'.esc($label).'</label>'.$input.'</div>';}return $html;}
$authorizedSpecs=[];
foreach($specs['specifications'] as $spec){
 if(!is_array($spec))continue;
 $tool=$spec['tool']??[];
 $slug=strtolower(trim((string)($tool['slug']??'')));
 if($slug===''||!isset($approved[$slug]))continue;
 if(($spec['spec_status']??'')!=='draft'||($spec['generation_eligible']??true)!==false){fwrite(STDERR,"Refusing non-draft or generation-authorized spec: {$slug}\n");exit(1);}
 if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) { fwrite(STDERR,"Invalid tool slug: {$slug}\n"); exit(1); }
 $gate=bsg_load_and_evaluate($spec,$securityPolicyFile,$securityDecisionFile);
 if(($gate['allowed']??false)!==true){
   fwrite(STDERR, "Pre-build Security Gate rejected {$slug}: ".($gate['error_code']??'security_gate_rejected')." - ".($gate['message']??'Rejected.')."\n");
   exit(1);
 }
 $authorizedSpecs[]=$spec;
}
if(!is_dir($outputDir)&&!mkdir($outputDir,0775,true)&&!is_dir($outputDir)){fwrite(STDERR,"Unable to create output directory.\n");exit(1);}
$generated=0;
foreach($authorizedSpecs as $spec){$tool=$spec['tool']??[];$slug=strtolower(trim((string)($tool['slug']??'')));$name=(string)($tool['name']??ucwords(str_replace('-',' ',$slug)));$description=(string)($spec['seo']['description']??('Free '.$name.' tool.'));$title=(string)($spec['seo']['title']??($name.' | Free Online Tool | JunctionTools'));$template=(string)($tool['implementation_template']??'generic-form');$fields=$spec['inputs']['fields']??[];$howTo=$spec['content']['how_to_use']??[];
if(!is_array($howTo)||count($howTo)<3){fwrite(STDERR,"Missing user-facing How to Use content for {$slug}.\n");exit(1);}
if($template==='date-age-calculator'){
$body='<section class="bg-[#0f172a] border border-slate-800/80 p-6 rounded-2xl space-y-6 shadow-xl"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div><label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2" for="birth_date">Date of Birth</label><input id="birth_date" type="date" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none"></div><div><label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2" for="as_of_date">Calculate Age On</label><input id="as_of_date" type="date" class="w-full bg-[#090d14] border border-slate-800 rounded-lg p-3 text-sm text-white focus:border-emerald-500 focus:outline-none"></div></div><div class="flex gap-3"><button id="run" type="button" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Calculate Age</button><button id="reset" type="button" class="border border-slate-700 text-slate-300 px-4 py-2 rounded-lg text-sm font-semibold">Reset</button></div><p id="error" class="text-sm text-red-400"></p><section id="result" aria-live="polite" class="text-sm text-slate-300"></section></section>';
$script=<<<'JS'
(()=>{const b=document.getElementById('birth_date'),a=document.getElementById('as_of_date'),r=document.getElementById('result'),e=document.getElementById('error');a.value=new Date().toISOString().slice(0,10);function run(){e.textContent='';r.textContent='';if(!b.value||!a.value){e.textContent='Please select both dates.';return;}const bd=new Date(b.value+'T00:00:00'),ad=new Date(a.value+'T00:00:00');if(Number.isNaN(bd.getTime())||Number.isNaN(ad.getTime())){e.textContent='Please enter valid dates.';return;}if(bd>ad){e.textContent='Date of birth cannot be after the calculation date.';return;}let y=ad.getFullYear()-bd.getFullYear(),m=ad.getMonth()-bd.getMonth(),d=ad.getDate()-bd.getDate();if(d<0){m--;d+=new Date(ad.getFullYear(),ad.getMonth(),0).getDate();}if(m<0){y--;m+=12;}const total=Math.floor((Date.UTC(ad.getFullYear(),ad.getMonth(),ad.getDate())-Date.UTC(bd.getFullYear(),bd.getMonth(),bd.getDate()))/86400000);r.textContent=`${y} years, ${m} months, ${d} days (${total} total days).`;}document.getElementById('run').addEventListener('click',run);document.getElementById('reset').addEventListener('click',()=>{b.value='';a.value=new Date().toISOString().slice(0,10);e.textContent='';r.textContent='';});})();
JS;
}else{
if(!is_array($fields)||count($fields)===0)$fields=[['name'=>'input','type'=>'text','required'=>true]];
$body='<section class="bg-[#0f172a] border border-slate-800/80 p-6 rounded-2xl space-y-6 shadow-xl"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'.renderFields($fields).'</div><div class="flex gap-3"><button id="run" type="button" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg text-sm font-semibold">Run</button><button id="reset" type="button" class="border border-slate-700 text-slate-300 px-4 py-2 rounded-lg text-sm font-semibold">Reset</button></div><output id="result" aria-live="polite" class="block text-sm text-slate-300"></output></section>';
$fieldNames=array_map(static fn($f)=>jsName((string)($f['name']??'input')),array_filter($fields,'is_array'));$ids=json_encode(array_values($fieldNames),JSON_UNESCAPED_SLASHES);
$script="(()=>{const ids={$ids},r=document.getElementById('result');document.getElementById('run').addEventListener('click',()=>{const values=ids.map(id=>{const e=document.getElementById(id);return e?e.value.trim():'';});if(values.some(v=>!v)){r.textContent='Please complete the required inputs.';return;}r.textContent=values.join(' | ');});document.getElementById('reset').addEventListener('click',()=>{ids.forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});r.textContent='';});})();";
}
$html=html_shell($slug,$title,$description,$name,$howTo,$header,$footer,$footerScript,$body,$script);$path=rtrim($outputDir,'/\\').'/'.$slug.'.html';if(file_put_contents($path,$html,LOCK_EX)===false){fwrite(STDERR,"Unable to write {$path}.\n");exit(1);}$generated++;}
echo 'Generated '.$generated." approved tool page(s) using the shared JunctionTools layout. No registry or sitemap changes were made.\n";
