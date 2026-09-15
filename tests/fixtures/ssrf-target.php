<?php
declare(strict_types=1);

// This endpoint is only a marker for tests. A passing SSRF test must never
// reach it when an unsafe destination is supplied to the application.
http_response_code(418);
header('Content-Type: text/plain; charset=utf-8');
echo 'SSRF_TARGET_REACHED';
