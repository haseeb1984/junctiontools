<?php
declare(strict_types=1);
http_response_code(418);
header('Content-Type: text/plain; charset=utf-8');
echo 'SSRF_TARGET_REACHED';
