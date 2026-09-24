<?php
declare(strict_types=1);

/**
 * Compile conservative, implementation-ready tool specifications from the
 * demand generation queue. This step creates a structured spec; it does not
 * generate, publish, or deploy executable tool code.
 */
if ($argc < 2) { fwrite(STDERR, "Usage: php tools/build-tool-specs.php <generation-queue.json> [output.json]\n"); exit(2); }
$input=$argv[1]; $output=$argv[2] ?? dirname(__DIR__).'/config/generated-tool-specs.json';
if (!is_file($input)) { fwrite(STDERR,"Generation queue not found.\n"); exit(1); }
$data=json_decode((string)file_get_contents($input),true);
if (!is_array($data)||!is_array($data['queue']??null)){fwrite(STDERR,"Invalid generation queue.\n");exit(1);}
$slugify=static fn(string $v):string=>strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v)??'','-'));
$specs=[];
foreach($data['queue'] as $entry){
 if(!is_array($entry))continue;

 if(($entry['decision']??'')==='enhancement'){
  $query=trim((string)($entry['core_query']??''));
  $targetSlug=strtolower(trim((string)($entry['target_tool_slug']??'')));
  $scope=$entry['enhancement_scope']??null;
  if($query===''||$targetSlug===''||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$targetSlug)||!is_array($scope)||($scope['type']??'')!=='seo')continue;
  $specs[]=[
   'spec_version'=>'1.0.0',
   'spec_status'=>'draft',
   'generation_eligible'=>false,
   'spec_type'=>'enhancement',
   'source'=>[
    'cluster_id'=>(string)($entry['cluster_id']??$targetSlug),
    'query'=>$query,
    'rank'=>(int)($entry['rank']??0),
    'priority_score'=>(int)($entry['priority_score']??0),
    'demand_signal'=>(int)($entry['demand_signal']??0),
    'country'=>$entry['country']??'unspecified',
    'language'=>$entry['language']??null,
    'confidence'=>$entry['confidence']??'low',
    'source_registry'=>$entry['source']??'unknown'
   ],
   'tool'=>[
    'name'=>(string)($entry['existing_tool_match']??$targetSlug),
    'slug'=>$targetSlug,
    'implementation'=>'existing_tool_seo_content',
    'implementation_template'=>'existing-tool-seo-enhancement',
    'page'=>'/'.$targetSlug,
    'frontend'=>null
   ],
   'purpose'=>'Improve the existing JunctionTools page for the discovered search intent without creating a duplicate tool or changing core functionality.',
   'enhancement'=>[
    'type'=>'seo',
    'target_tool_slug'=>$targetSlug,
    'scope'=>[
     'description'=>(string)($scope['description']??'Improve the existing tool page for the discovered search intent without changing its core functionality.'),
     'requested_capabilities'=>array_values($scope['requested_capabilities']??['search-intent-aligned-title','meta-description','on-page-content','how-to-use-content']),
     'affected_components'=>array_values($scope['affected_components']??['content','seo']),
     'preserve_existing_functionality'=>true
    ],
    'content_requirements'=>[
     'title'=>'Align the existing page title with the discovered search intent.',
     'meta_description'=>'Align the existing meta description with the discovered search intent without keyword stuffing.',
     'on_page_content'=>'Add or refine useful explanatory copy that directly addresses the discovered search intent.',
     'how_to_use'=>'Ensure the existing tool has clear, intent-relevant How to Use guidance.'
    ]
   ],
   'seo'=>[
    'target_query'=>$query,
    'indexable'=>true,
    'must_preserve_existing_canonical'=>true,
    'must_preserve_existing_functionality'=>true
   ],
   'privacy_security'=>[
    'processing'=>'browser_only',
    'network_requests'=>false,
    'external_dependencies'=>false,
    'privacy_note'=>'SEO/content enhancement changes page presentation only; user tool input remains governed by the existing tool implementation.',
    'security_requirements'=>['no eval or dynamic code execution','do not alter existing tool JavaScript behavior','do not introduce external network requests','escape all generated content','do not change filesystem or registry boundaries']
   ],
   'security'=>[
    'no_new_page'=>true,
    'no_new_registry_entry'=>true,
    'no_sitemap_mutation'=>true,
    'no_runtime_code_generation'=>true,
    'no_external_network_dependency'=>true
   ],
   'quality_gates'=>[
    'target_tool_exists',
    'enhancement_approval',
    'security_gate_validation',
    'seo_validation',
    'content_validation',
    'functional_regression_test',
    'manual_review_before_publish'
   ],
   'publication_policy'=>'Draft enhancement only. Applying changes and publication require separate approval gates; this specification never authorizes duplicate tool generation or production publication.'
  ];
  continue;
 }

 if(($entry['decision']??'')!=='candidate')continue;
 $query=trim((string)($entry['core_query']??'')); $slug=$slugify((string)($entry['recommended_slug']??$query));
 if($query===''||$slug==='')continue; $lower=strtolower($query);
 $category='utility'; $implementation='client_side'; $template='generic-form';
 $inputContract=['name'=>'input','type'=>'text','required'=>true,'validation'=>'non-empty'];
 $outputContract=['name'=>'result','type'=>'text'];
 $privacy='Process data locally in the browser whenever technically feasible; do not upload user input by default.';
 $functional=['valid input produces a deterministic result','invalid input produces an actionable validation message','reset clears user-entered data'];
 $howTo=['Enter or select the required information.','Choose the available options that match your needs.','Run the tool and review the result before copying or downloading it.'];
 $useCases=['Quick everyday calculations or conversions','Preparing content or values for websites and digital work','Checking a result without creating an account'];
 $tips=['Use valid, complete input for the most accurate result.','Review the result before using it in production or sharing it.'];
 if(str_contains($lower,'age calculator')){
  $category='calculators'; $template='date-age-calculator';
  $inputContract=['fields'=>[['name'=>'birth_date','type'=>'date','required'=>true],['name'=>'as_of_date','type'=>'date','required'=>true]]];
  $outputContract=['fields'=>['years','months','days','total_days']];
  $functional=['birth date must not be in the future','as-of date must not precede birth date','leap years and month lengths are handled correctly'];
  $howTo=['Choose the date of birth.','Choose the date to calculate the age on, or use today’s date.','Review the exact age in years, months and days and the total elapsed days.'];
  $useCases=['Checking someone’s exact age for forms or applications','Calculating age for birthdays, milestones or eligibility checks','Finding the exact elapsed days between two dates'];
  $tips=['Use the person’s actual date of birth rather than an approximate year.','Change the calculation date when you need a historical or future age.'];
 }elseif(str_contains($lower,'qr code')){
  $category='generators'; $template='qr-generator';
  $inputContract=['fields'=>[['name'=>'content','type'=>'text','required'=>true],['name'=>'error_correction','type'=>'enum','required'=>true],['name'=>'size','type'=>'integer','required'=>true]]];
  $outputContract=['fields'=>['qr_canvas','png_download']];
  $functional=['empty content is rejected','size is constrained to a safe range','PNG export matches the rendered QR code'];
  $howTo=['Enter the text, URL or other content you want to encode.','Choose the error-correction level and output size.','Generate the QR code, scan it to verify the content, then download the PNG.'];
  $useCases=['Sharing website links without typing long URLs','Creating QR codes for menus, flyers, labels or signs','Generating a QR code locally without uploading the encoded content'];
  $tips=['Test the downloaded QR code with more than one device when it will be printed.','Use a clear foreground/background contrast for reliable scanning.'];
 }elseif(str_contains($lower,'timestamp')||str_contains($lower,'unix')){
  $category='developer'; $template='timestamp-converter';
  $inputContract=['fields'=>[['name'=>'value','type'=>'text','required'=>true],['name'=>'unit','type'=>'enum','required'=>true,'values'=>['seconds','milliseconds']]]];
  $outputContract=['fields'=>['timestamp','iso_datetime','local_datetime']];
  $functional=['seconds and milliseconds are distinguished explicitly','invalid timestamps are rejected','conversion is deterministic for the same input'];
  $howTo=['Enter the Unix timestamp value.','Choose whether the value is in seconds or milliseconds.','Run the conversion and copy the resulting date/time values.'];
  $useCases=['Debugging API and application logs','Converting Unix timestamps while working with databases or developer tools','Checking event times across systems'];
  $tips=['Confirm whether your source system uses seconds or milliseconds before converting.','Use the ISO result when you need an unambiguous machine-readable date.'];
 }elseif(str_contains($lower,'calculator')){
  $category='calculators'; $template='calculator-form';
  $outputContract=['fields'=>['calculation_result']];
  $functional=['numeric inputs are validated','division by zero or invalid mathematical domains are rejected where applicable'];
 }elseif(str_contains($lower,'converter')||str_contains($lower,'formatter')||str_contains($lower,'generator')){
  $category='developer'; $template='text-utility';
  $outputContract=['fields'=>['result','copy_action']];
 }
 $specs[]=[
  'spec_version'=>'1.0.0','spec_status'=>'draft','generation_eligible'=>false,
  'source'=>['cluster_id'=>(string)($entry['cluster_id']??$slug),'query'=>$query,'rank'=>(int)($entry['rank']??0),'priority_score'=>(int)($entry['priority_score']??0),'demand_signal'=>(int)($entry['demand_signal']??0),'country'=>$entry['country']??'unspecified','language'=>$entry['language']??null,'confidence'=>$entry['confidence']??'low','source_registry'=>$entry['source']??'unknown'],
  'tool'=>['name'=>ucwords(str_replace('-',' ',$slug)),'slug'=>$slug,'category'=>$category,'implementation'=>$implementation,'implementation_template'=>$template,'page'=>'/'.$slug,'frontend'=>$slug.'.html'],
  'purpose'=>'Provide a focused, fast, privacy-conscious browser utility for the query intent: '.$query.'.','inputs'=>$inputContract,'outputs'=>$outputContract,
  'content'=>['how_to_use'=>$howTo,'use_cases'=>$useCases,'tips'=>$tips],
  'ux'=>['layout'=>'standard JunctionTools layout: title and description, How to Use, then the primary tool form/result area','mobile'=>'responsive and keyboard accessible','actions'=>['primary_action'=>'Run','secondary_action'=>'Reset','copy_or_download'=>true],'error_handling'=>'Inline, human-readable validation errors; never expose stack traces.'],
  'seo'=>['indexable'=>true,'canonical'=>'https://junctiontools.com/'.$slug,'title'=>ucwords(str_replace('-',' ',$slug)).' | Free Online Tool | JunctionTools','description'=>'Free '.$query.' with fast, privacy-conscious browser processing. No account required.'],
  'privacy_security'=>['processing'=>'browser_only','network_requests'=>false,'external_dependencies'=>false,'privacy_note'=>$privacy,'security_requirements'=>['no eval or dynamic code execution','escape rendered user-controlled text','validate numeric and date ranges','do not persist sensitive input without explicit user action']],
  'acceptance_criteria'=>$functional,'quality_gates'=>['syntax_validation','spec_schema_validation','functional_test','security_scan','seo_validation','content_how_to_use_validation','manual_review_before_publish'],'publication_policy'=>'Draft specification only. Code generation, registry activation, sitemap publication, and deployment require later approval gates.'
 ];
}
$result=['schema_version'=>'1.0.0','generated_at'=>gmdate('Y-m-d'),'methodology'=>['purpose'=>'Compile demand candidates into new-tool specifications and existing-tool SEO/content enhancement specifications.','automation_policy'=>'Specifications are drafts and never authorize code generation, enhancement application, or publication by themselves. Existing capabilities are enhancement-only and never become duplicate new-tool specifications.'],'specifications'=>$specs];
if(file_put_contents($output,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL,LOCK_EX)===false){fwrite(STDERR,"Unable to write output.\n");exit(1);} echo 'Built '.count($specs)." draft tool specifications.\n";
