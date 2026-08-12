<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Async\Timer;
use Kinetis\Config\Config;
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;

use function Kinetis\Async\concurrently;

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

$config = new Config(['SEARCH_OPENSEARCH_HOST' => getenv('SEARCH_OPENSEARCH_HOST') ?: 'http://localhost:9200']);
$client = OpenSearchClientFactory::fromConfig($config);

$index = 'kinetis-verify-' . bin2hex(random_bytes(4));

// index() a document, confirm it round-trips through get().
$client->index([
    'index' => $index,
    'id' => '1',
    'body' => ['title' => 'Kinetis', 'category' => 'framework'],
    'refresh' => 'true',
]);

$got = $client->get(['index' => $index, 'id' => '1']);
check('index() + get() round-trips the document', $got['_source']['title'] === 'Kinetis');

// search() finds it back via a real query.
$searchResult = $client->search([
    'index' => $index,
    'body' => ['query' => ['match' => ['category' => 'framework']]],
]);
check('search() finds the indexed document', ($searchResult['hits']['total']['value'] ?? 0) === 1);

// A second document, then delete the first and confirm only one remains.
$client->index([
    'index' => $index,
    'id' => '2',
    'body' => ['title' => 'Second', 'category' => 'framework'],
    'refresh' => 'true',
]);
$client->delete(['index' => $index, 'id' => '1', 'refresh' => 'true']);

$afterDelete = $client->search([
    'index' => $index,
    'body' => ['query' => ['match_all' => (object) []]],
]);
check('delete() removes exactly the deleted document', ($afterDelete['hits']['total']['value'] ?? -1) === 1);

$client->indices()->delete(['index' => $index]);

// Non-blocking proof: a real search raced against Timer::delay(0.5) should
// track max(search, delay), not their sum — the same technique already
// used for storage-s3/queue-sqs/mailer.
$secondIndex = 'kinetis-verify-nb-' . bin2hex(random_bytes(4));
$client->index(['index' => $secondIndex, 'id' => '1', 'body' => ['x' => 1], 'refresh' => 'true']);

$start = microtime(true);
concurrently([
    function () use ($client, $secondIndex): void {
        for ($i = 0; $i < 5; $i++) {
            $client->search(['index' => $secondIndex, 'body' => ['query' => ['match_all' => (object) []]]]);
        }
    },
    function (): void {
        Timer::delay(0.5);
    },
]);
$elapsed = microtime(true) - $start;

check(
    'a real search raced against Timer::delay(0.5) does not simply block for the sum',
    $elapsed < 2.0,
);
echo 'elapsed: ' . round($elapsed, 3) . "s\n";

$client->indices()->delete(['index' => $secondIndex]);

if ($failures > 0) {
    echo "\n{$failures} check(s) failed.\n";
    exit(1);
}

echo "\nALL CHECKS PASSED\n";
