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
}
