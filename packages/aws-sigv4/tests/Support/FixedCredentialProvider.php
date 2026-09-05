<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;

final class FixedCredentialProvider implements CredentialProvider
{
    public const string ACCESS_KEY = 'AKIDEXAMPLE';

    public const string SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function __construct(private readonly ?Credentials $credentials) {}

    public static function withSessionToken(string $sessionToken): self
    {
        return new self(new Credentials(self::ACCESS_KEY, self::SECRET_KEY, $sessionToken));
    }

    public static function example(): self
    {
        return new self(new Credentials(self::ACCESS_KEY, self::SECRET_KEY));
    }

    public static function none(): self
    {
        return new self(null);
    }

    #[\Override]
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        return $this->credentials;
    }
}
