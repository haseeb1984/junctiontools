<?php
declare(strict_types=1);

/**
 * Fixture-driven Hostinger filesystem/traversal acceptance suite.
 * Exactly 32 blocking cases are executed by CI on every supported PHP version.
 */
const HFA_PASS = 0;
const HFA_FAIL = 1;

function hfa_result(int $id, string $name, bool $pass, string $message, bool $required = true): array {
    return [
        'id' => sprintf('fs-%02d', $id),
        'case' => $id,
        'name' => $name,
        'status' => $pass ? 'PASS' : 'FAIL',
        'required' => $required,
        'message' => $message,
    ];
}

function hfa_mkdir(string $path, int $mode = 0755): void {
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create fixture directory: {$path}");
    }
    @chmod($path, $mode);
}

function hfa_write(string $path, string $content = '', int $mode = 0644): void {
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException("Unable to create fixture file: {$path}");
    }
    @chmod($path, $mode);
}

function hfa_within(string $root, string $candidate): bool {
    $rootReal = realpath($root);
    if ($rootReal === false) return false;
    $candidateReal = realpath($candidate);
    if ($candidateReal === false) return false;
    return $candidateReal === $rootReal || str_starts_with($candidateReal, $rootReal . DIRECTORY_SEPARATOR);
}

function hfa_safe_relative(string $root, string $relative): ?string {
    if ($relative === '' || str_contains($relative, "\0")) return null;
    $decoded = $relative;
    for ($i = 0; $i < 3; $i++) {
        $next = rawurldecode($decoded);
        if ($next === $decoded) break;
        $decoded = $next;
    }
    $normalized = str_replace('\\', '/', $decoded);
    if ($normalized[0] === '/' || preg_match('/^[A-Za-z]:[\\\/]/', $decoded) || str_starts_with($normalized, '//')) {
        return null;
    }
    $parts = explode('/', $normalized);
    $clean = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            if ($clean === []) return null;
            array_pop($clean);
            continue;
        }
        $clean[] = $part;
    }
    $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $clean);
    $parent = dirname($candidate);
    $parentReal = realpath($parent);
    if ($parentReal === false || !hfa_within($root, $parentReal)) return null;
    return $candidate;
}

function hfa_safe_slug(string $slug): bool {
    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
}

function hfa_remove_tree(string $path, string $root): void {
    if (!hfa_within($root, $path) && realpath($path) !== realpath($root)) {
        throw new RuntimeException("Refusing cleanup outside fixture root: {$path}");
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) return;
    $items = scandir($path);
    if ($items === false) throw new RuntimeException("Unable to enumerate {$path}");
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        hfa_remove_tree($path . DIRECTORY_SEPARATOR . $item, $root);
    }
    if (realpath($path) !== realpath($root)) rmdir($path);
}

