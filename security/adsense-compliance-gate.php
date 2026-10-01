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
    $injector = $root . '/inject_ads.php';

    if (!is_file($htaccess)) {
        return ['allowed' => false, 'error_code' => 'adsense_integration_missing', 'message' => 'Missing .htaccess.'];
    }
    if (!is_file($injector)) {
        return ['allowed' => false, 'error_code' => 'adsense_injector_missing', 'message' => 'Missing inject_ads.php.'];
    }

    $source = (string) file_get_contents($htaccess);
    $injectorSource = (string) file_get_contents($injector);
    $publisher = 'ca-pub-2009027605349204';
    $scriptNeedle = 'adsbygoogle.js?client=' . $publisher;

    // AdSense must use the single PHP auto-prepend injector. Legacy
    // mod_substitute response rewriting is intentionally forbidden.
    if (strpos($source, 'mod_substitute') !== false || strpos($source, 'SUBSTITUTE') !== false) {
        return ['allowed' => false, 'error_code' => 'adsense_legacy_integration_present', 'message' => 'Legacy mod_substitute AdSense integration is not allowed.'];
    }

    foreach ([
        'AddHandler application/x-httpd-lsphp .html .htm',
        'php_value auto_prepend_file',
        'inject_ads.php',
    ] as $needle) {
        if (strpos($source, $needle) === false) {
            return ['allowed' => false, 'error_code' => 'adsense_contract_missing', 'message' => 'Missing AdSense integration contract: ' . $needle];
        }
    }

    foreach (['ob_start', $scriptNeedle, 'crossorigin="anonymous"', "stripos(\$buffer, '</head>')"] as $needle) {
        if (strpos($injectorSource, $needle) === false) {
            return ['allowed' => false, 'error_code' => 'adsense_injector_contract_missing', 'message' => 'Missing AdSense injector contract: ' . $needle];
        }
    }

    if (substr_count($injectorSource, $scriptNeedle) !== 1) {
        return ['allowed' => false, 'error_code' => 'adsense_script_duplicate', 'message' => 'AdSense script URL must appear exactly once in inject_ads.php.'];
    }

    foreach (['/privacy-policy', '/terms', '/disclaimer', '/contact'] as $excluded) {
        if (strpos($injectorSource, "'" . $excluded . "'") === false) {
            return ['allowed' => false, 'error_code' => 'adsense_exclusion_missing', 'message' => 'Missing AdSense exclusion: ' . $excluded];
        }
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
        'mode' => 'php-auto-prepend-output-buffer',
    ];
}
