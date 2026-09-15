<?php
declare(strict_types=1);

/**
 * PHP built-in server router for Phase 0 integration tests.
 *
 * The real Apache deployment rewrites /scanner.php to scanner-compat.php.
 * PHP's built-in server does not process .htaccess, so mirror that public
 * route here while serving the repository root (including tests/fixtures/).
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
$requested = realpath($root . $uriPath);

if ($requested !== false && str_starts_with($requested, $root . DIRECTORY_SEPARATOR) && is_file($requested)) {
    return false;
}

return false;
