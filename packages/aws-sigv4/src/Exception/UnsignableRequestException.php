<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * `SigV4SigningClient` could not prepare and sign an on-origin request.
 * One fixed message per category — credentials unavailable, credential
 * provider failure, body capture failure, signing failure — so a caller
 * can tell the four apart without any of them carrying a value.
 *
 * See {@see ClientFailureException} for the message, cause, and
 * serialization rules every failure in this namespace shares.
 */
final class UnsignableRequestException extends RequestFailureException
{
    public const string CREDENTIALS_UNAVAILABLE
        = 'AWS credentials could not be resolved for this request. Set AWS_ACCESS_KEY_ID/'
        . 'AWS_SECRET_ACCESS_KEY, a shared credentials file, run somewhere with an IAM role '
        . 'attached, or pass a CredentialProvider.';

    public const string CREDENTIAL_PROVIDER_FAILED
        = 'The AWS credential provider failed while resolving credentials for this request.';

    public const string BODY_CAPTURE_FAILED
        = 'The request body could not be captured for signing.';

    public const string SIGNING_FAILED
        = 'The request could not be signed.';

    public static function credentialsUnavailable(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::CREDENTIALS_UNAVAILABLE);
    }

    public static function credentialProviderFailed(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::CREDENTIAL_PROVIDER_FAILED);
    }

    public static function bodyCaptureFailed(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::BODY_CAPTURE_FAILED);
    }

    public static function signingFailed(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::SIGNING_FAILED);
    }
}
