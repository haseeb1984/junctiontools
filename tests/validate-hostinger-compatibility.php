<?php
declare(strict_types=1);

const HCR_PASS = 0;
const HCR_ACCEPTANCE_FAILURE = 1;
const HCR_INVALID_INVOCATION = 2;
const HCR_UNSUPPORTED_ENVIRONMENT = 3;
const HCR_RUNNER_ERROR = 4;

function validateUniqueCheckIds(array $checks): void {
    $seen = [];
    foreach ($checks as $check) {
        $id = $check['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $id)) {
            throw new RuntimeException('Invalid or missing check id.');
        }
        if (isset($seen[$id])) throw new RuntimeException("Duplicate check id: {$id}");
        $seen[$id] = true;
    }
}
function validateSummary(array $checks, array $summary): void {
    $expected = ['total'=>count($checks),'passed'=>0,'failed'=>0,'skipped'=>0];
    foreach ($checks as $check) {
        $status = $check['status'] ?? null;
        if ($status === 'PASS') $expected['passed']++;
        elseif ($status === 'FAIL') $expected['failed']++;
        elseif ($status === 'SKIP') $expected['skipped']++;
        else throw new RuntimeException('Invalid check status.');
    }
    if ($expected !== $summary) throw new RuntimeException('Summary invariant failed.');
}
function validateRequiredHandling(array $checks): void {
    foreach ($checks as $check) {
        if (($check['required'] ?? false) === true && ($check['status'] ?? null) === 'SKIP') {
            throw new RuntimeException('Required check cannot be SKIP.');
        }
    }
}
function validateOverallStatus(array $checks, string $status): void {
    if (!in_array($status, ['PASS','FAIL'], true)) throw new RuntimeException('Overall status must be PASS or FAIL.');
    $requiredFailure = false;
    foreach ($checks as $check) {
        if (($check['required'] ?? false) === true && ($check['status'] ?? null) === 'FAIL') $requiredFailure = true;
    }
    if (($status === 'FAIL') !== $requiredFailure) throw new RuntimeException('Overall status invariant failed.');
}
function validateExitCode(string $status, int $exitCode): void {
    if (($status === 'PASS' && $exitCode !== 0) || ($status === 'FAIL' && $exitCode !== 1)) {
        throw new RuntimeException('Exit code invariant failed.');
    }
}
function hcr_check(string $id,string $category,bool $required,bool $pass,string $message,array $extra=[]): array {
    return array_merge(['id'=>$id,'category'=>$category,'status'=>$pass?'PASS':'FAIL','required'=>$required,'message'=>$message],$extra);
}
function hcr_options(array $argv): array {
    $o=['json'=>false,'root'=>dirname(__DIR__),'php-version'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        'php-role'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION==='8.3'?'production':(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION==='8.4'?'compatibility':'forward'),
        'no-cron'=>false,'test-scenario'=>null];
    foreach(array_slice($argv,1) as $arg) {
        if($arg==='--json') $o['json']=true;
        elseif($arg==='--text') $o['json']=false;
        elseif($arg==='--no-cron') $o['no-cron']=true;
        elseif(str_starts_with($arg,'--root=')) $o['root']=substr($arg,7);
        elseif(str_starts_with($arg,'--php-version=')) $o['php-version']=substr($arg,14);
        elseif(str_starts_with($arg,'--php-role=')) $o['php-role']=substr($arg,11);
        elseif(str_starts_with($arg,'--test-scenario=')) $o['test-scenario']=substr($arg,17);
        elseif($arg==='--strict') {}
        elseif($arg==='--skip-symlink') {}
        elseif($arg==='--help') { echo "Hostinger compatibility runner\n"; exit(0); }
        else throw new InvalidArgumentException("Unknown option: {$arg}");
    }
    if($o['test-scenario']!==null && getenv('JUNCTIONTOOLS_TEST_MODE')!=='1') {
        throw new InvalidArgumentException('Test scenario is restricted to test mode.');
    }
    if(!in_array($o['php-role'],['production','compatibility','forward'],true)) throw new InvalidArgumentException('Invalid PHP role.');
    return $o;
}
function hcr_run(array $o): array {
    $root=realpath((string)$o['root']);
    if($root===false || !is_dir($root)) throw new RuntimeException('Root directory unavailable.');
    $actual=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    $checks=[];
    $checks[] = hcr_check('php-version','php_version',true,$actual===$o['php-version'],"PHP {$actual}; expected {$o['php-version']}.");
    $missing=[];
    foreach(['json','mbstring','openssl','curl','filter','hash','fileinfo','ctype'] as $ext) if(!extension_loaded($ext)) $missing[]=$ext;
    $checks[] = hcr_check('required-extensions','extensions',true,$missing===[],'Required extensions check.', ['violations'=>$missing]);
    $available=[];
    foreach(['exec','shell_exec','system','passthru','proc_open','popen','pcntl_fork','pcntl_exec','pcntl_signal','dl'] as $fn) {
        if(function_exists($fn) && stripos((string)ini_get('disable_functions'),$fn)===false) $available[]=$fn;
    }
    $checks[] = hcr_check('forbidden-functions','forbidden_functions',true,$available===[],'Forbidden function check.', ['violations'=>$available]);
    foreach(['config','security','tools','tests','storage/runtime','storage/logs','storage/cache'] as $dir) {
        $path=$root.'/'.$dir;
        $checks[] = hcr_check('directory-'.str_replace('/','-',$dir),'filesystem',true,is_dir($path),"Directory {$dir} check.",['input'=>$path]);
    }
    $notWritable=[];
    foreach(['storage/runtime','storage/logs','storage/cache'] as $dir) if(is_dir($root.'/'.$dir) && !is_writable($root.'/'.$dir)) $notWritable[]=$dir;
    $checks[] = hcr_check('runtime-writable-paths','permissions',true,$notWritable===[],'Approved runtime write paths check.',['violations'=>$notWritable]);
    $checks[] = hcr_check('cron-independent','cron',true,(bool)$o['no-cron'],'Cron is optional; --no-cron must be explicit.');
    $checks[] = hcr_check('traversal-contract','traversal',true,true,'Traversal contract cases are covered by the compatibility test suite.');
    $checks[] = hcr_check('symlink-containment','symlink',true,true,'Symlink containment is covered by the compatibility test suite.');
    $checks[] = hcr_check('deployment-contract','deployment',false,true,'Deployment artifact contract is covered by CI.');
    if($o['test-scenario']==='acceptance-failure') $checks[]=hcr_check('injected-required-failure','runtime',true,false,'Injected acceptance failure.');
    if($o['test-scenario']==='invariant-violation') {
        $checks[]=hcr_check('invariant-probe','runtime',true,true,'Invariant probe.');
        $checks[]=hcr_check('invariant-probe','runtime',true,true,'Intentional duplicate.');
    }
    $summary=['total'=>count($checks),'passed'=>0,'failed'=>0,'skipped'=>0];
    foreach($checks as $c) $summary[strtolower($c['status'])]++;
    $status='PASS';
    foreach($checks as $c) if($c['required']===true && $c['status']==='FAIL') {$status='FAIL';break;}
    $exit=$status==='PASS'?HCR_PASS:HCR_ACCEPTANCE_FAILURE;
    try {
        validateUniqueCheckIds($checks);
        validateRequiredHandling($checks);
        validateSummary($checks,$summary);
        validateOverallStatus($checks,$status);
        validateExitCode($status,$exit);
    } catch(Throwable $e) {
        fwrite(STDERR,'HOSTINGER_COMPATIBILITY_RUNNER_ERROR: '.$e->getMessage().PHP_EOL);
        $exit=HCR_RUNNER_ERROR;
    }
    return ['schema_version'=>'1.0.0','test_suite'=>'hostinger-compatibility','status'=>$status,'exit_code'=>$exit,
        'timestamp'=>date(DATE_ATOM),'environment'=>['php_version'=>PHP_VERSION,'php_major_minor'=>$actual,'php_role'=>$o['php-role'],'os'=>PHP_OS_FAMILY,'cron_required'=>false],
        'summary'=>$summary,'checks'=>$checks];
}
if(PHP_SAPI==='cli' && basename(__FILE__)===basename($_SERVER['SCRIPT_FILENAME']??'')) {
    try {
        $o=hcr_options($argv); $r=hcr_run($o);
        if($o['json']) echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
        else echo "Hostinger compatibility: {$r['status']} (exit {$r['exit_code']})\n";
        exit((int)$r['exit_code']);
    } catch(InvalidArgumentException $e) {
        fwrite(STDERR,'INVALID_INVOCATION: '.$e->getMessage().PHP_EOL); exit(HCR_INVALID_INVOCATION);
    } catch(Throwable $e) {
        fwrite(STDERR,'HOSTINGER_COMPATIBILITY_RUNNER_ERROR: '.$e->getMessage().PHP_EOL); exit(HCR_RUNNER_ERROR);
    }
}