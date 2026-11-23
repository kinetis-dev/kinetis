<?php

declare(strict_types=1);

namespace Kinetis\StorageS3;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\Enum\ObjectCannedACL;
use AsyncAws\S3\Input\CopyObjectRequest;
use AsyncAws\S3\Input\DeleteObjectsRequest;
use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\Result\CopyObjectOutput;
use AsyncAws\S3\Result\DeleteObjectsOutput;
use AsyncAws\S3\Result\PutObjectOutput;
use AsyncAws\S3\S3Client as AsyncAwsS3Client;
use Kinetis\StorageS3\Exception\S3OperationException;

/**
 * AsyncAws's S3 client, corrected at the three points
 * League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter needs it to be.
 *
 * ACL: the adapter puts an `ACL` on every write and every copy, and its
 * default is `private`. A bucket with Object Ownership set to bucket
 * owner enforced rejects any request carrying an ACL header at all, so
 * that default alone fails every write. This client drops `private`
 * rather than sending it, and refuses any other value: object access on
 * such a bucket is a bucket policy's to grant, and there is no ACL mode
 * here that would grant it.
 *
 * Resolution: an AsyncAws Result is lazy. The adapter discards the
 * result of putObject, copyObject and deleteObjects, so the request is
 * resolved by Response::__destruct(), whose exception surfaces at an
 * arbitrary later point instead of at the call. Each override resolves
 * before returning.
 *
 * Result inspection: S3 answers a failed copy and a partly failed batch
 * delete with HTTP 200 and an error document, which resolution alone
 * treats as success. copyObject therefore requires an ETag, and
 * deleteObjects requires an empty error list. Failing copyObject is also
 * what stops the adapter's move() from deleting the source it never
 * copied.
 *
 * Browser uploads: bucketUrl() and signingRegion() expose the vendor's
 * protected addressing and signing-region rules to DirectUploads, which
 * signs a POST policy for the same destination this client writes to.
 */
final class S3Client extends AsyncAwsS3Client
{
    /**
     * @param array<string, mixed>|PutObjectRequest $input
     */
    #[\Override]
    public function putObject($input): PutObjectOutput
    {
        $request = PutObjectRequest::create($input);
        self::refuseAcl('PutObject', $request->getAcl());
        $request->setAcl(null);

        $result = parent::putObject($request);
        $result->resolve();

        return $result;
    }

    /**
     * @param array<string, mixed>|CopyObjectRequest $input
     */
    #[\Override]
    public function copyObject($input): CopyObjectOutput
    {
        $request = CopyObjectRequest::create($input);
        self::refuseAcl('CopyObject', $request->getAcl());
        $request->setAcl(null);

        $result = parent::copyObject($request);
        $result->resolve();

        if ($result->getCopyObjectResult()?->getEtag() === null) {
            throw S3OperationException::copyWithoutEtag();
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|DeleteObjectsRequest $input
     */
    #[\Override]
    public function deleteObjects($input): DeleteObjectsOutput
    {
        $result = parent::deleteObjects(DeleteObjectsRequest::create($input));
        $result->resolve();

        if ($result->getErrors() !== []) {
            throw S3OperationException::batchDeleteFailed();
        }

        return $result;
    }

    /**
     * The URL a browser-based POST upload targets: the vendor's own
     * endpoint for the bucket with no key, so it follows the same
     * virtual-hosted or path-style rule as every request this client
     * sends, and ends in `/`.
     */
    public function bucketUrl(string $bucket): string
    {
        return $this->getEndpoint('/' . rawurlencode($bucket) . '/', [], null);
    }

    /**
     * The region this client's requests are signed for. It differs from
     * the configured region for AsyncAws's pseudo-regions — `fips-*`
     * and `s3-external-1` sign as the region they name — and equals it
     * whenever an endpoint is configured, the same choice the vendor's
     * signer makes.
     */
    public function signingRegion(): string
    {
        $configuration = $this->getConfiguration();
        $region = $configuration->get(Configuration::OPTION_REGION) ?? Configuration::DEFAULT_REGION;

        if (!$configuration->isDefault(Configuration::OPTION_ENDPOINT)) {
            return $region;
        }

        return $this->getEndpointMetadata($region)['signRegion'];
    }

    private static function refuseAcl(string $operation, ?string $acl): void
    {
        if ($acl !== null && $acl !== ObjectCannedACL::PRIVATE) {
            throw S3OperationException::unsupportedAcl($operation);
        }
    }
}
