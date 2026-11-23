<?php

declare(strict_types=1);

/**
 * Real-backend coverage for S3FilesystemFactory against LocalStack:
 * write, stream write, read, list, copy, move and recursive delete, over
 * the plain-HTTP endpoint the FILESYSTEM_S3_PLAINTEXT opt-in exists for.
 * DirectUploads is covered by multipart POSTs whose results are read
 * back through a Filesystem on the same connection. LocalStack enforces
 * the size range, exact-match conditions and expiry of a POST policy, but
 * not its signature or uncovered extra fields.
 * The bucket is never auto-created by the factory (a deliberate design
 * choice, matching SqsQueue's identical stance on queues), so this script
 * creates it directly first.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Http\Client\BufferedContent;
use Amp\Http\Client\Form;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use AsyncAws\S3\S3Client;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\StorageS3\DirectUploads;
use Kinetis\StorageS3\Exception\S3ConfigurationException;
use Kinetis\StorageS3\PresignedPost;
use Kinetis\StorageS3\S3FilesystemFactory;
use League\Flysystem\UnableToReadFile;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

/**
 * Sends the form a browser would: every field unchanged, any override
 * applied, then the file last.
 *
 * @param array<string, string> $override
 *
 * @return array{int, string}
 */
function postUpload(PresignedPost $post, string $contents, array $override = []): array
{
    $form = new Form();

    foreach (array_replace($post->fields, $override) as $name => $value) {
        $form->addField($name, $value);
    }

    $form->addStream('file', BufferedContent::fromString($contents), 'upload.bin');
    $response = HttpClientBuilder::buildDefault()->request(new Request($post->url, 'POST', $form));

    return [$response->getStatus(), $response->getBody()->buffer()];
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

$streamed = str_repeat('Kinetis stream ', 10_000);
$resource = fopen('php://memory', 'w+b');
fwrite($resource, $streamed);
$storage->writeStream('streams/body.txt', $resource);
check('writeStream() leaves the resource open', is_resource($resource));
fclose($resource);
check('writeStream() + read() round-trip', $storage->read('streams/body.txt') === $streamed);
check('fileSize() matches the streamed byte count', $storage->fileSize('streams/body.txt') === strlen($streamed));
$storage->delete('streams/body.txt');

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

$direct = new Config($settings + ['FILESYSTEM_S3_PLAINTEXT' => 'true', 'FILESYSTEM_S3_PREFIX' => 'direct-prefix']);
$uploads = DirectUploads::fromConfig($direct);
$prefixed = S3FilesystemFactory::fromConfig($direct);

$post = $uploads->authorize('incoming/in-range.bin', 1024, 60, 'application/octet-stream');
[$status] = postUpload($post, str_repeat('x', 1000));
check('an in-range POST upload is accepted with 204', $status === 204);
check('the uploaded object exists at the same Filesystem path', $prefixed->fileExists('incoming/in-range.bin'));
check('fileSize() reports the uploaded byte count', $prefixed->fileSize('incoming/in-range.bin') === 1000);
check('mimeType() reports the declared Content-Type', $prefixed->mimeType('incoming/in-range.bin') === 'application/octet-stream');

[$status, $body] = postUpload($uploads->authorize('incoming/over-range.bin', 1024), str_repeat('x', 1025));
check('an over-range POST upload is refused with EntityTooLarge', $status === 400 && str_contains($body, 'EntityTooLarge'));
check('an over-range POST upload stores nothing', !$prefixed->fileExists('incoming/over-range.bin'));

$post = $uploads->authorize('incoming/expected.bin', 1024);
[$status] = postUpload($post, 'tampered', ['key' => 'direct-prefix/incoming/chosen.bin']);
check('a POST upload with a tampered key is refused with 403', $status === 403);
check('a tampered POST upload stores nothing', !$prefixed->fileExists('incoming/chosen.bin') && !$prefixed->fileExists('incoming/expected.bin'));

echo "ALL CHECKS PASSED\n";
