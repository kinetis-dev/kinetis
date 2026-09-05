<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use RuntimeException;

/**
 * A configuration failure raised while constructing
 * `SigV4SigningClient`: a trusted origin, region, or service name that
 * does not satisfy the package's own grammar.
 *
 * Every message names the field and the rule it failed, never the
 * rejected value. A configured origin can carry a password in its
 * userinfo or a token in its query string, and a caller that catches and
 * logs this exception must not copy either into a log by doing so. No
 * cause is chained: the value that failed, and any parser detail derived
 * from it, stops here.
 */
final class SigningException extends RuntimeException
{
    use SafeSerialization;

    public const string ORIGIN_NOT_ABSOLUTE
        = 'origin must be an absolute "http://" or "https://" URI.';

    public const string ORIGIN_UNSUPPORTED_SCHEME
        = 'origin must use the "http" or "https" scheme.';

    public const string ORIGIN_FORBIDDEN_COMPONENTS
        = 'origin must not carry userinfo, a query string, or a fragment.';

    public const string ORIGIN_AMBIGUOUS_CHARACTERS
        = 'origin must not contain whitespace, a control character, or a backslash.';

    public const string ORIGIN_ENCODED_AUTHORITY
        = 'origin authority must not contain a percent sign.';

    public const string ORIGIN_INVALID_HOST
        = 'origin host must be a registered name, an IPv4 address, or a bracketed IPv6 address.';

    public const string ORIGIN_INVALID_PORT
        = 'origin port must be a decimal number between 1 and 65535.';

    public const string ORIGIN_INVALID_PATH
        = 'origin path must contain only unreserved, sub-delimiter, ":", "@", "/" or well-formed '
        . 'percent-encoded characters, and no "." or ".." segment.';

    public const string INVALID_REGION
        = 'region must be 1 to 64 ASCII characters: a letter or digit, then letters, digits, ".", "-" or "_".';

    public const string INVALID_SERVICE
        = 'service must be 1 to 64 ASCII characters: a letter or digit, then letters, digits, ".", "-" or "_".';

    public static function originIsNotAbsolute(): self
    {
        return new self(self::ORIGIN_NOT_ABSOLUTE);
    }

    public static function originHasUnsupportedScheme(): self
    {
        return new self(self::ORIGIN_UNSUPPORTED_SCHEME);
    }

    public static function originHasForbiddenComponents(): self
    {
        return new self(self::ORIGIN_FORBIDDEN_COMPONENTS);
    }

    public static function originHasAmbiguousCharacters(): self
    {
        return new self(self::ORIGIN_AMBIGUOUS_CHARACTERS);
    }

    public static function originHasEncodedAuthority(): self
    {
        return new self(self::ORIGIN_ENCODED_AUTHORITY);
    }

    public static function originHasInvalidHost(): self
    {
        return new self(self::ORIGIN_INVALID_HOST);
    }

    public static function originHasInvalidPort(): self
    {
        return new self(self::ORIGIN_INVALID_PORT);
    }

    public static function originHasInvalidPath(): self
    {
        return new self(self::ORIGIN_INVALID_PATH);
    }

    public static function invalidRegion(): self
    {
        return new self(self::INVALID_REGION);
    }

    public static function invalidService(): self
    {
        return new self(self::INVALID_SERVICE);
    }

}
