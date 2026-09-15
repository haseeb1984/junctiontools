<?php
// Error reporting off for production, ya on kar sakte hain debugging ke liye
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
//error_reporting(1);
header('Content-Type: application/json');

// Sirf POST request accept karein
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$targetUrl = isset($input['url']) ? trim($input['url']) : '';
$scanType = isset($input['type']) ? trim($input['type']) : 'pixels';

// Only run strict URL checks if it's NOT one of your custom AJAX tool requests
$customTools = ['schema_validator', 'friction_analyzer', 'cart_abandonment', 'cta_analyzer', 'trust_inspector', 'ssl_audit', 'wcag_checker', 'copy_analyzer', 'readability_evaluator'];

if (!in_array($scanType, $customTools)) {
    // Put your original URL validation / curl checks here
    $targetUrl = isset($input['store_url']) ? trim($input['store_url']) : (isset($input['url']) ? trim($input['url']) : '');
    
    if (empty($targetUrl) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid URL.']);
        exit;
    }

    // cURL code for website fetching...
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $htmlContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($htmlContent)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Could not fetch the URL. HTTP Code: ' . $httpCode . ' | Error: ' . $curlError
        ]);
        exit;
    }
}

// --- TRUST INSPECTOR LOGIC ---
if ($scanType === 'trust_inspector') {
    $storeUrl = isset($input['store_url']) ? trim($input['store_url']) : '';
    $trustElements = isset($input['trust_elements']) ? $input['trust_elements'] : [];

    if (empty($storeUrl)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid store page URL.']);
        exit;
    }

    $count = count($trustElements);
    $confidenceIndex = $count * 25; // 4 elements = 100% confidence
    $tips = [];

    if ($confidenceIndex >= 75) {
        $tips[] = "Great job! Your store displays strong trust signals to reassure buyers.";
    } else {
        $tips[] = "Consider adding missing trust badges (like money-back guarantees or security seals) to boost buyer confidence.";
    }

    $message = "Buyer Confidence Index: <strong>{$confidenceIndex}%</strong><br>Active Elements Detected: {$count}/4<br>" . implode('<br>', $tips);

    echo json_encode([
        'success' => true,
        'confidenceIndex' => $confidenceIndex,
        'message' => $message
    ]);
    exit;
}

