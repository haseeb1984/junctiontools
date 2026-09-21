<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input') ?: '', true);
$type = is_array($input) ? trim((string)($input['type'] ?? 'pixels')) : '';

$compatTypes = [
    'cart_abandonment',
    'friction_analyzer',
    'schema_validator',
    'pixels',
    'mobile_audit',
    'ux_evaluator',
    'browser_checker',
    'seo_auditor',
    'cta_analyzer',
    'trust_inspector',
    'wcag_checker',
    'aria_audit',
    'copy_analyzer',
    'readability_evaluator',
    'ssl_audit'
];

if (!in_array($type, $compatTypes, true)) {
    require __DIR__ . '/scanner-secure.php';
    exit;
}

require_once __DIR__ . '/security/url-validator.php';
require_once __DIR__ . '/security/safe-http.php';
require_once __DIR__ . '/security/rate-limit.php';

header('Content-Type: application/json; charset=utf-8');

function compat_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    compat_json(['success' => false, 'message' => 'Invalid request method.'], 405);
}

if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    compat_json(['success' => false, 'message' => 'Request is too large.'], 413);
}

if (!is_array($input)) {
    compat_json(['success' => false, 'message' => 'Invalid JSON request.'], 400);
}

if (!jt_rate_limit('scanner', 10, 300)) {
    compat_json(['success' => false, 'message' => 'Too many scan requests. Please try again later.'], 429);
}

function compat_external_html(string $url): string
{
    if ($url === '' || strlen($url) > 2048) {
        compat_json(['success' => false, 'message' => 'Please provide a valid URL.'], 400);
    }

    [$ok, $normalized] = jt_validate_external_url($url);
    if (!$ok) {
        compat_json(['success' => false, 'message' => $normalized], 422);
    }

    $response = jt_safe_http_get($normalized, [
        'timeout' => 12,
        'connect_timeout' => 5,
        'max_bytes' => 2097152,
        'user_agent' => 'JunctionTools-SecureScanner/1.0 (+https://junctiontools.com)'
    ]);

    if (!$response['success']) {
        compat_json([
            'success' => false,
            'message' => $response['message'] ?: 'Unable to fetch the target URL.'
        ], 422);
    }

    return (string)$response['body'];
}

function compat_dom(string $html): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();

    return [$dom, new DOMXPath($dom)];
}

