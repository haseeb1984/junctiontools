<?php
declare(strict_types=1);

/**
 * PHP built-in server router for local integration testing.
 *
 * Apache uses .htaccess to map clean tool URLs such as /pagespeed-analyzer
 * to their corresponding .html files. PHP's built-in server does not process
 * .htaccess, so mirror that public routing here while keeping real static
 * files and the scanner compatibility route working.
 */

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($uriPath)) {
    http_response_code(400);
    exit('Bad request');
}

if ($uriPath === '/scanner.php') {
    require __DIR__ . '/../scanner-compat.php';
    return;
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    http_response_code(500);
    exit('Repository root not found');
}

$requested = realpath($root . $uriPath);

// Serve existing files directly.
if ($requested !== false && str_starts_with($requested, $root . DIRECTORY_SEPARATOR) && is_file($requested)) {
    return false;
}

// Mirror the production .htaccess clean-URL rule:
// /tool-slug -> /tool-slug.html
if ($uriPath !== '/' && !str_contains(basename($uriPath), '.')) {
    $candidate = realpath($root . $uriPath . '.html');
    if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            header('Content-Type: text/html; charset=UTF-8');
            readfile($candidate);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
        }
        return;
    }
}

return false;
