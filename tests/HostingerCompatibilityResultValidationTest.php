<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/validate-hostinger-compatibility.php';

final class HostingerCompatibilityResultValidationTest extends TestCase {
    private function checks(array $statuses,array $required): array {
        $out=[];
        foreach($statuses as $i=>$status) $out[]=['id'=>'check-'.($i+1),'category'=>'runtime','status'=>$status,'required'=>$required[$i],'message'=>'test'];
        return $out;
    }
    private function summary(array $checks): array {
        $s=['total'=>count($checks),'passed'=>0,'failed'=>0,'skipped'=>0];
        foreach($checks as $c) {
            $key = match ($c['status']) {
                'PASS' => 'passed',
                'FAIL' => 'failed',
                'SKIP' => 'skipped',
                default => throw new RuntimeException('Invalid check status.')
            };
            $s[$key]++;
        }
        return $s;
    }
    public function testPassContract(): void {
        $c=$this->checks(['PASS'],[true]);
        validateUniqueCheckIds($c); validateSummary($c,$this->summary($c)); validateRequiredHandling($c);
        validateOverallStatus($c,'PASS'); validateExitCode('PASS',0); $this->assertTrue(true);
    }
    public function testRequiredFailureContract(): void {
        $c=$this->checks(['FAIL'],[true]);
        validateUniqueCheckIds($c); validateSummary($c,$this->summary($c)); validateRequiredHandling($c);
        validateOverallStatus($c,'FAIL'); validateExitCode('FAIL',1); $this->assertTrue(true);
    }
    public function testOptionalFailureDoesNotFailOverall(): void {
        $c=$this->checks(['PASS','FAIL'],[true,false]);
        validateUniqueCheckIds($c); validateSummary($c,$this->summary($c)); validateOverallStatus($c,'PASS'); validateExitCode('PASS',0);
        $this->assertTrue(true);
    }
    public function testDuplicateIdIsInvariantViolation(): void {
        $c=$this->checks(['PASS','PASS'],[true,true]); $c[1]['id']=$c[0]['id'];
        $this->expectException(RuntimeException::class); validateUniqueCheckIds($c);
    }
    public function testRequiredSkipIsInvariantViolation(): void {
        $this->expectException(RuntimeException::class);
        validateRequiredHandling($this->checks(['SKIP'],[true]));
    }
}