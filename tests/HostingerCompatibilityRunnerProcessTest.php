<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class HostingerCompatibilityRunnerProcessTest extends TestCase {
    private string $runner;
    private string $root;
    protected function setUp(): void {
        $this->runner=__DIR__.'/validate-hostinger-compatibility.php';
        $this->root=sys_get_temp_dir().'/jt-hostinger-'.bin2hex(random_bytes(5));
        foreach(['config','security','tools','tests','storage/runtime','storage/logs','storage/cache'] as $d) mkdir($this->root.'/'.$d,0755,true);
    }
    protected function tearDown(): void {
        if(!is_dir($this->root)) return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f) $f->isDir()&&!$f->isLink()?rmdir($f->getPathname()):unlink($f->getPathname());
        rmdir($this->root);
    }
    private function executeRunner(string $scenario): array {
        $version=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $role=$version==='8.3'?'production':($version==='8.4'?'compatibility':'forward');
        $cmd=[PHP_BINARY,$this->runner,'--json','--php-version='.$version,'--php-role='.$role,'--root='.$this->root,'--no-cron'];
        if($scenario!=='') $cmd[]='--test-scenario='.$scenario;
        $pipes=[]; $env=array_merge($_ENV,['JUNCTIONTOOLS_TEST_MODE'=>'1']);
        $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        $this->assertIsResource($p);
        $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        return [$exit,trim($stdout),trim($stderr)];
    }
    public function testValidResultExitsZero(): void {
        [$exit,$out,$err]=$this->executeRunner('');
        $this->assertSame(0,$exit,$err); $json=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('PASS',$json['status']); $this->assertSame(0,$json['exit_code']);
    }
    public function testAcceptanceFailureExitsOne(): void {
        [$exit,$out,$err]=$this->executeRunner('acceptance-failure');
        $this->assertSame(1,$exit,$err); $json=json_decode($out,true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL',$json['status']); $this->assertSame(1,$json['exit_code']); $this->assertGreaterThan(0,$json['summary']['failed']);
    }
    public function testInvariantViolationExitsFour(): void {
        [$exit,$out,$err]=$this->executeRunner('invariant-violation');
        $this->assertSame(4,$exit); $this->assertStringContainsString('HOSTINGER_COMPATIBILITY_RUNNER_ERROR',$err);
    }
}