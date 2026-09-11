<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use AsyncAws\S3\ValueObject\ObjectIdentifier;
use League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter;
use League\Flysystem\AsyncAwsS3\VisibilityConverter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToDeleteDirectory;
use Throwable;

/**
 * League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter with deleteDirectory()
 * replaced; every other operation is the vendor's.
 *
 * The vendor deleteDirectory() collects an identifier for every key under
 * the prefix, across every listing page, before its first DeleteObjects,
 * so a persistent worker's memory grows with the size of the prefix.
 * Here each ListObjectsV2 page of at most 1,000 keys is deleted before
 * the next page is requested with that page's continuation token, so no
 * more than one page of identifiers is held. The sweep follows the
 * tokens once and never restarts from the first page: a restart until a
 * listing comes back empty ends only when writers under the prefix stop.
 *
 * The client, bucket and prefixer are held again here because the
 * vendor's are private.
 */
final class S3Adapter extends AsyncAwsS3Adapter
{
    private const PAGE_SIZE = 1000;

    private const NO_BATCH_CONFIRMED = 'No delete batch was confirmed complete; the directory may be partially deleted.';

    private const BATCH_CONFIRMED = 'At least one delete batch was confirmed complete; the directory may be partially deleted.';

    private readonly PathPrefixer $prefixer;

    /**
     * @param S3Client $client
     * @param list<string> $forwardedOptions
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        string $prefix,
        VisibilityConverter $visibility,
        array $forwardedOptions,
    ) {
        parent::__construct($client, $bucket, $prefix, $visibility, forwardedOptions: $forwardedOptions);

        $this->prefixer = new PathPrefixer($prefix);
    }

    /**
     * A batch counts as confirmed only once S3Client::deleteObjects() has
     * returned. A delete that failed may still have been applied — its
     * response can be lost after S3 acted on it — so neither failure
     * reason rules out a partial delete.
     */
    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $prefix = ltrim($this->prefixer->prefixDirectoryPath($path), '/');
        $token = null;
        $confirmedBatch = false;

        try {
            do {
                $page = $this->client->listObjectsV2([
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                    'MaxKeys' => self::PAGE_SIZE,
                    'ContinuationToken' => $token,
                ]);
                $objects = [];

                foreach ($page->getContents(true) as $object) {
                    $key = $object->getKey();

                    // DeleteObjects writes the key through
                    // DOMDocument::createElement(), which reads `&` as the
                    // start of an entity reference rather than escaping it.
                    if ($key !== null) {
                        $objects[] = new ObjectIdentifier(['Key' => htmlentities($key, ENT_XML1 | ENT_QUOTES, 'UTF-8')]);
                    }
                }

                if ($objects !== []) {
                    $this->client->deleteObjects(['Bucket' => $this->bucket, 'Delete' => ['Objects' => $objects]]);
                    $confirmedBatch = true;
                }

                $token = $page->getNextContinuationToken();
            } while ($token !== null);
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation(
                $path,
                $confirmedBatch ? self::BATCH_CONFIRMED : self::NO_BATCH_CONFIRMED,
                $exception,
            );
        }
    }
}
