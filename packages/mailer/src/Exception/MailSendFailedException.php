<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Exception;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The single exception {@see \Kinetis\Mailer\SafeMailer} throws when a send
 * fails, whatever failed underneath: MIME rendering, DKIM or S/MIME
 * signing, a temp file, an OpenSSL handshake, an SMTP conversation, a
 * provider's HTTP response, or a composite reporting that every child
 * failed.
 *
 * It implements `TransportExceptionInterface` so Symfony's documented
 * contract for `MailerInterface::send()` still holds for a caller that
 * catches it — and it satisfies that contract while retaining nothing.
 * `getDebug()` is why that matters: Symfony's own `TransportException`
 * accumulates the failing conversation into it, and `RoundRobinTransport`
 * appends each child's debug string to the next, which is where SMTP
 * dialogue lines carrying an AUTH exchange, a provider's JSON error body
 * quoting the API key it rejected, and recipient addresses all end up.
 * Here the debug string is a constant empty string and `appendDebug()`
 * keeps it that way, so a handler that logs it has nothing to log.
 *
 * The exception is thrown fresh with no `$previous` for the reason
 * {@see MailerConfigurationException} explains, and its message is one
 * of two fixed literals — a send that failed, or a send refused for
 * starting inside another send on the same mailer — so message, cause
 * chain, `__toString()`, serialization and ordinary logging all carry no
 * message body, attachment bytes, address, header, provider response or
 * credential.
 *
 * An ambiguous transport failure is reported as a failure and nothing
 * more. The send is not retried here and no delivery status is claimed: a
 * mail POST that may have been accepted before the connection broke has
 * no idempotency key in this package to make a second attempt safe.
 */
final class MailSendFailedException extends RuntimeException implements TransportExceptionInterface
{
    private const string MESSAGE = 'Sending mail failed. The transport reported an error; '
        . 'details are withheld because they carry message content and transport credentials.';

    private const string NESTED = 'Sending mail failed. The send was started from inside another send '
        . 'on the same mailer, by the same Fiber or from the main stack, and would have waited '
        . 'for a lock its own caller holds; a mailer admits one send at a time.';

    public static function sendFailed(): self
    {
        return new self(self::MESSAGE);
    }

    /**
     * The refusal {@see \Kinetis\Mailer\SafeMailer} raises before
     * touching its mutex, so a listener or decorator that sends through
     * the mailer it is running inside learns why at once rather than
     * waiting forever.
     */
    public static function nestedSend(): self
    {
        return new self(self::NESTED);
    }

    #[\Override]
    public function getDebug(): string
    {
        return '';
    }

    /**
     * Accepted and dropped. The interface requires the method; retaining
     * what a caller appends would reintroduce exactly the debug string
     * this class exists not to carry.
     */
    #[\Override]
    public function appendDebug(string $debug): void
    {
    }
}
