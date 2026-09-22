        } else {
            $payload['url'] = $fixtureUrl;
        }

        $response = jt_test_http($scannerUrl, 'POST', json_encode($payload), ['Content-Type: application/json']);
        jt_test_assert(in_array($response['status'], [200,400,422], true), "{$name} returned HTTP {$response['status']}.");
        $json = jt_test_json($response['body']);
        jt_test_assert(array_key_exists('success', $json), "{$name} response lacks success field.");

        if ($type === 'trust_inspector') {
            jt_test_assert($json['success'] === true, 'Trust Badge Inspector did not succeed: HTTP ' . $response['status'] . ' body=' . $response['body']);
            jt_test_assert($json['url'] === $fixtureUrl, 'Trust Badge Inspector did not analyze the requested URL.');
            jt_test_assert(($json['checkedCount'] ?? 0) === 4, 'Trust Badge Inspector checkedCount is invalid.');