<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/security/url-validator.php';
require_once __DIR__ . '/security/safe-http.php';
require_once __DIR__ . '/security/rate-limit.php';

function jt_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jt_json(['success' => false, 'message' => 'Invalid request method.'], 405);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 32768) {
    jt_json(['success' => false, 'message' => 'Request is too large.'], 413);
}

if (!jt_rate_limit('scanner', 10, 300)) {
    jt_json(['success' => false, 'message' => 'Too many scan requests. Please try again later.'], 429);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);
if (!is_array($input)) {
    jt_json(['success' => false, 'message' => 'Invalid JSON request.'], 400);
}

$scanType = trim((string)($input['type'] ?? 'pixels'));
if ($scanType === '' || strlen($scanType) > 64 || !preg_match('/^[a-z0-9_\-]+$/', $scanType)) {
    jt_json(['success' => false, 'message' => 'Invalid scan type.'], 400);
}

function jt_request_url(array $input, string $field = 'url'): string
{
    $url = trim((string)($input[$field] ?? ''));
    if ($url === '' || strlen($url) > 2048) {
        jt_json(['success' => false, 'message' => 'Please provide a valid URL.'], 400);
    }
    return $url;
}

function jt_fetch_html(string $url): array
{
    $result = jt_safe_http_get($url, [
        'timeout' => 12,
        'connect_timeout' => 5,
        'max_bytes' => 2 * 1024 * 1024,
        'user_agent' => 'JunctionTools-SecureScanner/1.0 (+https://junctiontools.com)'
    ]);

    if (!$result['success']) {
        jt_json(['success' => false, 'message' => $result['message'] ?? 'Unable to fetch the target URL.'], 422);
    }

    $contentType = strtolower((string)($result['content_type'] ?? ''));
    if ($contentType !== '' && strpos($contentType, 'text/html') === false && strpos($contentType, 'application/xhtml+xml') === false) {
        jt_json(['success' => false, 'message' => 'The target did not return an HTML page.'], 422);
    }

    return $result;
}

function jt_parse_html(string $html): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    if (!$loaded) {
        jt_json(['success' => false, 'message' => 'The target HTML could not be parsed.'], 422);
    }
    return [$dom, new DOMXPath($dom)];
}

function jt_domain_from_input(array $input): string
{
    $value = trim((string)($input['domain'] ?? $input['url'] ?? ''));
    $value = preg_replace('#^https?://#i', '', $value);
    $value = rtrim($value, '/');
    if ($value === '' || strlen($value) > 253 || strpos($value, '/') !== false || strpos($value, '?') !== false || strpos($value, '#') !== false) {
        jt_json(['success' => false, 'message' => 'Please provide a valid domain name.'], 400);
    }
    if (!jt_validate_domain($value)) {
        jt_json(['success' => false, 'message' => 'The supplied domain is not allowed.'], 422);
    }
    return strtolower($value);
}

