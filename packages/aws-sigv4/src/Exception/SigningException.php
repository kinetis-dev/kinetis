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

    /**
     * Deliberately never echoes the configured baseUri itself: this is
     * reachable with a fully attacker/operator-supplied string (including
     * one that failed to parse at all), and a URI carrying userinfo or a
     * query string may carry a credential or token as part of that same
     * string — one that a caller catching and logging this exception would
     * otherwise copy verbatim into a log. Reporting only the category of
     * problem, never the raw input, holds for every baseUri exception this
     * class throws, not just this one.
     */
    public static function invalidBaseUri(): self
    {
        return new self('baseUri is not a valid absolute URI (must include a scheme and host).');
    }

    /**
     * $scheme alone is safe to report: parse_url() only ever populates it
     * from the leading `scheme:` token, which RFC 3986 restricts to
     * `ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )` — it cannot itself carry
     * userinfo/query content, unlike the full configured baseUri.
     */
    public static function unsupportedBaseUriScheme(string $scheme): self
    {
        return new self(
            "baseUri uses unsupported scheme \"{$scheme}\" — only \"http\" and \"https\" are supported.",
        );
    }

    public static function unsupportedBaseUriComponents(): self
    {
        return new self(
            'baseUri must not include userinfo, a query string, or a fragment '
            . '— only scheme, host, port, and path are used.',
        );
    }
}
