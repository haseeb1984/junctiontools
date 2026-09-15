<?php
/** Lightweight file-backed rate limiter for public POST endpoints. */
function jt_rate_limit(string $bucket, int $limit = 10, int $windowSeconds = 60): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = hash('sha256', $bucket . '|' . $ip);
    $dir = sys_get_temp_dir() . '/junctiontools-rate-limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    $file = $dir . '/' . $key . '.json';
    $now = time();
    $data = ['start' => $now, 'count' => 0];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (($now - (int)($data['start'] ?? 0)) >= $windowSeconds) {
        $data = ['start' => $now, 'count' => 0];
    }

    $data['count'] = (int)($data['count'] ?? 0) + 1;
    @file_put_contents($file, json_encode($data), LOCK_EX);

    return $data['count'] <= $limit;
}
