<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

final class SigningException extends \RuntimeException
{
    public static function noCredentialsResolved(): self
    {
        return new self(
            'Could not resolve AWS credentials to sign this request. Set '
            . 'AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, a shared credentials '
            . 'file, or run somewhere with an IAM role attached, or pass a '
            . 'CredentialProvider directly.',
        );
    }

    public static function invalidBaseUri(string $baseUri): self
    {
        return new self("baseUri \"{$baseUri}\" is not a valid absolute URI (must include a scheme and host).");
    }
}