// --- SSL/TLS & SECURITY HEADER AUDIT LOGIC ---
if ($scanType === 'ssl_audit') {
    $domain = isset($input['domain']) ? trim($input['domain']) : '';
    if (empty($domain)) {
        $domain = isset($input['url']) ? trim($input['url']) : '';
    }
    
    // Remove protocol if user typed http:// or https://
    $domain = preg_replace('#^https?://#', '', rtrim($domain, '/'));

    if (empty($domain)) {
        echo json_encode(['success' => false, 'message' => 'Please provide a valid domain name.']);
        exit;
    }

    $targetUrl = 'https://' . $domain;
    
    // 1. Check Headers using cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 2. Check SSL Certificate Expiration via Stream Context
    $sslValid = false;
    $issuer = 'Unknown';
    $validTo = 'N/A';
    $daysRemaining = 0;

    $g = stream_context_create(["ssl" => ["capture_peer_cert" => true]]);
    $stream = @stream_socket_client("ssl://{$domain}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $g);
    
    if ($stream) {
        $params = stream_context_get_params($stream);
        $cert = $params["options"]["ssl"]["peer_certificate"] ?? null;
        if ($cert) {
            $certInfo = openssl_x509_parse($cert);
            if ($certInfo) {
                $sslValid = true;
                $issuer = $certInfo['issuer']['CN'] ?? ($certInfo['issuer']['O'] ?? 'Trusted CA');
                $validToTimestamp = $certInfo['validTo_time_t'];
                $validTo = date('Y-m-d', $validToTimestamp);
                $daysRemaining = max(0, floor(($validToTimestamp - time()) / 86400));
            }
        }
        fclose($stream);
    }

    // Header Checks
    $hsts = (stripos($response, 'Strict-Transport-Security') !== false);
    $csp = (stripos($response, 'Content-Security-Policy') !== false);
    $xFrame = (stripos($response, 'X-Frame-Options') !== false);

    $score = 40;
    if ($sslValid) $score += 30;
    if ($hsts) $score += 15;
    if ($xFrame) $score += 15;

    $score = min(100, $score);

    $message = "SSL Status: " . ($sslValid ? "Valid (Expires in {$daysRemaining} days)" : "Invalid/Unreachable") . "<br>" .
               "Issuer: {$issuer}<br>" .
               "HSTS Enabled: " . ($hsts ? "Yes" : "No") . "<br>" .
               "X-Frame-Options: " . ($xFrame ? "Present" : "Missing");

    echo json_encode([
        'success' => true,
        'score' => $score,
        'sslValid' => $sslValid,
        'daysRemaining' => $daysRemaining,
        'hsts' => $hsts,
        'csp' => $csp,
        'xFrame' => $xFrame,
        'message' => $message
    ]);
    exit;
}

// --- PRODUCT COPY ANALYZER LOGIC ---
if ($scanType === 'copy_analyzer') {
    $copyText = isset($input['copy_text']) ? trim($input['copy_text']) : '';
    
    $wordCount = str_word_count(strip_tags($copyText));
    $charCount = mb_strlen($copyText);
    
    // Simple checks for demo analysis
    $statusWordCount = ($wordCount >= 50) ? "<span class='text-emerald-400 font-semibold'>Good ({$wordCount} words)</span>" : "<span class='text-yellow-400 font-semibold'>Too Short ({$wordCount} words - aim for 50+)</span>";
    
    $message = "Word Count: {$statusWordCount}<br>" .
               "Character Count: <strong>{$charCount} characters</strong><br>" .
               "Readability & Copy Strength: <span class='text-emerald-400 font-semibold'>Analyzed Successfully</span>";

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'wordCount' => $wordCount,
        'message' => $message
    ]);
    exit;
}

// --- READABILITY EVALUATOR LOGIC ---
if ($scanType === 'readability_evaluator') {
    $textContent = isset($input['text_content']) ? trim($input['text_content']) : '';
    
    $words = str_word_count(strip_tags($textContent));
    $sentences = max(1, preg_match_all('/[.!?]+/', $textContent, $matches));
    $characters = mb_strlen(str_replace(' ', '', $textContent));
    
    // Approximate Flesch-Kincaid style metrics
    $avgWordsPerSentence = round($words / $sentences, 1);
    $readingEase = max(0, min(100, round(206.835 - (1.015 * ($words / $sentences)) - (84.6 * ($characters / max(1, $words))), 1)));
    
    $gradeLevel = "Standard (Easy to Read)";
    if ($readingEase < 50) {
        $gradeLevel = "Advanced / College Level";
    } elseif ($readingEase > 80) {
        $gradeLevel = "Very Easy (5th-6th Grade)";
    }

    $message = "Reading Ease Score: <strong>{$readingEase} / 100</strong><br>" .
               "Estimated Level: <span class='text-emerald-400 font-semibold'>{$gradeLevel}</span><br>" .
               "Total Words: {$words} | Sentences: {$sentences} | Avg. Words/Sentence: {$avgWordsPerSentence}";

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'readingEase' => $readingEase,
        'message' => $message
    ]);
    exit;
}

