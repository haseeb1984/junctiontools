<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
$size = isset($_GET['bytes']) ? max(1, min(8388608, (int) $_GET['bytes'])) : 3145728;
$chunk = str_repeat('X', 8192);
$remaining = $size;
while ($remaining > 0) {
    $write = min($remaining, strlen($chunk));
    echo substr($chunk, 0, $write);
    $remaining -= $write;
    flush();
}
