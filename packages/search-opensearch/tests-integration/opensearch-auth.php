<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use OpenSearch\Client;

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;

    if ($condition) {
        echo "OK   {$label}\n";
    } else {
        echo "FAIL {$label}\n";
        $failures++;
    }
}

function buildClient(array $env): Client
{
    return OpenSearchClientFactory::fromConfig(new Config($env));
}

$host = getenv('SEARCH_OPENSEARCH_SECURE_HOST') ?: 'https://localhost:9200';
$username = getenv('SEARCH_OPENSEARCH_USERNAME') ?: 'admin';
$password = getenv('SEARCH_OPENSEARCH_PASSWORD') ?: '';

// No credentials at all -> the security plugin should reject the request.
try {
    buildClient(['SEARCH_OPENSEARCH_HOST' => $host, 'SEARCH_OPENSEARCH_VERIFY_PEER' => 'false'])->info();
    check('an unauthenticated request is rejected', false);
} catch (\OpenSearch\Exception\UnauthorizedHttpException) {
    check('an unauthenticated request is rejected', true);
}

// Correct Basic auth, peer verification explicitly off for the self-signed
// demo cert -> should succeed.
$info = buildClient([
    'SEARCH_OPENSEARCH_HOST' => $host,
    'SEARCH_OPENSEARCH_USERNAME' => $username,
    'SEARCH_OPENSEARCH_PASSWORD' => $password,
    'SEARCH_OPENSEARCH_VERIFY_PEER' => 'false',
])->info();
check('a correctly authenticated request succeeds', isset($info['cluster_name']));

// Leaving SEARCH_OPENSEARCH_VERIFY_PEER at its true default should reject
// the self-signed cert outright — proving the secure-by-default posture is
// real, not just documented.
try {
    buildClient([
        'SEARCH_OPENSEARCH_HOST' => $host,
        'SEARCH_OPENSEARCH_USERNAME' => $username,
        'SEARCH_OPENSEARCH_PASSWORD' => $password,
    ])->info();
    check('the default (verify_peer=true) rejects a self-signed cert', false);
} catch (\Throwable) {
    check('the default (verify_peer=true) rejects a self-signed cert', true);
}

if ($failures > 0) {
    echo "\n{$failures} check(s) failed.\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
