<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$builder = $root . '/tools/build-deployment-plan.php';
if (!is_file($builder)) {
    fwrite(STDERR, "Deployment-plan builder missing.\n");
    exit(1);
}

$dir = sys_get_temp_dir() . '/jt-deploy-' . bin2hex(random_bytes(4));
mkdir($dir, 0775, true);
$spec = $dir . '/specs.json';
$approval = $dir . '/approvals.json';
$output = $dir . '/plan.json';

file_put_contents($spec, json_encode([
    'specifications' => [
        ['spec_status' => 'draft', 'generation_eligible' => false, 'tool' => ['name' => 'Age Calculator', 'slug' => 'age-calculator', 'implementation_template' => 'date-age-calculator']],
        ['spec_status' => 'draft', 'generation_eligible' => false, 'tool' => ['name' => 'QR Code Generator', 'slug' => 'qr-code-generator', 'implementation_template' => 'qr-generator']],
    ],
]));
file_put_contents($approval, json_encode([
    'policy' => ['default' => 'deny', 'publication_requires_separate_review' => true],
    'approvals' => [['slug' => 'age-calculator', 'approved' => true]],
]));

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($builder) . ' ' . escapeshellarg($spec) . ' ' . escapeshellarg($approval) . ' ' . escapeshellarg($output);
exec($cmd, $lines, $status);
if ($status !== 0 || !is_file($output)) {
    fwrite(STDERR, "Deployment plan compilation failed.\n");
    exit(1);
}

$plan = json_decode((string) file_get_contents($output), true);
if (!is_array($plan) || ($plan['plan_status'] ?? '') !== 'pending_publication_review' || ($plan['publication_allowed'] ?? true) !== false) {
    fwrite(STDERR, "Deployment plan is not publication-gated.\n");
    exit(1);
}
if (count($plan['tools'] ?? []) !== 1 || ($plan['tools'][0]['slug'] ?? '') !== 'age-calculator') {
    fwrite(STDERR, "Approved-tool filtering failed.\n");
    exit(1);
}
foreach ($plan['tools'][0]['validation'] ?? [] as $value) {
    if ($value !== true) {
        fwrite(STDERR, "Required validation gate disabled.\n");
        exit(1);
    }
}
if (($plan['tools'][0]['publication']['production_publish_allowed'] ?? true) !== false) {
    fwrite(STDERR, "Production publication was incorrectly enabled.\n");
    exit(1);
}

@unlink($spec);
@unlink($approval);
@unlink($output);
@rmdir($dir);
echo "Deployment plan validation: PASS\n";
