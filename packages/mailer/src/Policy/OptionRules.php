<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Policy;

/**
 * The value rules a registry entry's options are checked against.
 *
 * Which options exist belongs to
 * {@see \Kinetis\Mailer\Registry\TransportRegistry}, keyed by exact
 * scheme, because consumption differs branch by branch inside one bridge.
 * What an accepted value may look like belongs here, and each rule is as
 * narrow as the vendor's own use of the value allows: `region` is
 * interpolated into a hostname, so it is one DNS label rather than
 * anything a token regex would pass; `message_stream` becomes an
 * unstructured MIME header, so it is an identifier; `source_ip` is
 * concatenated with `:0` into a `bindto`, so a bare IPv6 address there
 * produces a string no socket can bind and is refused in favour of the
 * bracketed form.
 *
 * Every shape check runs on the string and every range check counts
 * digits. Nothing is cast before it is known to be in range:
 * `(int) '9999999999999999999999'` saturates to `PHP_INT_MAX` and
 * `(float) '1e400'` becomes `INF`, either of which would configure a
 * throttle or a threshold with a number nobody wrote.
 *
 * @internal to kinetis/mailer
 */
final class OptionRules
{
    public const string BOOL = 'bool';

    public const string SOURCE_IP = 'source_ip';

    public const string FINGERPRINT = 'fingerprint';

    public const string DOMAIN = 'domain';

    public const string RATE = 'rate';

    public const string COUNT = 'count';

    public const string SECONDS = 'seconds';

    public const string SLEEP_SECONDS = 'sleep_seconds';

    public const string DNS_LABEL = 'dns_label';

    public const string AWS_REGION = 'aws_region';

    public const string IDENTIFIER = 'identifier';

    public const string OPAQUE_TOKEN = 'opaque_token';

    /**
     * Canonical and strict: exactly `true`, `false`, `1` or `0`.
     * `FILTER_VALIDATE_BOOLEAN`, which every consumer of these options
     * eventually applies, reads `treu` as false and `on` as true, so a
     * typo would quietly become the opposite of what was written. Neither
     * spelling reaches it without passing here first.
     */
    public static function boolean(string $value): ?bool
    {
        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    public static function accepts(string $rule, string $value): bool
    {
        return match ($rule) {
            self::BOOL => self::boolean($value) !== null,
            self::SOURCE_IP => self::isSourceIp($value),
            self::FINGERPRINT => preg_match('/^(?:[0-9A-Fa-f]{32}|[0-9A-Fa-f]{40}|[0-9A-Fa-f]{64})$/D', $value) === 1,
            self::DOMAIN => self::isDomain($value),
            self::RATE => self::isDecimal($value, 0, 1000),
            self::COUNT => self::isInteger($value, 1, 1000000),
            self::SECONDS => self::isInteger($value, 1, 86400),
            self::SLEEP_SECONDS => self::isInteger($value, 0, 86400),
            self::DNS_LABEL => self::isDnsLabel($value),
            self::AWS_REGION => self::isDnsLabel($value) && strtolower($value) === $value,
            self::IDENTIFIER => preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_-]{0,62}[A-Za-z0-9])?$/D', $value) === 1,
            self::OPAQUE_TOKEN => preg_match('/^[A-Za-z0-9+\/=._-]{1,1024}$/D', $value) === 1,
            default => false,
        };
    }

    /**
     * `SocketStream::initialize()` builds `bindto` as `"{$sourceIp}:0"`,
     * which only parses when an IPv6 address is bracketed.
     */
    private static function isSourceIp(string $value): bool
    {
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return filter_var(substr($value, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * A hostname for the EHLO greeting, or a bracketed address literal —
     * the two forms RFC 5321 allows there. The literal is validated as an
     * address rather than as a run of hex and colons.
     */
    private static function isDomain(string $value): bool
    {
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return filter_var(substr($value, 1, -1), FILTER_VALIDATE_IP) !== false;
        }

        return self::isHostname($value);
    }

    public static function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }

        foreach (explode('.', $value) as $label) {
            if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isDnsLabel(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $value) === 1;
    }

    private static function isInteger(string $value, int $min, int $max): bool
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $value) !== 1) {
            return false;
        }

        $parsed = (int) $value;

        return $parsed >= $min && $parsed <= $max;
    }

    private static function isDecimal(string $value, int $min, int $max): bool
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,3})?$/D', $value) !== 1) {
            return false;
        }

        $parsed = (float) $value;

        return $parsed >= $min && $parsed <= $max;
    }
}
