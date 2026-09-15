<?php
declare(strict_types=1);

/**
 * Compile conservative, implementation-ready tool specifications from the
 * demand generation queue. This step creates a structured spec; it does not
 * generate, publish, or deploy executable tool code.
 */
if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/build-tool-specs.php <generation-queue.json> [output.json]\n");
    exit(2);
}
$input = $argv[1];
$output = $argv[2] ?? dirname(__DIR__) . '/config/generated-tool-specs.json';
if (!is_file($input)) { fwrite(STDERR, "Generation queue not found.\n"); exit(1); }
$data = json_decode((string)file_get_contents($input), true);
if (!is_array($data) || !is_array($data['queue'] ?? null)) { fwrite(STDERR, "Invalid generation queue.\n"); exit(1); }

$slugify = static fn(string $value): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-'));
$specs = [];
foreach ($data['queue'] as $entry) {
    if (!is_array($entry) || ($entry['decision'] ?? '') !== 'candidate') continue;
    $query = trim((string)($entry['core_query'] ?? ''));
    $slug = $slugify((string)($entry['recommended_slug'] ?? $query));
    if ($query === '' || $slug === '') continue;
    $lower = strtolower($query);

    $category = 'utility';
    $implementation = 'client_side';
    $inputContract = ['name'=>'input','type'=>'text','required'=>true,'validation'=>'non-empty'];
    $outputContract = ['name'=>'result','type'=>'text'];
    $privacy = 'Process data locally in the browser whenever technically feasible; do not upload user input by default.';
    $functional = ['valid input produces a deterministic result','invalid input produces an actionable validation message','reset clears user-entered data'];

    if (str_contains($lower, 'age calculator')) {
        $category='calculators'; $inputContract=['fields'=>[['name'=>'birth_date','type'=>'date','required'=>true],['name'=>'as_of_date','type'=>'date','required'=>true]]];
        $outputContract=['fields'=>['years','months','days','total_days']];
        $functional=['birth date must not be in the future','as-of date must not precede birth date','leap years and month lengths are handled correctly'];
    } elseif (str_contains($lower, 'qr code')) {
        $category='generators'; $inputContract=['fields'=>[['name'=>'content','type'=>'text','required'=>true],['name'=>'error_correction','type'=>'enum','required'=>true],['name'=>'size','type'=>'integer','required'=>true]]];
        $outputContract=['fields'=>['qr_canvas','png_download']];
        $functional=['empty content is rejected','size is constrained to a safe range','PNG export matches the rendered QR code'];
    } elseif (str_contains($lower, 'timestamp') || str_contains($lower, 'unix')) {
        $category='developer'; $inputContract=['fields'=>[['name'=>'value','type'=>'text','required'=>true],['name'=>'unit','type'=>'enum','required'=>true,'values'=>['seconds','milliseconds']]]];
        $outputContract=['fields'=>['timestamp','iso_datetime','local_datetime']];
        $functional=['seconds and milliseconds are distinguished explicitly','invalid timestamps are rejected','conversion is deterministic for the same input'];
    } elseif (str_contains($lower, 'calculator')) {
        $category='calculators';
        $outputContract=['fields'=>['calculation_result']];
        $functional=['numeric inputs are validated','division by zero or invalid mathematical domains are rejected where applicable'];
    } elseif (str_contains($lower, 'converter') || str_contains($lower, 'formatter') || str_contains($lower, 'generator')) {
        $category='developer';
        $outputContract=['fields'=>['result','copy_action']];
    }

    $specs[] = [
        'spec_version'=>'1.0.0',
        'spec_status'=>'draft',
        'generation_eligible'=>false,
        'source'=>[
            'cluster_id'=>(string)($entry['cluster_id'] ?? $slug),
            'query'=>$query,
            'rank'=>(int)($entry['rank'] ?? 0),
            'priority_score'=>(int)($entry['priority_score'] ?? 0),
            'demand_signal'=>(int)($entry['demand_signal'] ?? 0),
            'country'=>$entry['country'] ?? 'unspecified',
            'language'=>$entry['language'] ?? null,
            'confidence'=>$entry['confidence'] ?? 'low',
            'source_registry'=>$entry['source'] ?? 'unknown'
        ],
        'tool'=>[
            'name'=>ucwords(str_replace('-', ' ', $slug)),
            'slug'=>$slug,
            'category'=>$category,
            'implementation'=>$implementation,
            'page'=>'/' . $slug,
            'frontend'=>$slug . '.html'
        ],
        'purpose'=>'Provide a focused, fast, privacy-conscious browser utility for the query intent: ' . $query . '.',
        'inputs'=>$inputContract,
        'outputs'=>$outputContract,
        'ux'=>[
            'layout'=>'single-purpose tool with clear primary input and result area',
            'mobile'=>'responsive and keyboard accessible',
            'actions'=>['primary_action'=>'Run','secondary_action'=>'Reset','copy_or_download'=>true],
            'error_handling'=>'Inline, human-readable validation errors; never expose stack traces.'
        ],
        'seo'=>[
            'indexable'=>true,
            'canonical'=>'https://junctiontools.com/' . $slug,
            'title'=>ucwords(str_replace('-', ' ', $slug)) . ' | Free Online Tool | JunctionTools',
            'description'=>'Free ' . $query . ' with fast, privacy-conscious browser processing. No account required.'
        ],
        'privacy_security'=>[
            'processing'=>'browser_only',
            'network_requests'=>false,
            'external_dependencies'=>false,
            'privacy_note'=>$privacy,
            'security_requirements'=>['no eval or dynamic code execution','escape rendered user-controlled text','validate numeric and date ranges','do not persist sensitive input without explicit user action']
        ],
        'acceptance_criteria'=>$functional,
        'quality_gates'=>['syntax_validation','spec_schema_validation','functional_test','security_scan','seo_validation','manual_review_before_publish'],
        'publication_policy'=>'Draft specification only. Code generation, registry activation, sitemap publication, and deployment require later approval gates.'
    ];
}

$result=['schema_version'=>'1.0.0','generated_at'=>gmdate('Y-m-d'),'methodology'=>['purpose'=>'Compile demand candidates into structured implementation specifications before code generation.','automation_policy'=>'Specifications are drafts and never authorize code generation or publication by themselves.'],'specifications'=>$specs];
if (file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false) { fwrite(STDERR,"Unable to write output.\n"); exit(1); }
echo 'Built ' . count($specs) . " draft tool specifications.\n";
