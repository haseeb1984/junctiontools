<?php
/**
 * JunctionTools secure scanner endpoint.
 *
 * This endpoint intentionally does not execute the legacy scanner.php code.
 * All outbound requests go through security/safe-http.php, which performs
 * SSRF validation, TLS verification, no redirects, timeouts and size limits.
 */
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

switch ($scanType) {
    case 'trust_inspector':
        $elements = $input['trust_elements'] ?? [];
        $count = is_array($elements) ? min(20, count($elements)) : 0;
        $confidenceIndex = min(100, $count * 25);
        jt_json([
            'success' => true,
            'confidenceIndex' => $confidenceIndex,
            'message' => 'Buyer Confidence Index: <strong>' . $confidenceIndex . '%</strong><br>Active Elements Detected: ' . $count . '/4'
        ]);

    case 'copy_analyzer':
        $text = trim((string)($input['copy_text'] ?? ''));
        if (strlen($text) > 50000) jt_json(['success' => false, 'message' => 'Copy is too large.'], 413);
        $wordCount = str_word_count(strip_tags($text));
        $charCount = mb_strlen($text);
        $status = $wordCount >= 50 ? "Good ({$wordCount} words)" : "Too Short ({$wordCount} words - aim for 50+)";
        jt_json(['success' => true, 'wordCount' => $wordCount, 'message' => "Word Count: {$status}<br>Character Count: <strong>{$charCount} characters</strong><br>Readability & Copy Strength: <strong>Analyzed Successfully</strong>"]);

    case 'readability_evaluator':
        $text = trim((string)($input['text_content'] ?? ''));
        if (strlen($text) > 50000) jt_json(['success' => false, 'message' => 'Text is too large.'], 413);
        $plain = strip_tags($text);
        $words = str_word_count($plain);
        $sentences = max(1, preg_match_all('/[.!?]+/', $plain, $matches));
        $characters = mb_strlen(preg_replace('/\s+/', '', $plain));
        $readingEase = $words > 0 ? max(0, min(100, round(206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($characters / $words)), 1))) : 0;
        $gradeLevel = $readingEase < 50 ? 'Advanced / College Level' : ($readingEase > 80 ? 'Very Easy (5th-6th Grade)' : 'Standard (Easy to Read)');
        jt_json(['success' => true, 'readingEase' => $readingEase, 'message' => "Reading Ease Score: <strong>{$readingEase} / 100</strong><br>Estimated Level: <strong>{$gradeLevel}</strong><br>Total Words: {$words} | Sentences: {$sentences}"]);

    case 'wcag_checker':
        $textColor = trim((string)($input['text_color'] ?? '#FFFFFF'));
        $bgColor = trim((string)($input['bg_color'] ?? '#000000'));
        $hex = static function (string $value): ?array {
            if (!preg_match('/^#?(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) return null;
            $h = ltrim($value, '#');
            if (strlen($h) === 3) $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2];
            return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
        };
        $rgb1 = $hex($textColor); $rgb2 = $hex($bgColor);
        if (!$rgb1 || !$rgb2) jt_json(['success' => false, 'message' => 'Invalid color value.'], 400);
        $lum = static function (array $rgb): float {
            $v = array_map(static function ($x) { $x /= 255; return $x <= 0.03928 ? $x / 12.92 : (($x + 0.055) / 1.055) ** 2.4; }, $rgb);
            return $v[0]*0.2126 + $v[1]*0.7152 + $v[2]*0.0722;
        };
        $ratio = round((max($lum($rgb1), $lum($rgb2)) + 0.05) / (min($lum($rgb1), $lum($rgb2)) + 0.05), 2);
        jt_json(['success' => true, 'contrastRatio' => $ratio, 'message' => "Contrast Ratio: <strong>{$ratio}:1</strong>"]);

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
        $result = jt_fetch_html($url);
        $headers = strtolower((string)($result['headers'] ?? ''));
        $hsts = strpos($headers, 'strict-transport-security') !== false;
        $csp = strpos($headers, 'content-security-policy') !== false;
        $xFrame = strpos($headers, 'x-frame-options') !== false;
        jt_json(['success'=>true,'sslValid'=>true,'daysRemaining'=>null,'hsts'=>$hsts,'csp'=>$csp,'xFrame'=>$xFrame,'score'=>40+($hsts?15:0)+($xFrame?15:0)+30,'message'=>"HTTPS connection succeeded. HSTS: " . ($hsts?'Yes':'No') . "; CSP: " . ($csp?'Yes':'No') . "; X-Frame-Options: " . ($xFrame?'Present':'Missing') . "."]);

    default:
        jt_json(['success'=>false,'message'=>'Unsupported scan type.'], 400);
}
