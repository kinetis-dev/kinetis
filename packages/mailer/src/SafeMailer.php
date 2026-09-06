<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Amp\Sync\LocalMutex;
use Fiber;
use Kinetis\Mailer\Exception\MailSendFailedException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * The `MailerInterface` a consumer receives: one mailer, one send at a
 * time, and one error shape.
 *
 * **Serialized sends.** A Symfony mailer is not safe to re-enter. An
 * `EsmtpTransport` holds a live socket and a message counter across
 * calls; `RoundRobinTransport` and `FailoverTransport` hold a cursor and
 * a dead-transport set; the throttling `max_per_second` implements is a
 * running timestamp. Two Fibers inside one `send()` interleave all of
 * that. The `LocalMutex` here is per constructed mailer, so two named
 * connections progress independently while one mailer has at most one
 * send in flight. The lock is released in `finally`, including on the
 * failure path, so a failed send leaves the next one able to run. Waiting
 * senders are not promised FIFO order — `Amp\Sync\LocalSemaphore` decides
 * that, and nothing here adds a guarantee on top of it.
 *
 * **A nested send fails fast.** The mutex is not reentrant, and the
 * sender holding it is the one sender that can never be handed it again:
 * a `send()` started from inside this mailer's own in-flight `send()` by
 * the same Fiber — or by the main stack, where no Fiber runs — is a
 * synchronous listener on `MessageEvent`, a decorator, or a transport
 * calling back into the mailer that called it, and it would wait for a
 * lock its own caller releases only after it returns. In a persistent
 * worker that wait never ends. The mailer records who holds the lock and
 * refuses that sender at once with {@see MailSendFailedException},
 * before touching the mutex; the outer send then fails the way any send
 * whose delegate threw does, and the lock is released. A nested send by
 * a different Fiber is an ordinary waiter and is admitted once the lock
 * is free.
 *
 * **What "non-blocking" means here.** An API transport's network wait
 * yields, because it runs through {@see NoRedirectHttpClient} over
 * `kinetis/revolt-http-client`. Nothing else about a send does. Symfony
 * MIME reads attachments off disk, encodes them, and runs DKIM, S/MIME
 * and OpenSSL preparation synchronously, and SMTP opens a blocking socket
 * with no yield point at all — an SMTP send occupies its worker thread
 * for the whole conversation. Queueing a send moves that cost off an HTTP
 * worker; it does not make the send Fiber-concurrent.
 *
 * **Duplicate delivery.** A `failover(...)` or `roundrobin(...)` DSN can
 * try another child after a failure that was ambiguous — a POST whose
 * response never arrived may still have been accepted — so an explicit
 * composite is at-least-once and can duplicate a message. Nothing here
 * retries a send on its own, and a failure is reported as a failure with
 * no delivery status claimed.
 *
 * Every ordinary `Throwable` from rendering, signing, the provider or the
 * transport becomes one {@see MailSendFailedException} carrying nothing
 * from the original — see that class for why.
 */
final class SafeMailer implements MailerInterface
{
    private readonly LocalMutex $mutex;

    /**
     * Who holds the mutex: the Fiber inside whose `send()` it was taken,
     * {@see RootSender::Instance} when that `send()` runs on the main
     * stack, and null between sends. Read before the mutex is touched
     * and cleared in `finally`, so ownership never outlives the lock.
     */
    private Fiber|RootSender|null $owner = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly MailerInterface $mailer,
    ) {
        $this->mutex = new LocalMutex();
    }

    /**
     * @throws MailSendFailedException
     */
    #[\Override]
    public function send(
        #[\SensitiveParameter] RawMessage $message,
        #[\SensitiveParameter] ?Envelope $envelope = null,
    ): void {
        $sender = Fiber::getCurrent() ?? RootSender::Instance;

        if ($this->owner === $sender) {
            throw MailSendFailedException::nestedSend();
        }

        $lock = $this->mutex->acquire();
        $this->owner = $sender;

        try {
            $this->mailer->send($message, $envelope);
        } catch (Throwable) {
            throw MailSendFailedException::sendFailed();
        } finally {
            $this->owner = null;
            $lock->release();
        }
    }
}
