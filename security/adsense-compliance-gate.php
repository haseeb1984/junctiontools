<?php
declare(strict_types=1);

/**
 * Validate the repository's centralized AdSense integration contract.
 * This is a publication prerequisite, not a revenue/account check.
 */
function adsense_compliance_evaluate(?string $root = null): array
{
    $root = $root ?? dirname(__DIR__);
    $htaccess = $root . '/.htaccess';

    if (!is_file($htaccess)) {
        return ['allowed' => false, 'error_code' => 'adsense_integration_missing', 'message' => 'Missing .htaccess.'];
    }

    $source = (string) file_get_contents($htaccess);
    $publisher = 'ca-pub-2009027605349204';
    $scriptNeedle = 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $publisher;

    foreach ([
        '<IfModule mod_substitute.c>',
        'AddOutputFilterByType SUBSTITUTE text/html',
        'SubstituteMaxLineLength 10m',
        $scriptNeedle,
        's|</head>|',
    ] as $needle) {
        if (strpos($source, $needle) === false) {
            return ['allowed' => false, 'error_code' => 'adsense_contract_missing', 'message' => 'Missing AdSense integration contract: ' . $needle];
        }
    }

    if (!preg_match('/adsbygoogle\.js\?client=' . preg_quote($publisher, '/') . '[^\r\n]*crossorigin=\\\\?"anonymous\\\\?"/', $source)) {
        return ['allowed' => false, 'error_code' => 'adsense_script_malformed', 'message' => 'AdSense script tag contract is malformed.'];
    }

    if (substr_count($source, $publisher) !== 1) {
        return ['allowed' => false, 'error_code' => 'adsense_publisher_duplicate', 'message' => 'AdSense publisher ID must appear exactly once in .htaccess.'];
    }

    foreach (glob($root . '/*.html') ?: [] as $file) {
        $content = (string) file_get_contents($file);
        if (stripos($content, $scriptNeedle) !== false) {
            return ['allowed' => false, 'error_code' => 'adsense_duplicate_page_script', 'message' => 'AdSense publisher script is duplicated in ' . basename($file) . '.'];
        }
    }

    return [
        'allowed' => true,
        'error_code' => null,
        'message' => 'Centralized AdSense integration contract passed.',
        'publisher_id' => $publisher,
        'mode' => 'centralized-response-injection',
    ];
}
