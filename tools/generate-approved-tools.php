<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/security/build-security-gate.php';

/** Generate executable pages only for explicitly approved draft specifications. */
if($argc<3){fwrite(STDERR,"Usage: php tools/generate-approved-tools.php <specs.json> <approvals.json> [output-dir] [security-policy.json] [security-decisions.json] [tools.json]\n");exit(2);}
$specFile=$argv[1];$approvalFile=$argv[2];$outputDir=$argv[3]??dirname(__DIR__).'/generated-tools';
$securityPolicyFile=$argv[4]??dirname(__DIR__).'/config/build-security-gate.json';
$securityDecisionFile=$argv[5]??dirname(__DIR__).'/config/build-security-gate-decisions.json';
$registryFile=$argv[6]??dirname(__DIR__).'/config/tools.json';
$root=dirname(__DIR__);
if(!is_file($specFile)||!is_file($approvalFile)||!is_file($registryFile)){fwrite(STDERR,"Input file missing.\n");exit(1);}
$specs=json_decode((string)file_get_contents($specFile),true);$approvals=json_decode((string)file_get_contents($approvalFile),true);$registry=json_decode((string)file_get_contents($registryFile),true);
if(!is_array($specs)||!is_array($specs['specifications']??null)||!is_array($approvals)||!is_array($approvals['approvals']??null)||!is_array($registry)||!is_array($registry['tools']??null)){fwrite(STDERR,"Invalid generator input.\n");exit(1);}
$approved=[];foreach($approvals['approvals'] as $item){if(!is_array($item))continue;$slug=strtolower(trim((string)($item['slug']??'')));if($slug!==''&&($item['approved']??false)===true)$approved[$slug]=true;}
$header=is_file($root.'/header.html')?(string)file_get_contents($root.'/header.html'):'';
$footer=is_file($root.'/footer.html')?(string)file_get_contents($root.'/footer.html'):'';
if($header===''||$footer===''){fwrite(STDERR,"Shared JunctionTools header/footer are required.\n");exit(1);}
function esc(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function html_shell(string $slug,string $title,string $description,string $name,array $howTo,string $header,string $footer,string $body,string $script):string{
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
 $duplicate=null; foreach($registry['tools'] as $registeredTool){ if(!is_array($registeredTool)) continue; $registeredSlug=strtolower(trim((string)($registeredTool['slug']??''))); $registeredName=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',(string)($registeredTool['name']??''))??'')); if($registeredSlug===$slug||$registeredName===$slug){$duplicate=$registeredTool;break;} } if($duplicate!==null){ fwrite(STDERR,"Duplicate existing tool rejected: {$slug} (existing slug: ".((string)($duplicate['slug']??$slug)).")\n"); exit(1); }
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
$html=html_shell($slug,$title,$description,$name,$howTo,$header,$footer,$body,$script);$path=rtrim($outputDir,'/\\').'/'.$slug.'.html';if(file_put_contents($path,$html,LOCK_EX)===false){fwrite(STDERR,"Unable to write {$path}.\n");exit(1);}$generated++;}

/**
 * Build staged navigation/content updates for newly generated tools.
 * Production/shared files are never modified here; updated copies are written
 * beside the generated tool pages and must pass approval before publication.
 */
function nav_esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function nav_icon(array $spec): string {
    $icon = trim((string)($spec['ui']['icon'] ?? $spec['tool']['icon'] ?? 'fa-screwdriver-wrench'));
    return preg_match('/^fa-[a-z0-9-]+$/', $icon) ? $icon : 'fa-screwdriver-wrench';
}
function nav_short_description(array $spec): string {
    $value = trim((string)($spec['seo']['short_description'] ?? $spec['seo']['description'] ?? 'New JunctionTools utility'));
    return $value !== '' ? $value : 'New JunctionTools utility';
}
function nav_new_tools(array $specs, array $approved): array {
    $tools = [];
    foreach ($specs['specifications'] ?? [] as $spec) {
        if (!is_array($spec)) continue;
        $slug = strtolower(trim((string)($spec['tool']['slug'] ?? '')));
        if ($slug === '' || !isset($approved[$slug])) continue;
        $tools[$slug] = [
            'slug' => $slug,
            'name' => (string)($spec['tool']['name'] ?? ucwords(str_replace('-', ' ', $slug))),
            'description' => nav_short_description($spec),
            'icon' => nav_icon($spec),
        ];
    }
    return array_values($tools);
}
function nav_link(array $tool): string {
    return '<a href="'.nav_esc($tool['slug']).'">'.nav_esc($tool['name']).'</a>';
}
function nav_card(array $tool): string {
    return '<a href="'.nav_esc($tool['slug']).'" class="tool-card bg-[#0f172a]/80 border border-emerald-900/30 hover:border-emerald-500 p-4 rounded-xl flex items-center space-x-4 transition group"><div class="bg-emerald-500/10 text-emerald-400 p-3 rounded-lg group-hover:bg-emerald-500 group-hover:text-black transition"><i class="fa-solid '.nav_esc($tool['icon']).' text-lg"></i></div><div><h3 class="font-semibold text-white group-hover:text-emerald-400 transition">'.nav_esc($tool['name']).'</h3><p class="text-xs text-slate-400">'.nav_esc($tool['description']).'</p></div></a>';
}
function update_shared_header(string $html, array $tools, int $totalTools): string {
    if (!$tools) return $html;
    $html = preg_replace('/All \d+ Tools/', 'All '.$totalTools.' Tools', $html, 1);
    $links = '';
    foreach ($tools as $tool) $links .= nav_link($tool);
    $desktop = '<div class="relative group"><button class="nav-trigger">New Tools <i class="fa-solid fa-chevron-down text-[9px]"></i></button><div class="dropdown">'.$links.'</div></div>';
    if (str_contains($html, '<button class="nav-trigger">New Tools')) {
        $html = preg_replace('/<div class="relative group"><button class="nav-trigger">New Tools.*?<\/div><\/div>/s', $desktop, $html, 1);
    } else {
        $needle = '</nav>';
        if (!str_contains($html, $needle)) throw new RuntimeException('Header desktop navigation container missing.');
        $html = str_replace($needle, $desktop.$needle, $html, $count);
        if ($count !== 1) throw new RuntimeException('Header desktop navigation could not be updated.');
    }
    $mobileLinks = '';
    foreach ($tools as $tool) $mobileLinks .= nav_link($tool);
    $mobile = '<div><p class="font-bold text-slate-200 mb-1">New Tools</p><div class="grid gap-2 text-slate-400">'.$mobileLinks.'</div></div>';
    if (str_contains($html, '<p class="font-bold text-slate-200 mb-1">New Tools</p>')) {
        $html = preg_replace('/<div><p class="font-bold text-slate-200 mb-1">New Tools<\/p><div class="grid gap-2 text-slate-400">.*?<\/div><\/div>/s', $mobile, $html, 1);
    } else {
        $needle = '</div>\n  </div>\n  <button id="mobile-menu-btn"';
        $pos = strpos($html, $needle);
        if ($pos === false) throw new RuntimeException('Header mobile navigation container missing.');
        $before = substr($html, 0, $pos);
        $after = substr($html, $pos);
        $before .= $mobile."\n        ";
        $html = $before.$after;
    }
    return $html;
}
function update_shared_footer(string $html, array $tools): string {
    if (!$tools) return $html;
    $items = '';
    foreach ($tools as $tool) $items .= '<li>'.nav_link($tool).'</li>';
    $column = '<div class="space-y-2"><h4 class="font-bold text-white text-sm">New Tools</h4><ul class="space-y-1.5">'.$items.'</ul></div>';
    if (str_contains($html, '<h4 class="font-bold text-white text-sm">New Tools</h4>')) {
        $html = preg_replace('/<div class="space-y-2"><h4 class="font-bold text-white text-sm">New Tools<\/h4><ul class="space-y-1\.5">.*?<\/ul><\/div>/s', $column, $html, 1);
    } else {
        $needle = '</div>\n    <div class="flex flex-col md:flex-row';
        if (!str_contains($html, $needle)) throw new RuntimeException('Footer tool navigation container missing.');
        $html = str_replace($needle, $column."\n    ".$needle, $html, $count);
        if ($count !== 1) throw new RuntimeException('Footer navigation could not be updated.');
    }
    return $html;
}
function update_index_page(string $html, array $tools, int $totalTools): string {
    if (!$tools) return $html;
    $html = preg_replace('/Access \d+ completely free online tools/', 'Access '.$totalTools.' completely free online tools', $html, 1);
    $cards = '';
    foreach ($tools as $tool) $cards .= nav_card($tool);
    $pattern = '/(<!-- CATEGORY 6: Newly Published Tools -->.*?<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">).*?(<\/div>\s*<\/section>)/s';
    if (!preg_match($pattern, $html)) {
        throw new RuntimeException('Index Newly Published Tools section is missing.');
    }
    return preg_replace($pattern, '$1'.$cards.'$2', $html, 1);
}

$newTools = nav_new_tools($specs, $approved);
if ($newTools) {
    $totalTools = count($registry['tools']) + count($newTools);
    $stagedHeader = update_shared_header($header, $newTools, $totalTools);
    $stagedFooter = update_shared_footer($footer, $newTools);
    foreach ($newTools as $newTool) {
        $toolPage = rtrim($outputDir,'/\\') . '/' . $newTool['slug'] . '.html';
        if (!is_file($toolPage)) {
            throw new RuntimeException('Generated page missing while applying staged navigation: ' . $newTool['slug']);
        }
        $toolHtml = (string) file_get_contents($toolPage);
        $toolHtml = str_replace($header, $stagedHeader, $toolHtml);
        $toolHtml = str_replace($footer, $stagedFooter, $toolHtml);
        if (file_put_contents($toolPage, $toolHtml, LOCK_EX) === false) {
            throw new RuntimeException('Unable to apply staged navigation to generated page: ' . $newTool['slug']);
        }
    }
    $indexPath = $root.'/index.html';
    if (!is_file($indexPath)) {
        fwrite(STDERR, "Index page is required for new-tool publication staging.\n");
        exit(1);
    }
    $stagedIndex = update_index_page((string)file_get_contents($indexPath), $newTools, $totalTools);
    foreach (['header.html'=>$stagedHeader,'footer.html'=>$stagedFooter,'index.html'=>$stagedIndex] as $relative=>$content) {
        $target = rtrim($outputDir,'/\\').'/'.$relative;
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            fwrite(STDERR, "Unable to write staged site file: {$relative}\n");
            exit(1);
        }
    }
}

echo 'Generated '.$generated." approved tool page(s) using the shared JunctionTools layout. Registry, sitemap, header, footer, and index remain unchanged; staged navigation files are emitted for approval.\n";
