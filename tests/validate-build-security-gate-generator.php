<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$generator=$root.'/tools/generate-approved-tools.php';
$gate=$root.'/security/build-security-gate.php';
$policyFile=$root.'/config/build-security-gate.json';

if(!is_file($generator)||!is_file($gate)||!is_file($policyFile)){fwrite(STDERR,"Security gate test prerequisites missing.\n");exit(1);}
require_once $gate;

$base=sys_get_temp_dir().'/jt-security-gate-'.bin2hex(random_bytes(5));
mkdir($base,0700,true);

function writeJson(string $path,array $value):void {
    if(file_put_contents($path,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL)===false) throw new RuntimeException("Unable to write {$path}");
}
function validSpec():array {
    return [
        'schema_version'=>'1.0.0','spec_version'=>'1.0.0','spec_status'=>'draft','generation_eligible'=>false,
        'source'=>['cluster_id'=>'security-test','query'=>'security test','rank'=>1],
        'tool'=>['name'=>'Security Test','slug'=>'security-test','category'=>'utility','implementation'=>'client_side','implementation_template'=>'generic-form','page'=>'/security-test','frontend'=>'security-test.html'],
        'purpose'=>'Safe browser-only test utility.',
        'inputs'=>['fields'=>[['name'=>'input','type'=>'text','required'=>true]]],
        'outputs'=>['fields'=>['result']],
        'content'=>['how_to_use'=>['Enter a value.','Run the tool.','Review the result.']],
        'seo'=>['indexable'=>true,'canonical'=>'https://junctiontools.com/security-test','title'=>'Security Test | Free Online Tool | JunctionTools','description'=>'A safe browser-only security test.'],
        'privacy_security'=>[
            'processing'=>'browser_only','network_requests'=>false,'external_dependencies'=>false,
            'privacy_note'=>'Input stays in the browser.',
            'security_requirements'=>['no eval or dynamic code execution','escape rendered user-controlled text']
        ],
        'acceptance_criteria'=>['valid input produces a result'],
        'quality_gates'=>['syntax_validation','security_scan'],
        'publication_policy'=>'Draft only.'
    ];
}
function validDecision(array $spec,array $policy):array {
    return [
        'schema_version'=>'1.0.0','policy_version'=>$policy['policy_version'],
        'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'evaluator_id'=>'build-security-gate-v1',
        'source'=>['opportunity_id'=>'security-test','specification_slug'=>$spec['tool']['slug']],
        'type'=>'new_tool',
        'decision'=>'allow','safe_to_build'=>true,
        'conditions'=>[
            'valid_spec'=>true,'safe_slug'=>true,'approved_template'=>true,
            'no_dynamic_code_execution'=>true,'no_credential_handling'=>true,
            'no_unsafe_filesystem_access'=>true,'no_arbitrary_url_fetch'=>true,
            'no_unapproved_external_network_dependency'=>true,
            'bounded_resource_usage'=>true,'privacy_contract_present'=>true
        ],
        'blocked_conditions'=>[],
        'approval_requirements'=>['generation_approval'=>true,'enhancement_approval'=>false],
        'evidence'=>['spec_sha256'=>bsg_sha256($spec),'policy_sha256'=>bsg_sha256($policy)]
    ];
}
function runGenerator(string $generator,string $spec,string $approval,string $output,string $policy,string $decision):array {
    $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($generator).' '.escapeshellarg($spec).' '.escapeshellarg($approval).' '.escapeshellarg($output).' '.escapeshellarg($policy).' '.escapeshellarg($decision);
    $lines=[];$status=0;exec($cmd,$lines,$status);
    return [$status,implode("\n",$lines)];
}
function assertTrue(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException($message);
}

$policy=json_decode((string)file_get_contents($policyFile),true,512,JSON_THROW_ON_ERROR);
$spec=validSpec();
$specFile=$base.'/spec.json'; $approvalFile=$base.'/approval.json'; $decisionFile=$base.'/decision.json';
writeJson($specFile,['specifications'=>[$spec]]);
writeJson($approvalFile,['schema_version'=>'1.0.0','policy'=>['default'=>'deny'],'approvals'=>[['slug'=>'security-test','approved'=>true,'approved_by'=>'ci-test','approved_at'=>'2026-09-20T00:00:00Z']]]);
writeJson($decisionFile,['schema_version'=>'1.0.0','policy_version'=>$policy['policy_version'],'decisions'=>[validDecision($spec,$policy)]]);
[$status]=$okRun=runGenerator($generator,$specFile,$approvalFile,$base.'/positive',$policyFile,$decisionFile);
assertTrue($status===0,'Valid security-gated generation did not pass.');
assertTrue(is_file($base.'/positive/security-test.html'),'Valid generation did not produce output.');

