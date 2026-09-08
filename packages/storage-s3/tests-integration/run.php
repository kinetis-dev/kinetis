<?php

declare(strict_types=1);

/**
 * Real-backend coverage for S3FilesystemFactory against LocalStack:
 * write, read, list, copy, move and recursive delete, over the plain-HTTP
 * endpoint the FILESYSTEM_S3_PLAINTEXT opt-in exists for. The bucket is
 * never auto-created by the factory (a deliberate design choice, matching
 * SqsQueue's identical stance on queues), so this script creates it
 * directly first.
 */

require __DIR__ . '/../vendor/autoload.php';

use AsyncAws\S3\S3Client;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\StorageS3\Exception\S3ConfigurationException;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\UnableToReadFile;

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

$settings = [
    'FILESYSTEM_DRIVER' => 's3',
    'FILESYSTEM_S3_BUCKET' => $bucket,
    'FILESYSTEM_S3_REGION' => $region,
    'FILESYSTEM_S3_ENDPOINT' => $endpoint,
];

try {
    S3FilesystemFactory::fromConfig(new Config($settings));
    check('a plain-HTTP endpoint is refused without the opt-in', false);
} catch (S3ConfigurationException) {
    check('a plain-HTTP endpoint is refused without the opt-in', true);
}

$storage = S3FilesystemFactory::fromConfig(new Config($settings + ['FILESYSTEM_S3_PLAINTEXT' => 'true']));

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

$storage->copy('greetings/hello.txt', 'copies/hello.txt');
check('copy() reaches the destination', $storage->read('copies/hello.txt') === 'Hello from Kinetis');
check('copy() leaves the source in place', $storage->fileExists('greetings/hello.txt'));

$storage->move('copies/hello.txt', 'moves/hello.txt');
check('move() reaches the destination', $storage->read('moves/hello.txt') === 'Hello from Kinetis');
check('move() removes the source', !$storage->fileExists('copies/hello.txt'));

$storage->delete('greetings/hello.txt');
check('delete() actually removes the file', !$storage->fileExists('greetings/hello.txt'));

$storage->deleteDirectory('greetings');

try {
    $storage->read('greetings/other.txt');
    check('deleteDirectory() removes every key under the prefix', false);
} catch (UnableToReadFile) {
    check('deleteDirectory() removes every key under the prefix', true);
}

echo "ALL CHECKS PASSED\n";