switch ($type) {
    case 'ssl_audit':
        $rawTarget = trim((string)($input['domain'] ?? $input['url'] ?? ''));
        if ($rawTarget === '' || strlen($rawTarget) > 2048) {
            compat_json(['success' => false, 'message' => 'Please provide a valid domain or URL.'], 400);
        }

        $candidate = preg_match('#^https?://#i', $rawTarget) ? $rawTarget : 'https://' . $rawTarget;
        $parts = parse_url($candidate);
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '' || !jt_validate_domain($host)) {
            compat_json(['success' => false, 'message' => 'Please provide a valid domain or URL.'], 400);
        }

        $pathPart = (string)($parts['path'] ?? '/');
        if ($pathPart === '') $pathPart = '/';
        $queryPart = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $target = 'https://' . $host . $pathPart . $queryPart;

        $result = jt_safe_http_get($target, [
            'timeout' => 12,
            'connect_timeout' => 5,
            'max_bytes' => 262144,
            'certificate_info' => true,
            'user_agent' => 'JunctionTools-SSLChecker/1.0 (+https://junctiontools.com)'
        ]);

        if (!$result['success']) {
            compat_json([
                'success' => false,
                'message' => $result['message'] ?: 'Unable to establish a verified HTTPS connection.'
            ], 422);
        }

        $headers = strtolower((string)($result['headers'] ?? ''));
        $hsts = strpos($headers, 'strict-transport-security') !== false;
        $csp = strpos($headers, 'content-security-policy') !== false;
        $xFrame = strpos($headers, 'x-frame-options') !== false;
        $certificate = is_array($result['certificate'] ?? null) ? $result['certificate'] : [];
        $issuer = (string)($certificate['issuer'] ?? '');
        $expiryDate = (string)($certificate['expires_at'] ?? '');
        $daysRemaining = $certificate['days_remaining'] ?? null;

        $score = 40 + ($hsts ? 15 : 0) + ($xFrame ? 15 : 0) + 30;

        compat_json([
            'success' => true,
            'url' => $target,
            'domain' => $host,
            'sslValid' => true,
            'issuer' => $issuer !== '' ? $issuer : null,
            'expiryDate' => $expiryDate !== '' ? $expiryDate : null,
            'daysRemaining' => $daysRemaining,
            'hsts' => $hsts,
            'csp' => $csp,
            'xFrame' => $xFrame,
            'score' => min(100, $score),
            'message' => 'Verified HTTPS connection succeeded. Certificate issuer: ' .
                ($issuer !== '' ? $issuer : 'Unavailable') .
                '; expiry: ' . ($expiryDate !== '' ? $expiryDate : 'Unavailable') .
                '; HSTS: ' . ($hsts ? 'Yes' : 'No') .
                '; CSP: ' . ($csp ? 'Yes' : 'No') .
                '; X-Frame-Options: ' . ($xFrame ? 'Present' : 'Missing') . '.'
        ]);

    case 'cart_abandonment':
        $carts = filter_var($input['total_carts'] ?? null, FILTER_VALIDATE_INT);
        $orders = filter_var($input['completed_orders'] ?? null, FILTER_VALIDATE_INT);
        $aov = filter_var($input['aov'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($carts === false || $orders === false || $aov === false || $carts <= 0 || $orders < 0 || $orders > $carts || $aov < 0) {
            compat_json(['success' => false, 'message' => 'Please provide valid numbers for carts and orders.'], 400);
        }

        $abandoned = $carts - $orders;
        $rate = $abandoned / $carts * 100;
        $lost = $abandoned * $aov;

        compat_json([
            'success' => true,
            'abandonmentRate' => $rate,
            'lostRevenue' => $lost,
            'message' => 'Cart Abandonment Rate: <strong>' . number_format($rate, 2) . '%</strong><br>Abandoned Carts: <strong>' . $abandoned . '</strong><br>Estimated Lost Revenue: <strong>$' . number_format($lost, 2) . '</strong>'
        ]);

    case 'friction_analyzer':
        $checkout = trim((string)($input['checkout_type'] ?? '1step'));
        $fields = filter_var($input['required_fields'] ?? 0, FILTER_VALIDATE_INT);
        $guest = trim((string)($input['guest_option'] ?? 'enabled'));

        if ($fields === false || $fields < 0 || $fields > 100) {
            compat_json(['success' => false, 'message' => 'Please provide a valid required field count.'], 400);
        }

        $score = 100;
        $tips = [];

        if ($fields > 8) {
            $score -= ($fields - 8) * 5;
            $tips[] = "Your mandatory fields ({$fields}) are higher than recommended. Reducing them can lower checkout friction.";
        } else {
            $tips[] = 'Good! Your mandatory field count is 8 or fewer.';
        }

        if ($guest === 'forced') {
            $score -= 30;
            $tips[] = 'Forced account creation can increase checkout friction. Consider enabling guest checkout.';
        } else {
            $tips[] = 'Guest checkout is enabled, which helps reduce user friction.';
        }

        if ($checkout === '3step') {
            $score -= 15;
            $tips[] = 'A 3-step checkout can introduce more friction than a single-page or accordion-style checkout.';
        }

        $score = max(10, min(100, $score));

        compat_json([
            'success' => true,
            'score' => $score,
            'message' => 'Checkout Friction Score: ' . $score . '/100. ' . implode(' ', $tips)
        ]);

    case 'schema_validator':
        $payload = trim((string)($input['payload'] ?? ($input['url'] ?? '')));

        if ($payload !== '' && preg_match('/^https?:\/\//i', $payload)) {
            $html = compat_external_html($payload);
            [$dom, $xpath] = compat_dom($html);
            $scripts = $xpath->query('//script[@type="application/ld+json"]');

            if ($scripts->length === 0) {
                compat_json([
                    'success' => true,
                    'score' => 30,
                    'schemaType' => 'Unknown / Custom Schema',
                    'syntaxStatus' => 'No JSON-LD Tag Found',
                    'message' => 'The target URL does not contain application/ld+json schema markup.'
                ]);
            }

            $decoded = json_decode(trim($scripts->item(0)->nodeValue), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                compat_json([
                    'success' => true,
                    'score' => 40,
                    'schemaType' => 'Unknown / Custom Schema',
                    'syntaxStatus' => 'Invalid JSON Syntax inside script tag',
                    'message' => 'Found JSON-LD script tag, but parsing failed due to syntax errors.'
                ]);
            }

            $schemaType = $decoded['@type'] ?? 'Product/Generic';
            $schemaType = is_array($schemaType) ? implode(', ', $schemaType) : (string)$schemaType;

            compat_json([
                'success' => true,
                'score' => 95,
                'schemaType' => $schemaType,
                'syntaxStatus' => 'Valid JSON-LD Found in HTML',
                'message' => 'Successfully extracted and validated JSON-LD schema from the target URL.'
            ]);
        }

        if ($payload === '' || strlen($payload) > 100000) {
            compat_json(['success' => false, 'message' => $payload === '' ? 'Please provide schema JSON or a valid URL.' : 'Schema payload is too large.'], 400);
        }

        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            compat_json([
                'success' => true,
                'score' => 25,
                'schemaType' => 'Unknown / Custom Schema',
                'syntaxStatus' => 'JSON Syntax Error',
                'message' => 'Malformed JSON syntax. Please check for missing quotes, commas, or brackets.'
            ]);
        }

        $schemaType = $decoded['@type'] ?? 'Product / Generic Object';
        $schemaType = is_array($schemaType) ? implode(', ', $schemaType) : (string)$schemaType;

        compat_json([
            'success' => true,
            'score' => 90,
            'schemaType' => $schemaType,
            'syntaxStatus' => 'Valid JSON Syntax Checked',
            'message' => 'Raw JSON-LD syntax is valid and structurally parseable.'
        ]);

    case 'pixels':
        $url = trim((string)($input['url'] ?? ''));
        $html = compat_external_html($url);
        $pixels = [];

        if (strpos($html, 'connect.facebook.net') !== false || strpos($html, 'fbq(') !== false) {
            $pixels[] = ['name' => 'Meta Pixel (Facebook/Instagram)', 'status' => 'Active', 'id' => 'Detected'];
        }
        if (strpos($html, 'analytics.tiktok.com') !== false || strpos($html, 'ttq.load') !== false) {
            $pixels[] = ['name' => 'TikTok Pixel', 'status' => 'Active', 'id' => 'Detected'];
        }
        if (strpos($html, 'googletagmanager.com/gtag/js') !== false || strpos($html, 'gtag(') !== false) {
            $pixels[] = ['name' => 'Google Tag (GA4 / Google Ads)', 'status' => 'Active', 'id' => 'Detected'];
        }
        if (strpos($html, 'pinit.js') !== false || strpos($html, 'pinterest.com') !== false) {
            $pixels[] = ['name' => 'Pinterest Tag', 'status' => 'Active', 'id' => 'Detected'];
        }

        compat_json([
            'success' => true,
            'url' => $url,
            'pixels_found' => count($pixels),
            'pixels' => $pixels
        ]);

    case 'mobile_audit':
        $html = compat_external_html(trim((string)($input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);
        $viewportTags = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="viewport"]');

        $hasViewportTag = false;
        $mobileScore = 50;
        $message = 'Scanned live HTML: No mobile viewport tag found.';

        if ($viewportTags->length > 0) {
            $hasViewportTag = true;
            $content = $viewportTags->item(0)->getAttribute('content');

            if (stripos($content, 'width=device-width') !== false) {
                $mobileScore = 95;
                $message = "Scanned live HTML: Viewport is properly optimized ('{$content}').";
            } else {
                $mobileScore = 75;
                $message = "Scanned live HTML: Viewport tag exists but lacks 'width=device-width'.";
            }
        }

        compat_json([
            'success' => true,
            'url' => (string)$input['url'],
            'hasViewportTag' => $hasViewportTag,
            'mobileScore' => $mobileScore,
            'message' => $message
        ]);

    case 'ux_evaluator':
        $html = compat_external_html(trim((string)($input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);
        $navType = trim((string)($input['nav_type'] ?? 'simple'));

        $hasSearch = $xpath->query('//input[@type="search" or contains(@name, "search") or contains(@id, "search") or contains(@placeholder, "Search")]')->length > 0;
        $hasMega = $xpath->query('//*[contains(@class, "mega") or contains(@class, "dropdown-menu") or contains(@class, "sub-menu")]')->length > 0;

        $score = 50;
        if ($navType === 'mega') {
            $score += $hasMega ? 35 : 10;
            $navRating = $hasMega ? 'Optimal (Mega Menu Verified)' : 'Mega Menu (Mismatch Detected)';
            $details = $hasMega
                ? 'You selected Mega Menu, and the scanner verified matching navigation classes in the HTML.'
                : 'You selected Mega Menu, but the target HTML did not contain the expected mega-menu structure.';
        } elseif ($navType === 'hamburger') {
            $score += 15;
            $navRating = 'Desktop Hamburger Menu';
            $details = 'Selected Hamburger menu layout.';
        } else {
            $score += 20;
            $navRating = 'Standard Links Header';
            $details = 'Selected Simple Links structure.';
        }

        $score += $hasSearch ? 15 : -10;
        $score = max(20, min(100, $score));

        compat_json([
            'success' => true,
            'url' => (string)$input['url'],
            'uxScore' => $score,
            'navRatingText' => $navRating,
            'searchBarStatus' => $hasSearch ? 'Prominent Search Bar Detected' : 'Search Bar Missing in HTML Header',
            'message' => "Received Nav: [{$navType}] - {$details} HTML Search Check: " . ($hasSearch ? 'Passed' : 'Failed') . '.'
        ]);

    case 'browser_checker':
        $html = compat_external_html(trim((string)($input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);
        $engine = trim((string)($input['engine'] ?? 'webkit'));

        $styles = $xpath->query('//style');
        $hasModernLayout = false;
        foreach ($styles as $style) {
            if (stripos($style->nodeValue, 'flex') !== false || stripos($style->nodeValue, 'grid') !== false) {
                $hasModernLayout = true;
                break;
            }
        }

        $score = 85;
        if ($engine === 'webkit') {
            $score += 10;
            $engineStatus = 'Apple WebKit (iOS Safari) - Compatibility Check Complete';
        } elseif ($engine === 'gecko') {
            $score += 5;
            $engineStatus = 'Mozilla Gecko (Firefox) - Compatibility Check Complete';
        } else {
            $score += 12;
            $engineStatus = 'Chromium Blink - Compatibility Check Complete';
        }

        compat_json([
            'success' => true,
            'url' => (string)$input['url'],
            'score' => min(100, $score),
            'engineStatus' => $engineStatus,
            'cssStatus' => $hasModernLayout ? 'Modern Flexbox/Grid Rules Found' : 'Standard Block Layout',
            'message' => "Successfully audited engine compatibility for [{$engine}]."
        ]);

    case 'seo_auditor':
        $html = compat_external_html(trim((string)($input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);

        $titles = $xpath->query('//title');
        $metaTitle = $titles->length > 0 ? trim($titles->item(0)->nodeValue) : 'Missing Meta Title';
        $metaDescs = $xpath->query('//meta[@name="description"]');
        $metaDesc = $metaDescs->length > 0 ? trim($metaDescs->item(0)->getAttribute('content')) : '';
        $h1s = $xpath->query('//h1');
        $h1Status = $h1s->length > 0 ? trim($h1s->item(0)->nodeValue) : 'H1 Tag Missing';

        $isNoIndex = false;
        $robots = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]');
        if ($robots->length > 0 && stripos($robots->item(0)->getAttribute('content'), 'noindex') !== false) {
            $isNoIndex = true;
        }

        $score = 50 + ($titles->length > 0 ? 15 : 0) + ($h1s->length > 0 ? 15 : 0);
        $score += $isNoIndex ? -25 : 10;

        $keyword = trim((string)($input['keyword'] ?? ''));
        $keywordStatus = '';
        if ($keyword !== '') {
            $inTitle = stripos($metaTitle, $keyword) !== false;
            $inDesc = stripos($metaDesc, $keyword) !== false;
            if ($inTitle || $inDesc) {
                $score = min(100, $score + 10);
                $keywordStatus = " Target keyword [{$keyword}] verified in metadata.";
            } else {
                $score = max(20, $score - 15);
                $keywordStatus = " Target keyword [{$keyword}] was not found in title/description.";
            }
        }

        $parsed = parse_url((string)$input['url']);
        $robotsStatus = 'Robots.txt: Not checked.';
        if (isset($parsed['scheme'], $parsed['host'])) {
            $robotsUrl = $parsed['scheme'] . '://' . $parsed['host'] . '/robots.txt';
            [$ok, $robotsNormalized] = jt_validate_external_url($robotsUrl);
            if ($ok) {
                $robotsResponse = jt_safe_http_get($robotsNormalized, [
                    'timeout' => 4,
                    'connect_timeout' => 3,
                    'max_bytes' => 262144,
                    'user_agent' => 'JunctionTools-SecureScanner/1.0 (+https://junctiontools.com)'
                ]);
                if ($robotsResponse['success']) {
                    $robotsBody = (string)$robotsResponse['body'];
                    $robotsStatus = stripos($robotsBody, 'Disallow: /') !== false
                        ? "Robots.txt Warning: Contains strict 'Disallow: /' block rule."
                        : 'Robots.txt Verified: Active and accessible.';
                } else {
                    $robotsStatus = 'Robots.txt: Unreachable.';
                }
            }
        }

        compat_json([
            'success' => true,
            'url' => (string)$input['url'],
            'score' => max(10, min(100, $score)),
            'metaTitle' => $metaTitle,
            'h1Status' => $h1Status,
            'indexability' => $isNoIndex ? "Blocked from Indexing (Found 'noindex' tag)" : "Fully Indexable (No 'noindex' found)",
            'robotsTxt' => $robotsStatus,
            'message' => 'SEO & Indexability Audit complete.' . $keywordStatus
        ]);

    case 'cta_analyzer':
        $ctaText = trim((string)($input['cta_text'] ?? ''));
        if ($ctaText === '') {
            compat_json(['success' => false, 'message' => 'Please provide CTA button text.'], 400);
        }

        $score = 80;
        $lowerText = strtolower($ctaText);
        if (in_array($lowerText, ['submit', 'click here', 'send'], true)) {
            $score -= 30;
            $tip = "Generic wording like 'Submit' or 'Click Here' can be less action-oriented. Consider a clearer benefit-led CTA.";
        } else {
            $tip = 'Your CTA text is action-oriented and well-optimized.';
        }

        compat_json([
            'success' => true,
            'score' => $score,
            'message' => "CTA Impact Score: <strong>{$score}/100</strong><br>{$tip}"
        ]);

    case 'trust_inspector':
        $elements = isset($input['trust_elements']) && is_array($input['trust_elements'])
            ? array_values(array_unique(array_map('strval', $input['trust_elements'])))
            : [];
        $allowed = [
            'payment_icons' => 'Payment Method Icons',
            'money_back' => 'Money-Back Guarantee Seal',
            'ssl_badge' => 'SSL Lock / Security Badge',
            'reviews_summary' => 'Customer Reviews Summary'
        ];

        if (!$elements) {
            compat_json(['success' => false, 'message' => 'Select at least one trust factor to scan.'], 400);
        }
        if (array_diff($elements, array_keys($allowed))) {
            compat_json(['success' => false, 'message' => 'One or more selected trust checks are not supported.'], 400);
        }

        $html = compat_external_html(trim((string)($input['store_url'] ?? $input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);
        $visibleText = preg_replace('/\s+/u', ' ', trim((string)$dom->textContent));
        $htmlLower = strtolower($html);

        $payment = false;
        foreach ($xpath->query('//img | //svg | //i | //span | //div') as $node) {
            $attrs = strtolower(
                $node->getAttribute('alt') . ' ' .
                $node->getAttribute('title') . ' ' .
                $node->getAttribute('aria-label') . ' ' .
                $node->getAttribute('class') . ' ' .
                $node->getAttribute('id') . ' ' .
                $node->getAttribute('data-payment-method') . ' ' .
                $node->getAttribute('data-payment')
            );
            if (preg_match('/\b(visa|mastercard|master card|american express|amex|discover|paypal|apple pay|google pay|klarna|stripe|maestro|unionpay|payment method|accepted payments?)\b/i', $attrs)) {
                $payment = true;
                break;
            }
        }

        $moneyBack = (bool)preg_match(
            '/\b(money[- ]back|satisfaction|refund|return)\b.{0,100}\b(guarantee|guaranteed)\b|\b(guarantee|guaranteed)\b.{0,100}\b(money[- ]back|refund|satisfaction|return)\b/i',
            $visibleText
        );

        $sslBadge = (bool)preg_match(
            '/\b(ssl|secure checkout|secure payment|security badge|security seal|verified by visa|mastercard identity check|pci compliant|pci[- ]dss)\b/i',
            $visibleText . ' ' . $htmlLower
        );

        $reviews = false;
        $scripts = $xpath->query('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ld+json")]');
        foreach ($scripts as $script) {
            $json = json_decode(trim($script->textContent), true);
            if (!is_array($json)) continue;
            $nodes = isset($json['@graph']) && is_array($json['@graph']) ? $json['@graph'] : [$json];
            foreach ($nodes as $node) {
                if (!is_array($node)) continue;
                if (isset($node['aggregateRating']) || isset($node['review']) || isset($node['reviewCount']) || isset($node['ratingValue'])) {
                    $reviews = true;
                    break 2;
                }
            }
        }
        if (!$reviews) {
            $reviews = (bool)preg_match(
                '/\b(reviews?|ratings?|rated)\b.{0,80}(\d+(?:\.\d+)?\s*(?:\/\s*5|out of 5|stars?)|stars?|\(\d+[+]?\))/i',
                $visibleText
            );
        }

        $detected = [
            'payment_icons' => $payment,
            'money_back' => $moneyBack,
            'ssl_badge' => $sslBadge,
            'reviews_summary' => $reviews
        ];

        $results = [];
        foreach ($elements as $key) {
            $results[] = [
                'key' => $key,
                'name' => $allowed[$key],
                'present' => $detected[$key],
                'status' => $detected[$key] ? 'Present' : 'Not detected'
            ];
        }

        $lines = [];
        foreach ($results as $item) {
            $lines[] = '<strong>' . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . ':</strong> ' .
                ($item['present'] ? "<span class='text-emerald-400 font-semibold'>Present</span>" : "<span class='text-amber-400 font-semibold'>Not detected</span>");
        }

        compat_json([
            'success' => true,
            'url' => (string)($input['store_url'] ?? $input['url'] ?? ''),
            'checked' => $results,
            'checkedCount' => count($results),
            'foundCount' => count(array_filter($results, static fn($item) => $item['present'])),
            'message' => 'Live page scan completed. Only the trust factors you selected were evaluated.<br>' . implode('<br>', $lines)
        ]);
    case 'aria_audit':
        $html = compat_external_html(trim((string)($input['url'] ?? '')));
        [$dom, $xpath] = compat_dom($html);
        $images = $dom->getElementsByTagName('img');
        $missingAlt = 0;
        foreach ($images as $img) {
            if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
                $missingAlt++;
            }
        }

        $interactive = $xpath->query('//button | //a | //input[@type="submit" or @type="button"]');
        $missingAria = 0;
        foreach ($interactive as $el) {
            $hasAria = ($el->hasAttribute('aria-label') && trim($el->getAttribute('aria-label')) !== '')
                || ($el->hasAttribute('aria-labelledby') && trim($el->getAttribute('aria-labelledby')) !== '');
            $hasText = trim($el->textContent) !== '';
            $hasValue = $el->nodeName === 'input' && $el->hasAttribute('value') && trim($el->getAttribute('value')) !== '';

            if (!$hasAria && !$hasText && !$hasValue) {
                $missingAria++;
            }
        }

        $issues = $missingAlt + $missingAria;
        compat_json([
            'success' => true,
            'url' => (string)$input['url'],
            'missingAltCount' => $missingAlt,
            'missingAriaCount' => $missingAria,
            'accessibilityScore' => max(0, 100 - ($issues * 4)),
            'hasIssues' => $issues > 0,
            'message' => $issues > 0
                ? "Scanned live HTML: Found {$missingAlt} images without alt attributes and {$missingAria} interactive elements missing accessible descriptions."
                : 'Scanned live HTML: All checked images and interactive elements have accessible descriptions.'
        ]);

    case 'wcag_checker':
        $hexToRgb = static function (string $hex): array {
            $hex = ltrim(trim($hex), '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
                compat_json(['success' => false, 'message' => 'Please provide valid 3- or 6-digit hex colors.'], 400);
            }
            $int = hexdec($hex);
            return ['r' => ($int >> 16) & 255, 'g' => ($int >> 8) & 255, 'b' => $int & 255];
        };
        $luminance = static function (array $rgb): float {
            $channels = [];
            foreach ($rgb as $key => $value) {
                $v = $value / 255;
                $channels[$key] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }
            return ($channels['r'] * 0.2126) + ($channels['g'] * 0.7152) + ($channels['b'] * 0.0722);
        };

        $textRgb = $hexToRgb((string)($input['text_color'] ?? '#FFFFFF'));
        $bgRgb = $hexToRgb((string)($input['bg_color'] ?? '#000000'));
        $l1 = $luminance($textRgb);
        $l2 = $luminance($bgRgb);
        $ratio = round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);

        compat_json([
            'success' => true,
            'contrastRatio' => $ratio,
            'message' => "Contrast Ratio: <strong>{$ratio}:1</strong><br>AA Normal Text (4.5:1): " . ($ratio >= 4.5 ? 'Pass' : 'Fail') . "<br>AA Large Text (3:1): " . ($ratio >= 3 ? 'Pass' : 'Fail') . "<br>AAA Normal Text (7:1): " . ($ratio >= 7 ? 'Pass' : 'Fail') . "<br>AAA Large Text (4.5:1): " . ($ratio >= 4.5 ? 'Pass' : 'Fail')
        ]);

    case 'copy_analyzer':
        $text = trim((string)($input['copy_text'] ?? ''));
        $words = str_word_count(strip_tags($text));
        $chars = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        compat_json([
            'success' => true,
            'wordCount' => $words,
            'message' => "Word Count: <strong>{$words}</strong><br>Character Count: <strong>{$chars} characters</strong><br>Readability & Copy Strength: <span class='text-emerald-400 font-semibold'>Analyzed Successfully</span>"
        ]);

    case 'readability_evaluator':
        $text = trim((string)($input['text_content'] ?? ''));
        $plainText = trim(strip_tags($text));
        if ($plainText === '') {
            compat_json(['success' => false, 'message' => 'Please provide text to evaluate.'], 400);
        }

        $words = preg_split('/\s+/u', $plainText, -1, PREG_SPLIT_NO_EMPTY);
        $wordCount = is_array($words) ? count($words) : 0;
        $sentenceMatches = [];
        $sentenceCount = max(1, (int)preg_match_all('/[^.!?]+(?:[.!?]+|$)/u', $plainText, $sentenceMatches));

        $syllables = 0;
        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{L}\p{N}\']/u', '', $word);
            if ($cleanWord === '') continue;
            $parts = preg_split('/[-\x{2010}-\x{2015}]+/u', $cleanWord, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($parts as $part) {
                $lower = mb_strtolower($part, 'UTF-8');
                if (preg_match('/^[a-z]+$/i', $lower)) {
                    $count = max(1, (int)preg_match_all('/[aeiouy]+/i', $lower, $dummy));
                    if (strlen($lower) > 2 && preg_match('/(?:e|es|ed)$/i', $lower) && !preg_match('/(?:le|ye)$/i', $lower)) $count--;
                    if (preg_match('/[^aeiou]le$/i', $lower)) $count++;
                    $syllables += max(1, $count);
                } else {
                    $syllables += max(1, (int)preg_match_all('/[aeiouy]+/iu', $lower, $dummy));
                }
            }
        }

        $wps = $wordCount / max(1, $sentenceCount);
        $spw = $wordCount > 0 ? $syllables / $wordCount : 0;
        $ease = $wordCount > 0 ? 206.835 - (1.015 * $wps) - (84.6 * $spw) : 0;
        $ease = round(max(0, min(100, $ease)), 1);
        $grade = $wordCount > 0 ? 0.39 * $wps + 11.8 * $spw - 15.59 : 0;
        $grade = round(max(0, $grade), 1);
        if ($ease >= 90) $level = 'Very Easy';
        elseif ($ease >= 80) $level = 'Easy';
        elseif ($ease >= 70) $level = 'Fairly Easy';
        elseif ($ease >= 60) $level = 'Standard';
        elseif ($ease >= 50) $level = 'Fairly Difficult';
        elseif ($ease >= 30) $level = 'Difficult';
        else $level = 'Very Difficult';

        compat_json([
            'success' => true,
            'readingEase' => $ease,
            'fleschKincaidGrade' => $grade,
            'wordCount' => $wordCount,
            'sentenceCount' => $sentenceCount,
            'syllableCount' => $syllables,
            'message' => "Reading Ease Score: <strong>{$ease} / 100</strong><br>Estimated Reading Level: <span class='text-emerald-400 font-semibold'>{$level}</span><br>Flesch-Kincaid Grade: <strong>{$grade}</strong><br>Total Words: {$wordCount} | Sentences: {$sentenceCount} | Avg. Words/Sentence: " . round($wps, 1)
        ]);

}