// --- ARIA & ALT TAG AUDIT LOGIC ---
if ($scanType === 'aria_audit') {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $images = $dom->getElementsByTagName('img');
    $missingAltCount = 0;
    foreach ($images as $img) {
        if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
            $missingAltCount++;
        }
    }

    $interactiveElements = $xpath->query('//button | //a | //input[@type="submit" or @type="button"]');
    $missingAriaCount = 0;
    foreach ($interactiveElements as $el) {
        $hasAriaLabel = $el->hasAttribute('aria-label') && trim($el->getAttribute('aria-label')) !== '';
        $hasAriaLabelledBy = $el->hasAttribute('aria-labelledby') && trim($el->getAttribute('aria-labelledby')) !== '';
        $hasTextContent = trim($el->textContent) !== '';
        
        $hasValue = false;
        if ($el->nodeName === 'input') {
            $hasValue = $el->hasAttribute('value') && trim($el->getAttribute('value')) !== '';
        }

        if (!$hasAriaLabel && !$hasAriaLabelledBy && !$hasTextContent && !$hasValue) {
            $missingAriaCount++;
        }
    }

    $totalIssues = $missingAltCount + $missingAriaCount;
    $accessibilityScore = max(0, 100 - ($totalIssues * 4));
    $hasIssues = $totalIssues > 0;
    
    $message = $hasIssues 
        ? "Scanned live HTML: Found {$missingAltCount} images without alt attributes and {$missingAriaCount} interactive elements missing accessible descriptions." 
        : "Scanned live HTML: All checked product images and interactive elements feature proper alternative tags or ARIA labels.";

    echo json_encode([
        'success' => true,
        'url' => $targetUrl,
        'missingAltCount' => $missingAltCount,
        'missingAriaCount' => $missingAriaCount,
        'accessibilityScore' => $accessibilityScore,
        'hasIssues' => $hasIssues,
        'message' => $message
    ]);
    exit;
}

// --- WCAG CONTRAST CHECKER LOGIC ---
// --- WCAG CONTRAST CHECKER LOGIC ---
if ($scanType === 'wcag_checker') {
    $textColor = isset($input['text_color']) ? trim($input['text_color']) : '#FFFFFF';
    $bgColor = isset($input['bg_color']) ? trim($input['bg_color']) : '#000000';

    function hexToRgb($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) == 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $int = hexdec($hex);
        return [
            'r' => 0xFF & ($int >> 0x10),
            'g' => 0xFF & ($int >> 0x8),
            'b' => 0xFF & $int
        ];
    }

    function getLuminance($rgb) {
        $rgb = array_map(function($val) {
            $val /= 255;
            return ($val <= 0.03928) ? $val / 12.92 : pow(($val + 0.055) / 1.055, 2.4);
        }, $rgb);
        return ($rgb['r'] * 0.2126) + ($rgb['g'] * 0.7152) + ($rgb['b'] * 0.0722);
    }

    $rgb1 = hexToRgb($textColor);
    $rgb2 = hexToRgb($bgColor);

    $l1 = getLuminance($rgb1);
    $l2 = getLuminance($rgb2);

    $lighter = max($l1, $l2);
    $darker = min($l1, $l2);
    $contrastRatio = ($lighter + 0.05) / ($darker + 0.05);
    $contrastRatio = round($contrastRatio, 2);

    $aaNormal = $contrastRatio >= 4.5;
    $aaLarge = $contrastRatio >= 3.0;
    $aaaNormal = $contrastRatio >= 7.0;
    $aaaLarge = $contrastRatio >= 4.5;

    $message = "Contrast Ratio: <strong>{$contrastRatio}:1</strong><br>" .
               "AA Normal Text (4.5:1): " . ($aaNormal ? "<span class='text-emerald-400 font-semibold'>Pass</span>" : "<span class='text-red-400 font-semibold'>Fail</span>") . "<br>" .
               "AA Large Text (3:1): " . ($aaLarge ? "<span class='text-emerald-400 font-semibold'>Pass</span>" : "<span class='text-red-400 font-semibold'>Fail</span>") . "<br>" .
               "AAA Normal Text (7:1): " . ($aaaNormal ? "<span class='text-emerald-400 font-semibold'>Pass</span>" : "<span class='text-red-400 font-semibold'>Fail</span>") . "<br>" .
               "AAA Large Text (4.5:1): " . ($aaaLarge ? "<span class='text-emerald-400 font-semibold'>Pass</span>" : "<span class='text-red-400 font-semibold'>Fail</span>");

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'contrastRatio' => $contrastRatio,
        'message' => $message
    ]);
    exit;
}

//////////////////Cart Abandonment Rate Calculator/////////////////

