<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Exception;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown by {@see \Kinetis\Mailer\NoRedirectHttpClient} when a request
 * would leave the boundary that client exists to hold: anything but an
 * absolute HTTPS URL carrying no userinfo.
 *
 * It implements Symfony's HTTP client `TransportExceptionInterface`, so a
 * bridge that catches transport failures around its own request handles
 * this one the way it handles a connection failure, rather than meeting
 * an exception type it has no branch for.
 *
 * The message is one fixed literal. The rejected URL and options are the
 * things being refused for carrying a provider token, an address list and
 * message content, so neither is quoted back into an exception that a
 * handler will log.
 */
final class InsecureRequestException extends RuntimeException implements TransportExceptionInterface
{
    private const string MESSAGE = 'The mailer HTTP client refused a request: an API transport may only '
        . 'be given an absolute https:// URL carrying no userinfo, and the request target was not one.';

    public static function notAbsoluteHttps(): self
    {
        return new self(self::MESSAGE);
    }
}