function jt_hex_rgb(string $value): ?array
{
    if (!preg_match('/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) return null;
    $h = ltrim($value, '#');
    if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
    return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}

function jt_luminance(array $rgb): float
{
    $v = array_map(static function ($x) {
        $x /= 255;
        return $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4;
    }, $rgb);
    return $v[0]*0.2126 + $v[1]*0.7152 + $v[2]*0.0722;
}

function jt_contrast_ratio(array $a, array $b): float
{
    return round((max(jt_luminance($a), jt_luminance($b)) + 0.05) / (min(jt_luminance($a), jt_luminance($b)) + 0.05), 2);
}

switch ($scanType) {
    case 'trust_inspector':
        $url = jt_request_url($input);
        $result = jt_fetch_html($url);
        $html = strtolower(strip_tags($result['body']));
        $signals = [
            'secure/payment' => preg_match('/\b(secure checkout|secure payment|ssl|encrypted|payment secure|safe checkout)\b/i', $html) === 1,
            'guarantee' => preg_match('/\b(guarantee|guaranteed|money[- ]back|refund policy)\b/i', $html) === 1,
            'reviews' => preg_match('/\b(review|reviews|testimonial|testimonials|rating|ratings)\b/i', $html) === 1,
            'shipping/returns' => preg_match('/\b(free shipping|shipping|returns?|return policy|delivery)\b/i', $html) === 1,
        ];
        $count = count(array_filter($signals));
        $confidenceIndex = $count * 25;
        jt_json([
            'success' => true,
            'url' => $url,
            'confidenceIndex' => $confidenceIndex,
            'activeElements' => $count,
            'detectedElements' => array_keys(array_filter($signals)),
            'message' => 'Buyer Confidence Index: <strong>' . $confidenceIndex . '%</strong><br>Active Elements Detected: ' . $count . '/4'
        ]);

    case 'copy_analyzer':
        $url = jt_request_url($input);
        $result = jt_fetch_html($url);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($result['body'])));
        $wordCount = str_word_count($plain);
        $charCount = mb_strlen($plain);
        $status = $wordCount >= 50 ? "Good ({$wordCount} words)" : "Too Short ({$wordCount} words - aim for 50+)";
        jt_json(['success' => true, 'url' => $url, 'wordCount' => $wordCount, 'characterCount' => $charCount, 'message' => "Word Count: {$status}<br>Character Count: <strong>{$charCount} characters</strong><br>Readability & Copy Strength: <strong>Analyzed Successfully</strong>"]);

    case 'readability_evaluator':
        $url = jt_request_url($input);
        $result = jt_fetch_html($url);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($result['body'])));
        $words = str_word_count($plain);
        $sentences = max(1, preg_match_all('/[.!?]+/', $plain, $matches));
        $characters = mb_strlen(preg_replace('/\s+/', '', $plain));
        $readingEase = $words > 0 ? max(0, min(100, round(206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($characters / $words)), 1))) : 0;
        $gradeLevel = $readingEase < 50 ? 'Advanced / College Level' : ($readingEase > 80 ? 'Very Easy (5th-6th Grade)' : 'Standard (Easy to Read)');
        jt_json(['success' => true, 'url' => $url, 'readingEase' => $readingEase, 'message' => "Reading Ease Score: <strong>{$readingEase} / 100</strong><br>Estimated Level: <strong>{$gradeLevel}</strong><br>Total Words: {$words} | Sentences: {$sentences}"]);

    case 'wcag_checker':
        $url = jt_request_url($input);
        $result = jt_fetch_html($url);
        preg_match_all('/#[0-9a-fA-F]{3,6}\b/', $result['body'], $matches);
        $colors = [];
        foreach ($matches[0] as $value) {
            $normalized = strtoupper($value);
            if (!in_array($normalized, $colors, true) && jt_hex_rgb($normalized) !== null) $colors[] = $normalized;
            if (count($colors) >= 20) break;
        }
        $pairs = [];
        for ($i = 0; $i < count($colors); $i++) {
            for ($j = $i + 1; $j < count($colors); $j++) {
                $ratio = jt_contrast_ratio(jt_hex_rgb($colors[$i]), jt_hex_rgb($colors[$j]));
                $pairs[] = ['foreground' => $colors[$i], 'background' => $colors[$j], 'ratio' => $ratio, 'passesAA' => $ratio >= 4.5, 'passesAAA' => $ratio >= 7];
            }
        }
        $bestRatio = null;
        foreach ($pairs as $pair) $bestRatio = $bestRatio === null ? $pair['ratio'] : max($bestRatio, $pair['ratio']);
        jt_json(['success'=>true,'url'=>$url,'colorsDetected'=>count($colors),'contrastPairsAnalyzed'=>count($pairs),'bestContrastRatio'=>$bestRatio,'pairs'=>$pairs,'message'=>'Analyzed color values present in the fetched HTML for WCAG contrast. Found '.count($colors).' unique color value(s) and '.count($pairs).' contrast pair(s).']);

    case 'aria_audit':
    case 'mobile_audit':
    case 'ux_evaluator':
    case 'seo_auditor':
    case 'browser_checker':
    case 'cta_analyzer':
    case 'friction_analyzer':
    case 'cart_abandonment':
    case 'schema_validator':
        $url = jt_request_url($input, isset($input['store_url']) ? 'store_url' : 'url');
        $result = jt_fetch_html($url);
        $html = $result['body'];
        [$dom, $xpath] = jt_parse_html($html);

        if ($scanType === 'aria_audit') {
            $images = $dom->getElementsByTagName('img');
            $missingAlt = 0;
            foreach ($images as $img) if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') $missingAlt++;
            $interactive = $xpath->query('//button | //a | //input[@type="submit" or @type="button"]');
            $missingAria = 0;
            foreach ($interactive as $el) {
                $label = ($el->getAttribute('aria-label') ?: $el->getAttribute('aria-labelledby'));
                if (trim($label) === '' && trim($el->textContent) === '' && !($el->hasAttribute('value') && trim($el->getAttribute('value')) !== '')) $missingAria++;
            }
            $issues = $missingAlt + $missingAria;
            jt_json(['success'=>true,'url'=>$url,'missingAltCount'=>$missingAlt,'missingAriaCount'=>$missingAria,'accessibilityScore'=>max(0,100-($issues*4)),'hasIssues'=>$issues>0,'message'=>"Scanned live HTML: Found {$missingAlt} images without alt attributes and {$missingAria} interactive elements missing accessible descriptions."]);
        }

        if ($scanType === 'schema_validator') {
            $schemas = [];
            foreach ($dom->getElementsByTagName('script') as $script) {
                if (strtolower($script->getAttribute('type')) === 'application/ld+json') $schemas[] = trim($script->textContent);
            }
            $valid = 0; $invalid = 0;
            foreach ($schemas as $schema) { json_decode($schema, true); json_last_error() === JSON_ERROR_NONE ? $valid++ : $invalid++; }
            jt_json(['success'=>true,'schemaCount'=>count($schemas),'validSchemas'=>$valid,'invalidSchemas'=>$invalid,'message'=>"Found " . count($schemas) . " JSON-LD block(s): {$valid} valid, {$invalid} invalid."]);
        }

        $title = trim((string)$dom->getElementsByTagName('title')->item(0)?->textContent);
        $metaDescription = '';
        foreach ($dom->getElementsByTagName('meta') as $meta) if (strtolower($meta->getAttribute('name')) === 'description') { $metaDescription = trim($meta->getAttribute('content')); break; }
        $h1Count = $dom->getElementsByTagName('h1')->length;
        $links = $dom->getElementsByTagName('a')->length;
        $buttons = $dom->getElementsByTagName('button')->length;
        $message = "Live HTML analyzed successfully. Title: " . ($title !== '' ? 'Present' : 'Missing') . "; Meta description: " . ($metaDescription !== '' ? 'Present' : 'Missing') . "; H1 count: {$h1Count}; Links: {$links}; Buttons: {$buttons}.";
        jt_json(['success'=>true,'url'=>$url,'title'=>$title,'metaDescription'=>$metaDescription,'h1Count'=>$h1Count,'links'=>$links,'buttons'=>$buttons,'message'=>$message]);

    case 'ssl_audit':
        $domain = jt_domain_from_input($input);
        $url = 'https://' . $domain;
        $result = jt_safe_http_get($url, ['timeout'=>12,'connect_timeout'=>5,'max_bytes'=>256*1024,'certificate_info'=>true,'user_agent'=>'JunctionTools-SSLChecker/1.0 (+https://junctiontools.com)']);
        if (!$result['success']) jt_json(['success'=>false,'message'=>$result['message'] ?? 'Unable to establish a verified HTTPS connection.'],422);
        $headers = strtolower((string)($result['headers'] ?? ''));
        $hsts = strpos($headers, 'strict-transport-security') !== false;
        $csp = strpos($headers, 'content-security-policy') !== false;
        $xFrame = strpos($headers, 'x-frame-options') !== false;
        $certificate = is_array($result['certificate'] ?? null) ? $result['certificate'] : [];
        $issuer = (string)($certificate['issuer'] ?? '');
        $expiryDate = (string)($certificate['expires_at'] ?? '');
        $daysRemaining = $certificate['days_remaining'] ?? null;
        $score = 40 + ($hsts?15:0) + ($xFrame?15:0) + 30;
        jt_json(['success'=>true,'sslValid'=>true,'issuer'=>$issuer!==''?$issuer:null,'expiryDate'=>$expiryDate!==''?$expiryDate:null,'daysRemaining'=>$daysRemaining,'hsts'=>$hsts,'csp'=>$csp,'xFrame'=>$xFrame,'score'=>$score,'message'=>'Verified HTTPS connection succeeded. Certificate issuer: '.($issuer!==''?$issuer:'Unavailable').'; expiry: '.($expiryDate!==''?$expiryDate:'Unavailable').'; HSTS: '.($hsts?'Yes':'No').'; CSP: '.($csp?'Yes':'No').'; X-Frame-Options: '.($xFrame?'Present':'Missing').'.']);

    default:
        jt_json(['success'=>false,'message'=>'Unsupported scan type.'],400);
}