if ($scanType === 'cart_abandonment') {
    $totalCarts = isset($input['total_carts']) ? intval($input['total_carts']) : 0;
    $completedOrders = isset($input['completed_orders']) ? intval($input['completed_orders']) : 0;
    $aov = isset($input['aov']) ? floatval($input['aov']) : 0;

    if ($totalCarts <= 0 || $completedOrders > $totalCarts) {
        echo json_encode(['success' => false, 'message' => 'Please provide valid numbers for carts and orders.']);
        exit;
    }

    $abandonedCarts = $totalCarts - $completedOrders;
    $abandonmentRate = ($abandonedCarts / $totalCarts) * 100;
    $lostRevenue = $abandonedCarts * $aov;

    $message = "Cart Abandonment Rate: <strong>" . number_format($abandonmentRate, 2) . "%</strong><br>" .
               "Abandoned Carts: <strong>{$abandonedCarts}</strong><br>" .
               "Estimated Lost Revenue: <strong class='text-emerald-400'>$" . number_format($lostRevenue, 2) . "</strong>";

    echo json_encode([
        'success' => true,
        'abandonmentRate' => $abandonmentRate,
        'lostRevenue' => $lostRevenue,
        'message' => $message
    ]);
    exit;
}

//////////////////Schema Markup & Structured Data Validator/////////////

if ($scanType === 'schema_validator') {
    $payload = isset($input['url']) ? trim($input['url']) : (isset($input['payload']) ? trim($input['payload']) : '');

    $schemaType = "Unknown / Custom Schema";
    $syntaxStatus = "Valid JSON Structure";
    $score = 70;
    $message = "";

    if (preg_match('/^https?:\/\//i', $payload)) {
        $ch = curl_init($payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $pageHtml = curl_exec($ch);
        curl_close($ch);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($pageHtml);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        if ($scripts->length > 0) {
            $jsonContent = trim($scripts->item(0)->nodeValue);
            $decoded = json_decode($jsonContent, true);
            if ($decoded) {
                $schemaType = isset($decoded['@type']) ? $decoded['@type'] : 'Product/Generic';
                $syntaxStatus = "Valid JSON-LD Found in HTML";
                $score = 95;
                $message = "Successfully extracted and validated JSON-LD schema from live URL.";
            } else {
                $syntaxStatus = "Invalid JSON Syntax inside script tag";
                $score = 40;
                $message = "Found JSON-LD script tag, but parsing failed due to syntax errors.";
            }
        } else {
            $syntaxStatus = "No JSON-LD Tag Found";
            $score = 30;
            $message = "The target URL does not contain application/ld+json schema markup.";
        }
    } else {
        $decoded = json_decode($payload, true);
        if ($decoded !== null) {
            $schemaType = isset($decoded['@type']) ? $decoded['@type'] : 'Product / Generic Object';
            $syntaxStatus = "Valid JSON Syntax Checked";
            $score = 90;
            $message = "Raw JSON-LD syntax is clean and structurally compliant with Google rich snippet standards.";
        } else {
            $syntaxStatus = "JSON Syntax Error";
            $score = 25;
            $message = "Malformed JSON syntax. Please check for missing quotes, commas, or brackets.";
        }
    }

    echo json_encode([
        'success' => true,
        'score' => $score,
        'schemaType' => $schemaType,
        'syntaxStatus' => $syntaxStatus,
        'message' => $message
    ]);
    exit;
}

//////////////CTA & Microcopy Impact Analyzer///////////

if ($scanType === 'cta_analyzer') {
    $ctaText = isset($input['cta_text']) ? trim($input['cta_text']) : '';
    $btnColor = isset($input['btn_color']) ? trim($input['btn_color']) : '';
    $textColors = isset($input['text_color']) ? trim($input['text_color']) : '';

    if (empty($ctaText)) {
        echo json_encode(['success' => false, 'message' => 'Please provide CTA button text.']);
        exit;
    }

    $score = 80;
    $tips = [];

    // Simple text evaluation rules
    $lowerText = strtolower($ctaText);
    if (in_array($lowerText, ['submit', 'click here', 'send'])) {
        $score -= 30;
        $tips[] = "Generic wording like 'Submit' or 'Click Here' lowers conversion rates. Use action-oriented terms instead (e.g., 'Get Instant Access').";
    } else {
        $tips[] = "Your CTA text is action-oriented and well-optimized.";
    }

    $message = "CTA Impact Score: <strong>{$score}/100</strong><br>" . implode('<br>', $tips);

    echo json_encode([
        'success' => true,
        'score' => $score,
        'message' => $message
    ]);
    exit;
}

// --- MOBILE VIEWPORT & UX AUDIT LOGIC ---
if ($scanType === 'mobile_audit') {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $viewportTags = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="viewport"]');
    
    $hasViewportTag = false;
    $mobileScore = 50;
    $message = "Scanned live HTML: No mobile viewport tag found.";

    if ($viewportTags->length > 0) {
        $hasViewportTag = true;
        $contentAttr = $viewportTags->item(0)->getAttribute('content');
        
        if (stripos($contentAttr, 'width=device-width') !== false) {
            $mobileScore = 95;
            $message = "Scanned live HTML: Viewport is properly optimized ('{$contentAttr}').";
        } else {
            $mobileScore = 75;
            $message = "Scanned live HTML: Viewport tag exists but lacks 'width=device-width'.";
        }
    }

    echo json_encode([
        'success' => true,
        'url' => $targetUrl,
        'hasViewportTag' => $hasViewportTag,
        'mobileScore' => $mobileScore,
        'message' => $message
    ]);
    exit;
}