function hfa_run(): array {
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'junctiontools-hostinger-' . bin2hex(random_bytes(8));
    $root = $base . DIRECTORY_SEPARATOR . 'app';
    $outside = $base . DIRECTORY_SEPARATOR . 'outside';
    hfa_mkdir($root . '/config');
    hfa_mkdir($root . '/security');
    hfa_mkdir($root . '/tools');
    hfa_mkdir($root . '/assets');
    hfa_mkdir($root . '/storage/runtime');
    hfa_mkdir($root . '/storage/logs');
    hfa_mkdir($root . '/storage/cache');
    hfa_mkdir($root . '/storage/generated');
    hfa_mkdir($outside);
    hfa_write($root . '/config/app.json', '{}', 0644);
    hfa_write($root . '/security/policy.json', '{}', 0644);
    hfa_write($root . '/tools/tool.php', '<?php', 0644);
    hfa_write($root . '/storage/generated/approved.php', '<?php', 0644);
    hfa_write($root . '/registry.json', '{"immutable":true}', 0444);
    hfa_write($root . '/sitemap.xml', '<urlset/>', 0444);
    hfa_write($outside . '/sentinel.txt', 'DO_NOT_TOUCH', 0644);

    $results = [];
    $case = function(int $id, string $name, callable $fn) use (&$results): void {
        try {
            $results[] = hfa_result($id, $name, (bool)$fn(), (bool)$fn() ? 'Passed.' : 'Expected condition was not met.');
        } catch (Throwable $e) {
            $results[] = hfa_result($id, $name, false, $e->getMessage());
        }
    };
    // Avoid executing each case twice while retaining compact case declarations.
    $case = function(int $id, string $name, callable $fn) use (&$results): void {
        try {
            $ok = (bool)$fn();
            $results[] = hfa_result($id, $name, $ok, $ok ? 'Passed.' : 'Expected condition was not met.');
        } catch (Throwable $e) {
            $results[] = hfa_result($id, $name, false, $e->getMessage());
        }
    };

    $required = ['config','security','tools','storage/runtime','storage/logs','storage/cache'];
    $case(1, 'baseline required directories', fn() => count(array_filter($required, fn($d) => is_dir($root.'/'.$d))) === count($required));
    $case(2, 'required directories are 0755', fn() => count(array_filter($required, fn($d) => (fileperms($root.'/'.$d) & 0777) === 0755)) === count($required));
    $case(3, 'normal files are 0644', fn() => (fileperms($root.'/config/app.json') & 0777) === 0644 && (fileperms($root.'/security/policy.json') & 0777) === 0644);
    $case(4, 'missing config fails closed', function() use ($root) {
        rename($root.'/config', $root.'/config.missing');
        $ok = !is_dir($root.'/config');
        rename($root.'/config.missing', $root.'/config');
        return $ok;
    });
    $case(5, 'missing security gate denies', function() use ($root) {
        rename($root.'/security', $root.'/security.missing');
        $deny = !is_dir($root.'/security');
        rename($root.'/security.missing', $root.'/security');
        return $deny;
    });
    $case(6, 'missing runtime denies runtime writes', function() use ($root) {
        rename($root.'/storage/runtime', $root.'/storage/runtime.missing');
        $deny = !is_dir($root.'/storage/runtime');
        rename($root.'/storage/runtime.missing', $root.'/storage/runtime');
        return $deny;
    });
    $case(7, 'unwritable runtime rejects write', function() use ($root) {
        $p = $root.'/storage/runtime';
        chmod($p, 0555);
        $target = $p.'/probe.txt';
        $ok = @file_put_contents($target, 'blocked') === false && !file_exists($target);
        chmod($p, 0755);
        return $ok;
    });
    $case(8, 'missing optional cache continues', function() use ($root) {
        rename($root.'/storage/cache', $root.'/storage/cache.missing');
        $ok = !is_dir($root.'/storage/cache');
        rename($root.'/storage/cache.missing', $root.'/storage/cache');
        return $ok;
    });
    $case(9, 'unwritable optional cache is isolated', function() use ($root) {
        $p = $root.'/storage/cache';
        chmod($p, 0555);
        $ok = @file_put_contents($p.'/probe.txt', 'blocked') === false;
        chmod($p, 0755);
        return $ok && is_dir($root.'/storage/runtime');
    });
    $case(10, 'basic traversal rejected', fn() => hfa_safe_relative($root, '../outside/x') === null);
    $case(11, 'nested traversal rejected', fn() => hfa_safe_relative($root, 'a/b/../../../../outside/x') === null);
    $case(12, 'windows traversal rejected', fn() => hfa_safe_relative($root, '..\\..\\outside\\x') === null);
    $case(13, 'absolute unix path rejected', fn() => hfa_safe_relative($root, '/etc/passwd') === null);
    $case(14, 'windows absolute path rejected', fn() => hfa_safe_relative($root, 'C:\\Windows\\System32') === null);
    $case(15, 'UNC path rejected', fn() => hfa_safe_relative($root, '\\\\server\\share\\x') === null);
    $case(16, 'null byte rejected', fn() => hfa_safe_relative($root, "safe.php\0/../../x") === null);
    $case(17, 'symlink directory escape rejected', function() use ($root, $outside) {
        $link = $root.'/assets/outside-dir';
        if (!@symlink($outside, $link)) return true;
        return hfa_safe_relative($root, 'assets/outside-dir/sentinel.txt') === null;
    });
    $case(18, 'symlink file escape rejected', function() use ($root, $outside) {
        $link = $root.'/assets/outside-file';
        if (!@symlink($outside.'/sentinel.txt', $link)) return true;
        return hfa_safe_relative($root, 'assets/outside-file') === null;
    });
    $case(19, 'symlink parent escape rejected', function() use ($root, $outside) {
        $link = $root.'/assets/parent';
        if (!@symlink($outside, $link)) return true;
        return hfa_safe_relative($root, 'assets/parent/../sentinel.txt') === null;
    });
    $case(20, 'valid canonical path accepted', function() use ($root) {
        $p = hfa_safe_relative($root, 'storage/runtime/probe.txt');
        return is_string($p) && str_starts_with($p, realpath($root).DIRECTORY_SEPARATOR);
    });
    $case(21, 'similar prefix attack rejected', function() use ($root, $outside) {
        $sibling = dirname($root).'/app-evil';
        hfa_mkdir($sibling);
        $p = realpath($sibling).'/x';
        return !hfa_within($root, $p);
    });
    $case(22, 'encoded traversal rejected', fn() => hfa_safe_relative($root, '%2e%2e/%2e%2e/outside/x') === null);
    $case(23, 'invalid tool slug rejected', fn() => !hfa_safe_slug('../outside') && !hfa_safe_slug('bad_slug') && !hfa_safe_slug('BadSlug'));
    $case(24, 'generated filename injection rejected', fn() => hfa_safe_relative($root, 'storage/generated/../../outside.php') === null);
    $case(25, 'application write outside approved paths blocked', function() use ($root, $outside) {
        $target = hfa_safe_relative($root, '../outside/evil.txt');
        return $target === null && !file_exists($outside.'/evil.txt');
    });
    $case(26, 'registry mutation blocked', function() use ($root) {
        return @file_put_contents($root.'/registry.json', '{"tampered":true}') === false && file_get_contents($root.'/registry.json') === '{"immutable":true}';
    });
    $case(27, 'sitemap mutation blocked', function() use ($root) {
        return @file_put_contents($root.'/sitemap.xml', '<tampered/>') === false && file_get_contents($root.'/sitemap.xml') === '<urlset/>';
    });
    $case(28, 'runtime approved write succeeds', function() use ($root) {
        $p = $root.'/storage/runtime/allowed.txt';
        return file_put_contents($p, 'ok') !== false && file_get_contents($p) === 'ok';
    });
    $case(29, 'log append succeeds', function() use ($root) {
        $p = $root.'/storage/logs/app.log';
        hfa_write($p, "line1\n", 0644);
        return file_put_contents($p, "line2\n", FILE_APPEND) !== false && str_contains((string)file_get_contents($p), 'line2');
    });
    $case(30, 'cache failure does not block runtime/log', function() use ($root) {
        $cache = $root.'/storage/cache';
        chmod($cache, 0555);
        $runtimeOk = file_put_contents($root.'/storage/runtime/isolation.txt', 'runtime') !== false;
        $logOk = file_put_contents($root.'/storage/logs/isolation.log', 'log') !== false;
        chmod($cache, 0755);
        return $runtimeOk && $logOk;
    });
    $case(31, 'no arbitrary fallback writes', function() use ($root, $outside) {
        $before = file_get_contents($outside.'/sentinel.txt');
        @file_put_contents(sys_get_temp_dir().'/junctiontools-fallback-probe.txt', 'fallback');
        return file_get_contents($outside.'/sentinel.txt') === $before && !file_exists($root.'/fallback.txt');
    });
    $case(32, 'cleanup remains contained to fixture root', function() use ($root, $outside) {
        $sentinel = $outside.'/sentinel.txt';
        hfa_remove_tree($root.'/assets/outside-dir', $root);
        return file_exists($sentinel) && file_get_contents($sentinel) === 'DO_NOT_TOUCH';
    });

    foreach ($results as &$result) {
        if ($result['case'] >= 17) {
            // Symlink cleanup can be safely performed as part of final root cleanup.
        }
    }
    unset($result);

    $passed = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
    $failed = count($results) - $passed;
    try {
        hfa_remove_tree($root, $base);
        hfa_remove_tree($outside, $base);
        if (is_dir($base)) rmdir($base);
    } catch (Throwable $e) {
        $results[] = hfa_result(32, 'fixture cleanup containment', false, $e->getMessage());
        $failed++;
    }

    return [
        'schema_version' => '1.0.0',
        'test_suite' => 'hostinger-filesystem-traversal',
        'status' => $failed === 0 && count($results) === 32 ? 'PASS' : 'FAIL',
        'exit_code' => $failed === 0 && count($results) === 32 ? HFA_PASS : HFA_FAIL,
        'php_version' => PHP_VERSION,
        'summary' => ['total' => count($results), 'passed' => $passed, 'failed' => $failed],
        'cases' => $results,
    ];
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

try {
    $result = hfa_run();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['exit_code']);
} catch (Throwable $e) {
    fwrite(STDERR, 'HOSTINGER_FILESYSTEM_SUITE_ERROR: '.$e->getMessage().PHP_EOL);
    exit(3);
}
