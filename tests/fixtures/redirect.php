<?php
declare(strict_types=1);

$target = $_GET['to'] ?? '/timeout.php?delay=0';
if (!is_string($target) || $target === '' || str_starts_with($target, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
    $target = '/timeout.php?delay=0';
}
header('Location: ' . $target, true, 302);
exit;
