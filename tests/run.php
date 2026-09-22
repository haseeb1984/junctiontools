<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$options = getopt('', [
    'suite:',
    'report:',
    'slow-url:',
    'large-url:',
    'contact-url:',
]);

$suite = (string)($options['suite'] ?? 'all');
$reportPath = isset($options['report']) ? (string)$options['report'] : null;
$baseUrl = rtrim((string)(getenv('JUNCTIONTOOLS_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$fixtureBase = $baseUrl . '/tests/fixtures';

function jt_run_http(string $url, string $method = 'GET', ?string $body = null, array $headers = []): array
{
    return jt_test_http($url, $method, $body, $headers);
}

function jt_run_expect_json(array $response): array
{
    jt_test_assert($response['status'] >= 200 && $response['status'] < 500, 'Unexpected HTTP status: ' . $response['status']);
    $json = jt_test_json($response['body']);
    jt_test_assert(is_array($json), 'Response body is not a JSON object.');
    return $json;
}

function jt_suite_compatibility(): array
{
    $results = [];
    $clientTools = [
        'PageSpeed Analyzer'=>'pagespeed.html','Image Compressor'=>'asset-optimizer.html','GA4 Event Code Generator'=>'ga4-verifier.html','CSS/JS Minifier'=>'css-js-minifier.html','Invoice Generator'=>'invoice-generator.html','Discount Calculator'=>'discount-calculator.html','Aspect Ratio Calculator'=>'aspect-ratio-calculator.html','Color Palette Generator'=>'color-palette-generator.html','Social Asset Optimizer'=>'social-asset-optimizer.html','Bio Font Generator'=>'bio-font-generator.html','Clean Text Tool'=>'clean-text.html','Case Converter'=>'case-converter.html','Word Counter'=>'word-counter.html','PX to REM'=>'px-to-rem.html','Base64 Converter'=>'base64-converter.html','JSON Formatter'=>'json-formatter.html','Regex Tester'=>'regex-tester.html','SHA-256 Hash'=>'sha256-hash.html','UUID Generator'=>'uuid-generator.html','WhatsApp Link'=>'whatsapp-direct.html'
    ];
    foreach ($clientTools as $name=>$file) {
        jt_test_case($results, "35-tool compatibility: {$name}", static function() use ($file): void {
            $path=JT_TEST_ROOT.'/'.$file;
            jt_test_assert(is_file($path), "Missing {$file}.");
            $html=file_get_contents($path);
            jt_test_assert(is_string($html)&&trim($html)!=='', "{$file} is empty.");
            jt_test_assert(stripos($html,'<html')!==false||stripos($html,'<!doctype')!==false, "{$file} is not HTML.");
        });
    }
    $scannerTools=[
        'Pixel Diagnostic'=>['pixels','url'],'ARIA & Alt Audit'=>['aria_audit','url'],'Mobile Viewport UX'=>['mobile_audit','url'],'UX Layout Evaluator'=>['ux_evaluator','url'],'Cross-Browser Check'=>['browser_checker','url'],'Meta SEO Checker'=>['seo_auditor','url'],'Schema Validator'=>['schema_validator','raw'],'Checkout Funnel Analyzer'=>['friction_analyzer','calc'],'Cart Loss Calculator'=>['cart_abandonment','calc'],'CTA & Headline Analyzer'=>['cta_analyzer','url'],'Trust Badge Inspector'=>['trust_inspector','url'],'SSL & Security Checker'=>['ssl_audit','url'],'Contrast Checker'=>['wcag_checker','url'],'Product Copy Analyzer'=>['copy_analyzer','url'],'Readability Evaluator'=>['readability_evaluator','url']
    ];
    foreach($scannerTools as $name=>[$type,$mode]){
        jt_test_case($results,"35-tool compatibility: {$name}",static function() use($name,$type,$mode):void{
            $payload=['type'=>$type];
            if($type==='cart_abandonment') $payload+=['total_carts'=>100,'completed_orders'=>80,'aov'=>50];
            elseif($type==='friction_analyzer') $payload+=['checkout_type'=>'1step','required_fields'=>5,'guest_option'=>'enabled'];
            elseif($type==='schema_validator') $payload['payload']='{"@context":"https://schema.org","@type":"Product","name":"Test"}';
            else $payload['url']='https://junctiontools.com/';
            $response=jt_run_http(jt_test_scanner_url().'/scanner.php','POST',json_encode($payload),['Content-Type: application/json']);
            jt_test_assert(in_array($response['status'],[200,400,422],true),"{$name} returned HTTP {$response['status']}.");
            $json=jt_run_expect_json($response);
            jt_test_assert(array_key_exists('success',$json),"{$name} response lacks success field.");

            if ($type === 'trust_inspector') {
                jt_test_assert($json['success'] === true, 'Trust Badge Inspector did not succeed.');
                jt_test_assert($json['url'] === 'https://junctiontools.com/', 'Trust Badge Inspector did not analyze the requested URL.');
                jt_test_assert(is_int($json['confidenceIndex']) && $json['confidenceIndex'] >= 0 && $json['confidenceIndex'] <= 100, 'Trust confidenceIndex is invalid.');
                jt_test_assert(is_int($json['activeElements']) && $json['activeElements'] >= 0 && $json['activeElements'] <= 4, 'Trust activeElements is invalid.');
                jt_test_assert(is_array($json['detectedElements']), 'Trust detectedElements is missing.');
            }

            if ($type === 'wcag_checker') {
                jt_test_assert($json['success'] === true, 'Contrast Checker did not succeed.');
                jt_test_assert($json['url'] === 'https://junctiontools.com/', 'Contrast Checker did not analyze the requested URL.');
                jt_test_assert(is_int($json['colorsDetected']) && $json['colorsDetected'] >= 0, 'Contrast colorsDetected is invalid.');
                jt_test_assert(is_int($json['contrastPairsAnalyzed']) && $json['contrastPairsAnalyzed'] >= 0, 'Contrast pair count is invalid.');
                jt_test_assert(array_key_exists('bestContrastRatio', $json), 'Contrast bestContrastRatio field is missing.');
                jt_test_assert(is_array($json['pairs']), 'Contrast pairs field is missing.');
            }

            if ($type === 'copy_analyzer') {
                jt_test_assert($json['success'] === true, 'Product Copy Analyzer did not succeed.');
                jt_test_assert($json['url'] === 'https://junctiontools.com/', 'Product Copy Analyzer did not analyze the requested URL.');
                jt_test_assert(is_int($json['wordCount']) && $json['wordCount'] > 0, 'Product Copy Analyzer returned no page words.');
                jt_test_assert(is_int($json['characterCount']) && $json['characterCount'] > 0, 'Product Copy Analyzer returned no page characters.');
                jt_test_assert(str_contains($json['message'], 'Analyzed Successfully'), 'Product Copy Analyzer did not report successful analysis.');
            }

            if ($type === 'readability_evaluator') {
                jt_test_assert($json['success'] === true, 'Readability Evaluator did not succeed.');
                jt_test_assert($json['url'] === 'https://junctiontools.com/', 'Readability Evaluator did not analyze the requested URL.');
                jt_test_assert(is_numeric($json['readingEase']) && $json['readingEase'] >= 0 && $json['readingEase'] <= 100, 'Readability readingEase is invalid.');
                jt_test_assert(str_contains($json['message'], 'Total Words:'), 'Readability Evaluator did not report word/sentence analysis.');
            }
        });
    }
    return $results;
}

function jt_suite_ssl(): array
{
    global $fixtureBase;
    $results=[];
    jt_test_case($results,'SSL helper rejects certificate metadata for HTTP fixture',static function()use($fixtureBase):void{
        $r=jt_safe_http_get($fixtureBase.'/compatibility-page.html',['certificate_info'=>true,'timeout'=>3,'connect_timeout'=>2]);
        jt_test_assert(($r['success']??false)===true,'HTTP fixture request failed: '.($r['message']??'unknown'));
        jt_test_assert(($r['certificate']['issuer']??null)===null,'HTTP request returned TLS certificate metadata.');
    });
    jt_test_case($results,'SSL certificate parser extracts issuer and expiry',static function():void{
        $expires=gmdate('D, d M Y H:i:s T',time()+10*86400);
        $parsed=jt_parse_certificate_info([['Issuer'=>'CN=JunctionTools CI Test CA','Expire date'=>$expires]]);
        jt_test_assert(trim((string)($parsed['issuer']??''))==='CN=JunctionTools CI Test CA','Certificate issuer parsing failed.');
        jt_test_assert(strtotime((string)($parsed['expires']??''))!==false,'Certificate expiry parsing failed.');
        jt_test_assert(is_int($parsed['daysRemaining']??null),'daysRemaining is not an integer.');
        jt_test_assert(($parsed['daysRemaining']??-999)>=9 && ($parsed['daysRemaining']??-999)<=10,'daysRemaining value is outside expected range.');
    });
    jt_test_case($results,'SSL daysRemaining matches parsed expiry',static function():void{
        $expiresTimestamp=time()+20*86400;
        $parsed=jt_parse_certificate_info([['Issuer'=>'CN=JunctionTools CI Test CA','Expire date'=>gmdate('D, d M Y H:i:s T',$expiresTimestamp)]]);
        $expected=(int)floor(($expiresTimestamp-time())/86400);
        jt_test_assert(abs($expected-(int)$parsed['daysRemaining'])<=1,'daysRemaining mismatch for parsed certificate metadata.');
    });
    $sslUrl=getenv('JUNCTIONTOOLS_SSL_TEST_URL');
    if(is_string($sslUrl) && trim($sslUrl)!==''){
        jt_test_case($results,'Configured SSL issuer/expiry/daysRemaining smoke test',static function()use($sslUrl):void{
            $r=jt_safe_http_get($sslUrl,['certificate_info'=>true,'timeout'=>12,'connect_timeout'=>5,'max_bytes'=>262144]);
            jt_test_assert(($r['success']??false)===true,'Configured HTTPS smoke test failed: '.($r['message']??'unknown'));
            $c=$r['certificate']??null;
            jt_test_assert(is_array($c),'Certificate metadata missing.');
            jt_test_assert(trim((string)($c['issuer']??''))!=='','Certificate issuer missing.');
            jt_test_assert(strtotime((string)($c['expires']??''))!==false,'Certificate expiry invalid. Debug: '.json_encode($r['certificate_debug']??[],JSON_UNESCAPED_SLASHES));
            jt_test_assert(is_int($c['daysRemaining']??null),'daysRemaining is not an integer.');
        });
    }
    return $results;
}

function jt_suite_ssrf(): array
{
    $results=[];
    $blocked=['http://127.0.0.1/','http://localhost/','http://0.0.0.0/','http://10.0.0.1/','http://172.16.0.1/','http://192.168.1.1/','http://169.254.169.254/','http://[::1]/','http://[fc00::1]/','http://[fe80::1]/','file:///etc/passwd','ftp://example.com/','gopher://example.com/','https://user:password@example.com/','https://example.com:8080/'];
    foreach($blocked as $url){jt_test_case($results,"SSRF blocked: {$url}",static function()use($url):void{[$ok]=jt_validate_external_url($url);jt_test_assert($ok===false,"Unsafe URL accepted: {$url}");});}
    return $results;
}

function jt_suite_redirects(): array
{
    global $fixtureBase;
    $results=[];
    jt_test_case($results,'Committed redirect fixture returns 302',static function()use($fixtureBase):void{$r=jt_run_http($fixtureBase.'/redirect.php','GET');jt_test_assert($r['status']===302,'redirect.php did not return 302.');jt_test_assert(isset($r['headers']['location']),'Redirect Location header missing.');});
    jt_test_case($results,'Safe HTTP client rejects private redirect fixture before any follow',static function()use($fixtureBase):void{$r=jt_safe_http_get($fixtureBase.'/redirect.php',['timeout'=>5,'connect_timeout'=>2,'max_bytes'=>65536]);jt_test_assert(($r['status']??0)===0,'Safe HTTP client reached the private redirect fixture.');jt_test_assert(($r['success']??true)===false,'Safe HTTP client unexpectedly succeeded against a private fixture URL.');});
    return $results;
}

function jt_suite_limits(array $options): array
{
    global $fixtureBase;
    $results=[];
    $slow=(string)($options['slow-url']??$fixtureBase.'/timeout.php?delay=10');
    $large=(string)($options['large-url']??$fixtureBase.'/large-response.php?bytes=3145728');
    jt_test_case($results,'Committed timeout fixture exceeds 1-second client timeout',static function()use($slow):void{$started=microtime(true);$r=jt_safe_http_get($slow,['timeout'=>1,'connect_timeout'=>1,'max_bytes'=>65536]);$elapsed=microtime(true)-$started;jt_test_assert(($r['success']??true)===false,'Timeout fixture unexpectedly succeeded.');jt_test_assert($elapsed<5,sprintf('Timeout took %.2fs.',$elapsed));});
    jt_test_case($results,'Committed response-size fixture exceeds 2 MiB limit',static function()use($large):void{$r=jt_safe_http_get($large,['timeout'=>5,'connect_timeout'=>2,'max_bytes'=>2097152]);jt_test_assert(($r['success']??true)===false,'Oversized fixture unexpectedly succeeded.');});
    return $results;
}

function jt_suite_rate_limit(): array
{
    $results=[];$bucket='ci-'.bin2hex(random_bytes(8));
    for($i=1;$i<=10;$i++){jt_test_case($results,"Rate limit request {$i}/10 is allowed",static function()use($bucket):void{jt_test_assert(jt_rate_limit($bucket,10,300),'Expected request to be allowed.');});}
    jt_test_case($results,'Rate limit request 11/10 is rejected',static function()use($bucket):void{jt_test_assert(jt_rate_limit($bucket,10,300)===false,'Expected rate-limit rejection.');});
    return $results;
}

function jt_suite_contact(array $options): array
{
    global $fixtureBase;
    $results=[];$url=(string)($options['contact-url']??getenv('JUNCTIONTOOLS_CONTACT_TEST_URL')?:$fixtureBase.'/contact-endpoint.php');
    $headers=['Origin: https://junctiontools.com'];
    jt_test_case($results,'Contact fixture rejects GET',static function()use($url,$headers):void{$r=jt_run_http($url,'GET',null,$headers);jt_test_assert($r['status']===405,'Expected GET 405, got '.$r['status']);});
    jt_test_case($results,'Contact fixture rejects invalid email',static function()use($url,$headers):void{$b=http_build_query(['name'=>'CI Test','email'=>'invalid','message'=>'test']);$r=jt_run_http($url,'POST',$b,array_merge($headers,['Content-Type: application/x-www-form-urlencoded']));jt_test_assert($r['status']===422,'Expected invalid email 422, got '.$r['status']);});
    jt_test_case($results,'Contact fixture rejects oversized request',static function()use($url,$headers):void{$b='message='.str_repeat('A',40000);$r=jt_run_http($url,'POST',$b,array_merge($headers,['Content-Type: application/x-www-form-urlencoded']));jt_test_assert($r['status']===413,'Expected oversized request 413, got '.$r['status']);});
    jt_test_case($results,'Contact fixture rejects honeypot',static function()use($url,$headers):void{$b=http_build_query(['name'=>'CI Test','email'=>'ci@example.test','message'=>'test','website'=>'bot']);$r=jt_run_http($url,'POST',$b,array_merge($headers,['Content-Type: application/x-www-form-urlencoded']));jt_test_assert($r['status']===422,'Expected honeypot 422, got '.$r['status']);});
    jt_test_case($results,'Contact fixture accepts valid submission without side effects',static function()use($url,$headers):void{$b=http_build_query(['name'=>'CI Test','email'=>'ci@example.test','message'=>'fixture test']);$r=jt_run_http($url,'POST',$b,array_merge($headers,['Content-Type: application/x-www-form-urlencoded']));jt_test_assert($r['status']===200,'Expected valid submission 200, got '.$r['status']);$j=jt_test_json($r['body']);jt_test_assert(($j['success']??false)===true,'Valid fixture submission did not return success=true.');});
    return $results;
}

function jt_suite_http_security(): array
{
    $results=[];$url=jt_test_scanner_url().'/scanner.php';
    jt_test_case($results,'Scanner rejects GET',static function()use($url):void{$r=jt_run_http($url,'GET');jt_test_assert($r['status']===405,'Scanner GET returned '.$r['status']);});
    jt_test_case($results,'Scanner rejects malformed JSON',static function()use($url):void{$r=jt_run_http($url,'POST','{bad-json',['Content-Type: application/json']);jt_test_assert($r['status']===400,'Malformed JSON returned '.$r['status']);});
    return $results;
}

$dispatch=['compatibility'=>'jt_suite_compatibility','ssl'=>'jt_suite_ssl','ssrf'=>'jt_suite_ssrf','redirects'=>'jt_suite_redirects','limits'=>static fn():array=>jt_suite_limits($options),'rate-limit'=>'jt_suite_rate_limit','contact'=>static fn():array=>jt_suite_contact($options),'http-security'=>'jt_suite_http_security'];

if($suite==='all'){$all=[];foreach($dispatch as $fn){$all=array_merge($all,$fn());}$results=$all;}elseif(isset($dispatch[$suite])){$fn=$dispatch[$suite];$results=$fn();}else{fwrite(STDERR,"Unknown suite: {$suite}\n");exit(2);}

$passed=0;$failed=0;foreach($results as $result){if($result['status']==='PASS')$passed++;else$failed++;echo '['.$result['status'].'] '.$result['name']."\n";if($result['status']==='FAIL')echo '  '.$result['error']."\n";}

echo "\nSuite: {$suite} | PASS: {$passed} | FAIL: {$failed} | TOTAL: ".count($results)."\n";

$summary=['suite'=>$suite,'status'=>$failed===0?'PASS':'FAIL','passed'=>$passed,'failed'=>$failed,'total'=>count($results),'results'=>$results];
if($reportPath!==null){file_put_contents($reportPath,json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}

echo 'RESULT: '.($failed===0?'PASS':'FAIL')."\n";
exit($failed===0?0:1);
