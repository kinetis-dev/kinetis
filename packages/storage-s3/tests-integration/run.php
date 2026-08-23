<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for S3FilesystemFactory — write/read/
 * exists/size/nested-list/delete against a real LocalStack S3 endpoint.
 * The bucket is never auto-created by the factory (a deliberate design
 * choice, matching SqsQueue's identical stance on queues), so this script
 * creates it directly first.
 */

require __DIR__ . '/../vendor/autoload.php';

use AsyncAws\S3\S3Client;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\StorageS3\S3FilesystemFactory;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

$endpoint = getenv('LOCALSTACK_ENDPOINT') ?: 'http://127.0.0.1:4566';
$region = getenv('AWS_REGION') ?: 'us-east-1';
$bucket = 'kinetis-integration-test';

$setupClient = new S3Client(
    ['endpoint' => $endpoint, 'region' => $region, 'pathStyleEndpoint' => true],
    null,
    AmpHttpClientFactory::create(),
);
$setupClient->createBucket(['Bucket' => $bucket])->resolve();

$config = new Config([
    'FILESYSTEM_DRIVER' => 's3',
    'FILESYSTEM_S3_BUCKET' => $bucket,
    'FILESYSTEM_S3_REGION' => $region,
    'FILESYSTEM_S3_ENDPOINT' => $endpoint,
    'FILESYSTEM_S3_PATH_STYLE' => 'true',
]);

$storage = S3FilesystemFactory::fromConfig($config);

$storage->write('greetings/hello.txt', 'Hello from Kinetis');
check('write() + exists() round-trip', $storage->fileExists('greetings/hello.txt'));
check('read() returns exactly what was written', $storage->read('greetings/hello.txt') === 'Hello from Kinetis');
check('fileSize() matches the real byte count', $storage->fileSize('greetings/hello.txt') === strlen('Hello from Kinetis'));

$storage->write('greetings/other.txt', 'Another file');
$listed = [];
foreach ($storage->listContents('greetings', deep: false) as $item) {
    $listed[] = $item->path();
}
sort($listed);
check('listContents() finds both nested files', $listed === ['greetings/hello.txt', 'greetings/other.txt']);

$storage->delete('greetings/hello.txt');
check('delete() actually removes the file', !$storage->fileExists('greetings/hello.txt'));

echo "ALL CHECKS PASSED\n";
