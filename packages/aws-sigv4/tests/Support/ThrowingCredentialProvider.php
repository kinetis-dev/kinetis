<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use RuntimeException;

/**
 * A provider whose failure carries sentinel text standing in for what a
 * real one leaks: the endpoint it was reaching for and the token file it
 * read. Nothing this package raises may carry either.
 */
final class ThrowingCredentialProvider implements CredentialProvider
{
    public const string FAILURE_MESSAGE
        = 'PROVIDER-ENDPOINT-SENTINEL http://169.254.170.2/creds token file TOKEN-FILE-SENTINEL';

    #[\Override]
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        throw new RuntimeException(self::FAILURE_MESSAGE);
    }
}
