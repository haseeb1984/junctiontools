<?php
declare(strict_types=1);

$delay = isset($_GET['delay']) ? max(0, min(60, (int) $_GET['delay'])) : 10;
sleep($delay);
header('Content-Type: text/plain; charset=utf-8');
echo 'timeout-fixture-complete';
