<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$approval = $root . '/config/tool-enhancement-approvals.json';
$registry = $root . '/config/tools.json';
$plan = sys_get_temp_dir() . '/junctiontools-enhancement-plan-' . bin2hex(random_bytes(4)) . '.json';

$beforeRegistry = hash_file('sha256', $registry);

passthru('php ' . escapeshellarg($root . '/tools/build-enhancement-deployment-plan.php') . ' ' . escapeshellarg($approval) . ' ' . escapeshellarg($plan), $exitCode);
if ($exitCode !== 0 || !is_file($plan)) {
    fwrite(STDERR, "FAIL: enhancement deployment plan did not compile\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($plan), true);
@unlink($plan);

if (!is_array($data) || ($data['plan_status'] ?? '') !== 'pending_publication_review' || ($data['publication_allowed'] ?? true) !== false) {
    fwrite(STDERR, "FAIL: plan must remain pending separate publication review\n");
    exit(1);
}

$enhancements = $data['enhancements'] ?? [];
if (count($enhancements) !== 1 || ($enhancements[0]['target_tool_slug'] ?? '') !== 'image-compressor') {
    fwrite(STDERR, "FAIL: expected exactly the approved image-compressor enhancement\n");
    exit(1);
}

$item = $enhancements[0];
if (($item['implementation_validation_approved'] ?? false) !== true || ($item['validation']['runtime_validation_required'] ?? false) !== true || ($item['validation']['human_publication_review_required'] ?? false) !== true) {
    fwrite(STDERR, "FAIL: enhancement validation gates are incomplete\n");
    exit(1);
}
if (($item['publication']['production_publish_allowed'] ?? true) !== false || ($item['publication']['registry_update_required'] ?? true) !== false || ($item['publication']['sitemap_update_required'] ?? true) !== false) {
    fwrite(STDERR, "FAIL: enhancement plan authorizes an unsafe publication or registry/sitemap mutation\n");
    exit(1);
}

if (hash_file('sha256', $registry) !== $beforeRegistry) {
    fwrite(STDERR, "FAIL: deployment-plan generation modified the production registry\n");
    exit(1);
}

fwrite(STDOUT, "PASS: approved Image Compressor/Image Resizer enhancement has a review-only deployment plan and remains blocked from publication.\n");