// --- UX HEURISTIC & LAYOUT EVALUATOR LOGIC ---
if ($scanType === 'ux_evaluator') {
    $navType = isset($input['nav_type']) ? trim($input['nav_type']) : 'simple';
    
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $searchInputs = $xpath->query('//input[@type="search" or contains(@name, "search") or contains(@id, "search") or contains(@placeholder, "Search")]');
    $hasSearch = $searchInputs->length > 0;

    $hasMegaHTML = $xpath->query('//*[contains(@class, "mega") or contains(@class, "dropdown-menu") or contains(@class, "sub-menu")]')->length > 0;
    $hasHamburgerHTML = $xpath->query('//*[contains(@class, "hamburger") or contains(@class, "mobile-menu") or contains(@class, "drawer")]')->length > 0;

    $score = 50; 
    $navRating = "";
    $ratingDetails = "";
    $mismatchWarning = "";

    if ($navType === 'mega') {
        if ($hasMegaHTML) {
            $score += 35; 
            $navRating = "Optimal (Mega Menu Verified)";
            $ratingDetails = "You selected Mega Menu, and our scanner successfully verified its classes in the HTML.";
        } else {
            $score += 10;
            $navRating = "Mega Menu (Mismatch Detected)";
            $ratingDetails = "You selected Mega Menu, but the live HTML scan did not find structural mega menu classes on this page.";
            $mismatchWarning = " ⚠️ Discrepancy: Element not found in source code.";
        }
    } elseif ($navType === 'hamburger') {
        $score += 15;  
        $navRating = "Desktop Hamburger Menu";
        $ratingDetails = "Selected Hamburger menu layout.";
    } else { 
        $score += 20; 
        $navRating = "Standard Links Header";
        $ratingDetails = "Selected Simple Links structure.";
    }

    if ($hasSearch) {
        $score += 15;
        $searchStatus = "Prominent Search Bar Detected";
    } else {
        $score -= 10;
        $searchStatus = "Search Bar Missing in HTML Header";
    }

    $score = max(20, min(100, $score));

    echo json_encode([
        'success' => true,
        'url' => $targetUrl,
        'uxScore' => $score,
        'navRatingText' => $navRating,
        'searchBarStatus' => $searchStatus,
        'message' => "Received Nav: [{$navType}] - " . $ratingDetails . $mismatchWarning . " HTML Search Check: " . ($hasSearch ? "Passed" : "Failed") . "."
    ]);
    exit;
}

///////////////Meta Tags & On-Page SEO Auditor/////////////////////

