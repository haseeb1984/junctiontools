<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$base = rtrim((string)(getenv('JUNCTIONTOOLS_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
$fixtureUrl = $base . '/tests/fixtures/compatibility-page.html';
$scannerUrl = $base . '/scanner.php';
$results = [];

$clientTools = [
    'PageSpeed Analyzer'=>'pagespeed.html','Image Compressor'=>'asset-optimizer.html','GA4 Event Code Generator'=>'ga4-verifier.html','CSS/JS Minifier'=>'css-js-minifier.html','Invoice Generator'=>'invoice-generator.html','Discount Calculator'=>'discount-calculator.html','Aspect Ratio Calculator'=>'aspect-ratio-calculator.html','Color Palette Generator'=>'color-palette-calculator.html','Social Asset Optimizer'=>'social-asset-optimizer.html','Bio Font Generator'=>'bio-font-generator.html','Clean Text Tool'=>'clean-text.html','Case Converter'=>'case-converter.html','Word Counter'=>'word-counter.html','PX to REM'=>'px-to-rem.html','Base64 Converter'=>'base64-converter.html','JSON Formatter'=>'json-formatter.html','Regex Tester'=>'regex-tester.html','SHA-256 Hash'=>'sha256-hash.html','UUID Generator'=>'uuid-generator.html','WhatsApp Link'=>'whatsapp-direct.html'
];

foreach ($clientTools as $name => $file) {
    jt_test_case($results, "35-tool compatibility: {$name}", static function () use ($file): void {
        $path = JT_TEST_ROOT . '/' . $file;
        jt_test_assert(is_file($path), "Missing {$file}.");
        $html = file_get_contents($path);
        jt_test_assert(is_string($html) && trim($html) !== '', "{$file} is empty.");
        jt_test_assert(stripos($html, '<html') !== false || stripos($html, '<!doctype') !== false, "{$file} is not HTML.");
    });
}

$scannerTools = [
    'Pixel Diagnostic'=>['pixels','url'],'ARIA & Alt Audit'=>['aria_audit','url'],'Mobile Viewport UX'=>['mobile_audit','url'],'UX Layout Evaluator'=>['ux_evaluator','url'],'Cross-Browser Check'=>['browser_checker','url'],'Meta SEO Checker'=>['seo_auditor','url'],'Schema Validator'=>['schema_validator','raw'],'Checkout Funnel Analyzer'=>['friction_analyzer','calc'],'Cart Loss Calculator'=>['cart_abandonment','calc'],'CTA & Headline Analyzer'=>['cta_analyzer','url'],'Trust Badge Inspector'=>['trust_inspector','url'],'SSL & Security Checker'=>['ssl_audit','ssl'],'Contrast Checker'=>['wcag_checker','url'],'Product Copy Analyzer'=>['copy_analyzer','url'],'Readability Evaluator'=>['readability_evaluator','url']
];

foreach ($scannerTools as $name => [$type, $mode]) {
    jt_test_case($results, "35-tool compatibility: {$name}", static function () use ($name, $type, $mode, $fixtureUrl, $scannerUrl): void {
        $payload = ['type' => $type];
        if ($type === 'cart_abandonment') {
            $payload += ['total_carts'=>100,'completed_orders'=>80,'aov'=>50];
        } elseif ($type === 'friction_analyzer') {
            $payload += ['checkout_type'=>'1step','required_fields'=>5,'guest_option'=>'enabled'];
        } elseif ($type === 'schema_validator') {
            $payload['payload'] = '{"@context":"https://schema.org","@type":"Product","name":"Test"}';
        } elseif ($type === 'ssl_audit') {
            $payload['domain'] = 'example.com';
        } else {
            $payload['url'] = $fixtureUrl;
        }

        $response = jt_test_http($scannerUrl, 'POST', json_encode($payload), ['Content-Type: application/json']);
        jt_test_assert(in_array($response['status'], [200,400,422], true), "{$name} returned HTTP {$response['status']}.");
        $json = jt_test_json($response['body']);
        jt_test_assert(array_key_exists('success', $json), "{$name} response lacks success field.");

        if ($type === 'trust_inspector') {
            jt_test_assert($json['success'] === true, 'Trust Badge Inspector did not succeed.');
            jt_test_assert($json['url'] === $fixtureUrl, 'Trust Badge Inspector did not analyze the fixture URL.');
            jt_test_assert(is_int($json['confidenceIndex']) && $json['confidenceIndex'] >= 0 && $json['confidenceIndex'] <= 100, 'Trust confidenceIndex is invalid.');
            jt_test_assert(is_int($json['activeElements']) && $json['activeElements'] >= 0 && $json['activeElements'] <= 4, 'Trust activeElements is invalid.');
            jt_test_assert(is_array($json['detectedElements']), 'Trust detectedElements is missing.');
        }
        if ($type === 'wcag_checker') {
            jt_test_assert($json['success'] === true, 'Contrast Checker did not succeed.');
            jt_test_assert($json['url'] === $fixtureUrl, 'Contrast Checker did not analyze the fixture URL.');
            jt_test_assert(is_int($json['colorsDetected']) && $json['colorsDetected'] >= 0, 'Contrast colorsDetected is invalid.');
            jt_test_assert(is_int($json['contrastPairsAnalyzed']) && $json['contrastPairsAnalyzed'] >= 0, 'Contrast pair count is invalid.');
            jt_test_assert(array_key_exists('bestContrastRatio', $json), 'Contrast bestContrastRatio field is missing.');
            jt_test_assert(is_array($json['pairs']), 'Contrast pairs field is missing.');
        }
        if ($type === 'copy_analyzer') {
            jt_test_assert($json['success'] === true, 'Product Copy Analyzer did not succeed.');
            jt_test_assert($json['url'] === $fixtureUrl, 'Product Copy Analyzer did not analyze the fixture URL.');
            jt_test_assert(is_int($json['wordCount']) && $json['wordCount'] > 0, 'Product Copy Analyzer returned no page words.');
            jt_test_assert(is_int($json['characterCount']) && $json['characterCount'] > 0, 'Product Copy Analyzer returned no page characters.');
            jt_test_assert(str_contains($json['message'], 'Analyzed Successfully'), 'Product Copy Analyzer did not report successful analysis.');
        }
        if ($type === 'readability_evaluator') {
            jt_test_assert($json['success'] === true, 'Readability Evaluator did not succeed.');
            jt_test_assert($json['url'] === $fixtureUrl, 'Readability Evaluator did not analyze the fixture URL.');
            jt_test_assert(is_numeric($json['readingEase']) && $json['readingEase'] >= 0 && $json['readingEase'] <= 100, 'Readability readingEase is invalid.');
            jt_test_assert(str_contains($json['message'], 'Total Words:'), 'Readability Evaluator did not report word/sentence analysis.');
        }
    });
}

jt_test_finish('compatibility', $results, null);
