<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Bedrock;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;

/** AWS's documentation example key pair, never a real credential. */
final class StaticCredentials implements CredentialProvider
{
    public const string ACCESS_KEY = 'AKIDEXAMPLE';

    public const string SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';

    public function getCredentials(Configuration $configuration): Credentials
    {
        return new Credentials(self::ACCESS_KEY, self::SECRET_KEY);
    }
}