$cases=[
    'missing decision'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions']=[];writeJson($base.'/missing.json',$d);
        $out=$base.'/missing-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/missing.json');
        assertTrue($s!==0,'Missing decision bypassed the gate.');assertTrue(!is_dir($out),'Missing decision created output directory.');
    },
    'explicit reject'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions'][0]['decision']='reject';$d['decisions'][0]['safe_to_build']=false;$d['decisions'][0]['blocked_conditions']=['ssrf_risk'];writeJson($base.'/reject.json',$d);
        $out=$base.'/reject-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/reject.json');
        assertTrue($s!==0,'Explicit security rejection was bypassed.');assertTrue(!is_dir($out),'Rejected build created output directory.');
    },
    'blocked condition with allow'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions'][0]['blocked_conditions']=['arbitrary_url_fetch'];writeJson($base.'/blocked.json',$d);
        $out=$base.'/blocked-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/blocked.json');
        assertTrue($s!==0,'Contradictory allow decision bypassed blocked-condition enforcement.');
    },
    'mandatory condition false'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions'][0]['conditions']['no_arbitrary_url_fetch']=false;writeJson($base.'/condition.json',$d);
        $out=$base.'/condition-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/condition.json');
        assertTrue($s!==0,'Failed mandatory condition bypassed the gate.');
    },
    'stale spec approval'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $changed=validSpec();$changed['purpose']='Modified after approval.';writeJson($base.'/changed-spec.json',['specifications'=>[$changed]]);
        $out=$base.'/stale-spec-output';[$s]=runGenerator($generator,$base.'/changed-spec.json',$approvalFile,$out,$policyFile,$decisionFile);
        assertTrue($s!==0,'Stale spec approval was accepted.');
    },
    'stale policy approval'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $changed=$policy;$changed['policy_version']='2.0.0';writeJson($base.'/changed-policy.json',$changed);
        $out=$base.'/stale-policy-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$base.'/changed-policy.json',$decisionFile);
        assertTrue($s!==0,'Stale policy approval was accepted.');
    },
    'unknown evaluator'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions'][0]['evaluator_id']='untrusted-evaluator';writeJson($base.'/evaluator.json',$d);
        $out=$base.'/evaluator-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/evaluator.json');
        assertTrue($s!==0,'Unknown evaluator bypassed the gate.');
    },
    'future timestamp'=>function()use($base,$generator,$specFile,$approvalFile,$policyFile,$decisionFile){
        $d=json_decode((string)file_get_contents($decisionFile),true);$d['decisions'][0]['evaluated_at']='2099-01-01T00:00:00Z';writeJson($base.'/future.json',$d);
        $out=$base.'/future-output';[$s]=runGenerator($generator,$specFile,$approvalFile,$out,$policyFile,$base.'/future.json');
        assertTrue($s!==0,'Future-dated security approval was accepted.');
    },
    'unapproved template'=>function()use($base,$generator,$approvalFile,$policyFile){
        $bad=validSpec();$bad['tool']['implementation_template']='arbitrary-php-exec';$badSpec=$base.'/bad-template-spec.json';writeJson($badSpec,['specifications'=>[$bad]]);
        $d=['schema_version'=>'1.0.0','policy_version'=>$policy['policy_version']??'1.0.0','decisions'=>[validDecision($bad,$policy)]];writeJson($base.'/bad-template-decision.json',$d);
        $out=$base.'/bad-template-output';[$s]=runGenerator($generator,$badSpec,$approvalFile,$out,$policyFile,$base.'/bad-template-decision.json');
        assertTrue($s!==0,'Unapproved implementation template bypassed the gate.');
    },
    'unsafe privacy contract'=>function()use($base,$generator,$approvalFile,$policyFile,$policy){
        $bad=validSpec();$bad['privacy_security']['network_requests']=true;$badSpec=$base.'/bad-privacy-spec.json';writeJson($badSpec,['specifications'=>[$bad]]);
        $d=['schema_version'=>'1.0.0','policy_version'=>$policy['policy_version'],'decisions'=>[validDecision($bad,$policy)]];writeJson($base.'/bad-privacy-decision.json',$d);
        $out=$base.'/bad-privacy-output';[$s]=runGenerator($generator,$badSpec,$approvalFile,$out,$policyFile,$base.'/bad-privacy-decision.json');
        assertTrue($s!==0,'Unsafe privacy/network contract bypassed the gate.');
    }
];
foreach($cases as $name=>$case){$case();echo "PASS: {$name}\n";}

function removeTree(string $dir):void {
    if(!is_dir($dir))return;
    $items=scandir($dir);if($items===false)return;
    foreach($items as $item){if($item==='.'||$item==='..')continue;$path=$dir.'/'.$item;if(is_dir($path)&&!is_link($path))removeTree($path);else@unlink($path);}
    @rmdir($dir);
}
removeTree($base);
echo "Pre-build Security Gate generator enforcement: PASS\n";
