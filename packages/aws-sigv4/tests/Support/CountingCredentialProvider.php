<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;

/**
 * A provider that answers with whatever it currently holds and counts
 * how often it was asked, so a test can tell a held credential from a
 * re-resolved one.
 */
final class CountingCredentialProvider implements CredentialProvider
{
    public int $calls = 0;

    public function __construct(public ?Credentials $answer) {}

    #[\Override]
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        ++$this->calls;

        return $this->answer;
    }
}