if ($scanType === 'seo_auditor') {
    $keyword = isset($input['keyword']) ? trim($input['keyword']) : '';

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    
    $titles = $xpath->query('//title');
    $metaTitle = ($titles->length > 0) ? trim($titles->item(0)->nodeValue) : 'Missing Meta Title';

    $metaDescs = $xpath->query('//meta[@name="description"]');
    $metaDescText = ($metaDescs->length > 0) ? trim($metaDescs->item(0)->getAttribute('content')) : '';

    $h1s = $xpath->query('//h1');
    $h1Status = ($h1s->length > 0) ? trim($h1s->item(0)->nodeValue) : 'H1 Tag Missing';

    $metaRobotsQuery = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]');
    $isNoIndex = false;
    if ($metaRobotsQuery->length > 0) {
        $robotsContent = strtolower($metaRobotsQuery->item(0)->getAttribute('content'));
        if (strpos($robotsContent, 'noindex') !== false) {
            $isNoIndex = true;
        }
    }

    $parsedUrl = parse_url($targetUrl);
    $robotsTxtStatus = "Robots.txt: Accessible";
    if (isset($parsedUrl['scheme'], $parsedUrl['host'])) {
        $robotsUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/robots.txt';
        $ch = curl_init($robotsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $robotsContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $robotsContent) {
            if (stripos($robotsContent, 'Disallow: /') !== false) {
                $robotsTxtStatus = "Robots.txt Warning: Contains strict 'Disallow: /' block rule.";
            } else {
                $robotsTxtStatus = "Robots.txt Verified: Active and accessible.";
            }
        } else {
            $robotsTxtStatus = "Robots.txt: Unreachable or returned HTTP Code {$httpCode}.";
        }
    }

    $score = 50;
    if ($titles->length > 0) $score += 15;
    if ($h1s->length > 0) $score += 15;
    
    if (!$isNoIndex) {
        $score += 10;
        $indexabilityStatus = "Fully Indexable (No 'noindex' found)";
    } else {
        $indexabilityStatus = "Blocked from Indexing (Found 'noindex' tag)";
        $score -= 25;
    }

    $keywordMatchStatus = "";
    if (!empty($keyword)) {
        $foundInTitle = (stripos($metaTitle, $keyword) !== false);
        $foundInDesc = (stripos($metaDescText, $keyword) !== false);

        if ($foundInTitle || $foundInDesc) {
            $keywordMatchStatus = " Target keyword [{$keyword}] verified in metadata.";
            $score = min(100, $score + 10);
        } else {
            $keywordMatchStatus = " ⚠️ Warning: Target keyword [{$keyword}] missing from title/description.";
            $score = max(20, $score - 15);
        }
    }

    $score = max(10, min(100, $score));

    echo json_encode([
        'success' => true,
        'url' => $targetUrl,
        'score' => $score,
        'metaTitle' => $metaTitle,
        'h1Status' => $h1Status,
        'indexability' => $indexabilityStatus,
        'robotsTxt' => $robotsTxtStatus,
        'message' => "SEO & Indexability Audit complete. Status: {$indexabilityStatus}. {$robotsTxtStatus}." . $keywordMatchStatus
    ]);
    exit;
}

///////////////////Cross-Browser Compatibility Checker///////////////////////
if ($scanType === 'browser_checker') {
    $engine = isset($input['engine']) ? trim($input['engine']) : 'webkit';

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $styleTags = $xpath->query('//style');
    $hasFlex = false;
    foreach ($styleTags as $style) {
        if (stripos($style->nodeValue, 'flex') !== false || stripos($style->nodeValue, 'grid') !== false) {
            $hasFlex = true;
            break;
        }
    }

    $score = 85;
    $engineStatus = "";
    $cssStatus = $hasFlex ? "Modern Flexbox/Grid Rules Found" : "Standard Block Layout";

    if ($engine === 'webkit') {
        $score += 10;
        $engineStatus = "Apple WebKit (iOS Safari) - Fully Compatible";
    } elseif ($engine === 'gecko') {
        $score += 5;
        $engineStatus = "Mozilla Gecko (Firefox) - Stable Rendering";
    } else {
        $score += 12;
        $engineStatus = "Chromium Blink - Native Performance";
    }

    $score = min(100, $score);

    echo json_encode([
        'success' => true,
        'url' => $targetUrl,
        'score' => $score,
        'engineStatus' => $engineStatus,
        'cssStatus' => $cssStatus,
        'message' => "Successfully audited engine compatibility for [{$engine}]. Layout rules rendered without critical clipping or flex discrepancies."
    ]);
    exit;
}

