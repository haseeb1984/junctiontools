<?php
declare(strict_types=1);

/**
 * Phase 13 live Google Ads Keyword Planner demand acquisition.
 *
 * Credentials are read only from environment variables. No secrets are
 * written to the repository or output artifacts.
 *
 * Usage:
 *   php tools/fetch-google-keyword-planner-demand.php [config.json] [output.json]
 */

$configPath = $argv[1] ?? dirname(__DIR__) . '/config/google-keyword-planner-runtime.json';
$outputPath = $argv[2] ?? dirname(__DIR__) . '/config/google-keyword-planner-live.json';

if (!is_file($configPath)) {
    fwrite(STDERR, "Runtime config not found: {$configPath}\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configPath), true);
if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
    fwrite(STDERR, "Google demand runtime is disabled or invalid.\n");
    exit(1);
}

$getEnv = static function (string $name, bool $required = true): ?string {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        if ($required) {
            throw new RuntimeException("Required environment variable is missing: {$name}");
        }
        return null;
    }
    return trim($value);
};

try {
    $customerId = preg_replace('/\D+/', '', $getEnv((string)($config['customer_id_env'] ?? 'GOOGLE_ADS_CUSTOMER_ID')));
    if ($customerId === '') {
        throw new RuntimeException('GOOGLE_ADS_CUSTOMER_ID must contain a numeric customer ID.');
    }

    $loginCustomerId = $getEnv((string)($config['login_customer_id_env'] ?? 'GOOGLE_ADS_LOGIN_CUSTOMER_ID'), false);
    if ($loginCustomerId !== null) {
        $loginCustomerId = preg_replace('/\D+/', '', $loginCustomerId);
    }

    $developerToken = $getEnv((string)($config['developer_token_env'] ?? 'GOOGLE_ADS_DEVELOPER_TOKEN'));
    $clientId = $getEnv((string)($config['client_id_env'] ?? 'GOOGLE_ADS_CLIENT_ID'));
    $clientSecret = $getEnv((string)($config['client_secret_env'] ?? 'GOOGLE_ADS_CLIENT_SECRET'));
    $refreshToken = $getEnv((string)($config['refresh_token_env'] ?? 'GOOGLE_ADS_REFRESH_TOKEN'));

    $seeds = array_values(array_unique(array_filter(array_map(
        static fn($v): string => trim((string)$v),
        is_array($config['seed_keywords'] ?? null) ? $config['seed_keywords'] : []
    ), static fn(string $v): bool => $v !== '')));
    if ($seeds === []) {
        throw new RuntimeException('At least one seed keyword is required.');
    }

    $geoTargets = array_values(array_unique(array_filter(array_map(
        static fn($v): string => preg_replace('/\D+/', '', (string)$v),
        is_array($config['geo_target_constants'] ?? null) ? $config['geo_target_constants'] : []
    ), static fn(string $v): bool => $v !== '')));
    if ($geoTargets === []) {
        throw new RuntimeException('At least one geo target constant is required.');
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Google Ads API access.');
    }

    $postForm = static function (string $url, array $fields): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Google OAuth returned invalid JSON (HTTP {$status}).");
        }
        if ($status < 200 || $status >= 300 || isset($decoded['error'])) {
            $message = (string)($decoded['error_description'] ?? $decoded['error']['message'] ?? 'OAuth request rejected');
            throw new RuntimeException("Google OAuth error (HTTP {$status}): {$message}");
        }
        return $decoded;
    };

    $oauth = $postForm('https://oauth2.googleapis.com/token', [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);
    $accessToken = (string)($oauth['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Google OAuth response did not contain an access token.');
    }

    $apiVersion = preg_replace('/[^a-z0-9]/i', '', (string)($config['api_version'] ?? 'v24'));
    $endpoint = "https://googleads.googleapis.com/{$apiVersion}/customers/{$customerId}:generateKeywordIdeas";

    $request = [
        'language' => 'customers/' . $customerId . '/languageConstants/' . (string)($config['language_constant'] ?? '1000'),
        'geoTargetConstants' => array_map(
            static fn(string $id): string => 'geoTargetConstants/' . $id,
            $geoTargets
        ),
        'includeAdultKeywords' => (bool)($config['include_adult_keywords'] ?? false),
        'keywordPlanNetwork' => (string)($config['keyword_plan_network'] ?? 'GOOGLE_SEARCH'),
        'keywordSeed' => ['keywords' => $seeds],
    ];

    $allResults = [];
    $pageToken = null;
    $maxResults = max(1, min(10000, (int)($config['max_results'] ?? 1000)));

    do {
        if ($pageToken !== null) {
            $request['pageToken'] = $pageToken;
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'developer-token: ' . $developerToken,
        ];
        if ($loginCustomerId !== null && $loginCustomerId !== '') {
            $headers[] = 'login-customer-id: ' . $loginCustomerId;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 90,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Google Ads API request failed: {$error}");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Google Ads API returned invalid JSON (HTTP {$status}).");
        }
        if ($status < 200 || $status >= 300 || isset($decoded['error'])) {
            $message = (string)($decoded['error']['message'] ?? 'Google Ads API request rejected');
            throw new RuntimeException("Google Ads API error (HTTP {$status}): {$message}");
        }

        foreach (($decoded['results'] ?? []) as $result) {
            if (count($allResults) >= $maxResults) {
                break;
            }
            if (is_array($result)) {
                $allResults[] = $result;
            }
        }

        $pageToken = isset($decoded['nextPageToken']) && count($allResults) < $maxResults
            ? (string)$decoded['nextPageToken']
            : null;
    } while ($pageToken !== null);

    $keywords = [];
    foreach ($allResults as $result) {
        $metrics = is_array($result['keywordIdeaMetrics'] ?? null) ? $result['keywordIdeaMetrics'] : [];
        $text = trim((string)($result['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $keywords[] = [
            'query' => $text,
            'search_volume' => isset($metrics['avgMonthlySearches']) ? (int)$metrics['avgMonthlySearches'] : 0,
            'competition' => $metrics['competition'] ?? null,
            'competition_index' => isset($metrics['competitionIndex']) ? (int)$metrics['competitionIndex'] : null,
            'low_top_of_page_bid_micros' => isset($metrics['lowTopOfPageBidMicros']) ? (int)$metrics['lowTopOfPageBidMicros'] : null,
            'high_top_of_page_bid_micros' => isset($metrics['highTopOfPageBidMicros']) ? (int)$metrics['highTopOfPageBidMicros'] : null,
            'source' => 'google-ads-keyword-planner-api',
            'source_date' => gmdate('Y-m-d'),
        ];
    }

    usort($keywords, static fn(array $a, array $b): int =>
        ($b['search_volume'] <=> $a['search_volume']) ?: strcasecmp($a['query'], $b['query'])
    );

    $result = [
        'schema_version' => '1.0.0',
        'source' => 'google-ads-keyword-planner-api',
        'generated_at' => gmdate('c'),
        'account' => [
            'customer_id_last4' => substr($customerId, -4),
        ],
        'targeting' => [
            'language_constant' => (string)($config['language_constant'] ?? '1000'),
            'geo_target_constants' => $geoTargets,
            'keyword_plan_network' => (string)($config['keyword_plan_network'] ?? 'GOOGLE_SEARCH'),
        ],
        'seeds' => $seeds,
        'methodology' => [
            'authority' => 'Google Ads Keyword Planner KeywordPlanIdeaService.GenerateKeywordIdeas',
            'policy' => 'Only metrics returned by Google are stored; no search volume is inferred or invented.',
            'credentials_policy' => 'OAuth credentials and developer token are runtime secrets and are never written to repository artifacts.',
        ],
        'keywords' => $keywords,
    ];

    if (file_put_contents(
        $outputPath,
        json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    ) === false) {
        throw new RuntimeException("Unable to write output: {$outputPath}");
    }

    echo "Fetched " . count($keywords) . " live Google Keyword Planner keyword records.\n";
    echo "Output: {$outputPath}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Live Google demand acquisition failed: " . $e->getMessage() . "\n");
    exit(1);
}