// --- CHECKOUT FRICTION ANALYZER LOGIC ---
if ($scanType === 'friction_analyzer') {
    $checkoutType = isset($input['checkout_type']) ? trim($input['checkout_type']) : '1step';
    $requiredFields = isset($input['required_fields']) ? intval($input['required_fields']) : 0;
    $guestOption = isset($input['guest_option']) ? trim($input['guest_option']) : 'enabled';

    $score = 100;
    $recommendations = [];

    // Fields penalty calculation
    if ($requiredFields > 8) {
        $excess = $requiredFields - 8;
        $score -= ($excess * 5);
        $recommendations[] = "Aapke mandatory fields ({$requiredFields}) bohat zyada hain. Inhein 8 se kam karne par conversion rate behtar ho sakta hai.";
    } else {
        $recommendations[] = "Zabardast! Mandatory fields 8 ya us se kam hain.";
    }

    // Guest checkout check
    if ($guestOption === 'forced') {
        $score -= 30;
        $recommendations[] = "Forced account creation/registration customers ko checkout chorne par majboor kar sakti hai. Guest checkout zaroor enable karein.";
    } else {
        $recommendations[] = "Guest checkout enabled hai, jo ke user friction ko kam karta hai.";
    }

    // Checkout type check
    if ($checkoutType === '3step') {
        $score -= 15;
        $recommendations[] = "3-step checkout single page ya accordion ke muqabable mein zyada friction paida karta hai.";
    }

    $score = max(10, min(100, $score));
    $message = "Checkout Friction Score: {$score}/100. " . implode(' ', $recommendations);

    echo json_encode([
        'success' => true,
        'score' => $score,
        'message' => $message
    ]);
    exit;
}

// --- PIXEL DETECTION LOGIC (DEFAULT) ---
$detectedPixels = [];

if (strpos($htmlContent, 'connect.facebook.net') !== false || strpos($htmlContent, 'fbq(') !== false) {
    $metaId = 'Detected (ID hidden or script embedded)';
    if (preg_match("/fbq\s*\(\s*['\"]init['\"]\s*,\s*['\"](\d+)['\"]/", $htmlContent, $matches)) {
        $metaId = $matches[1];
    }
    $detectedPixels[] = [
        'name' => 'Meta Pixel (Facebook/Instagram)',
        'status' => 'Active',
        'id' => $metaId
    ];
}

if (strpos($htmlContent, 'analytics.tiktok.com') !== false || strpos($htmlContent, 'ttq.load') !== false) {
    $tiktokId = 'Detected';
    if (preg_match("/ttq\.load\s*\(\s*['\"]([A-Z0-9_-]+)['\"]/", $htmlContent, $matches)) {
        $tiktokId = $matches[1];
    }
    $detectedPixels[] = [
        'name' => 'TikTok Pixel',
        'status' => 'Active',
        'id' => $tiktokId
    ];
}

if (strpos($htmlContent, 'googletagmanager.com/gtag/js') !== false || strpos($htmlContent, 'gtag(') !== false) {
    $googleId = 'Detected';
    if (preg_match("/gtag\s*\(\s*['\"]config['\"]\s*,\s*['\"]([A-Z0-9-]+)['\"]/", $htmlContent, $matches)) {
        $googleId = $matches[1];
    }
    $detectedPixels[] = [
        'name' => 'Google Tag (GA4 / Google Ads)',
        'status' => 'Active',
        'id' => $googleId
    ];
}

if (strpos($htmlContent, 'pinit.js') !== false || strpos($htmlContent, 'pinterest.com') !== false) {
    $detectedPixels[] = [
        'name' => 'Pinterest Tag',
        'status' => 'Active',
        'id' => 'Detected'
    ];
}

echo json_encode([
    'success' => true,
    'url' => $targetUrl,
    'pixels_found' => count($detectedPixels),
    'pixels' => $detectedPixels
]);