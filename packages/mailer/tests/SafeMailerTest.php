<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Amp\DeferredFuture;
use Amp\Future;
use Kinetis\Mailer\Exception\MailSendFailedException;
use Kinetis\Mailer\SafeMailer;
use Kinetis\Mailer\Tests\Doubles\CallbackMailer;
use Kinetis\Mailer\Tests\Doubles\GatedMailer;
use Kinetis\Mailer\Tests\Doubles\ThrowingMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Throwable;

use function Amp\async;
use function Amp\delay;

final class SafeMailerTest extends TestCase
{
    use RendersThrowables;

    private const string BODY = 'BODYSENTINEL-account-recovery-code-77213';

    private const string ADDRESS = 'victim-SENTINEL@example.com';

    private const string CREDENTIAL = 'PROVIDERKEY-SENTINEL-9f2a';

    public function test_a_successful_send_reaches_the_delegate(): void
    {
        $delegate = new GatedMailer();

        new SafeMailer($delegate)->send($this->email());

        self::assertSame(1, $delegate->completed);
    }

    public function test_every_failure_becomes_one_fixed_package_exception(): void
    {
        $mailer = new SafeMailer(new ThrowingMailer(new \RuntimeException('smtp: 535 auth failed')));

        try {
            $mailer->send($this->email());
        } catch (MailSendFailedException $e) {
            self::assertInstanceOf(TransportExceptionInterface::class, $e);
            self::assertNull($e->getPrevious());
            self::assertSame('', $e->getDebug());
            self::assertStringNotContainsString('535', $e->getMessage());

            return;
        }

        self::fail('The failure was not normalized.');
    }

    public function test_the_debug_string_stays_empty_however_it_is_appended_to(): void
    {
        $exception = MailSendFailedException::sendFailed();
        $exception->appendDebug("250 OK\nAUTH PLAIN " . self::CREDENTIAL);

        self::assertSame('', $exception->getDebug());
    }

    public function test_no_sentinel_survives_into_any_rendering_of_the_failure(): void
    {
        $vendorFailure = new TransportException('POST failed for ' . self::ADDRESS);
        $vendorFailure->appendDebug('Authorization: Bearer ' . self::CREDENTIAL);

        $mailer = new SafeMailer(new ThrowingMailer($vendorFailure));

        try {
            $mailer->send($this->email());
        } catch (MailSendFailedException $e) {
            $this->assertNoSentinelSurvives($e, [self::BODY, self::ADDRESS, self::CREDENTIAL]);

            return;
        }

        self::fail('The failure was not normalized.');
    }

    public function test_a_failed_send_is_not_retried_and_leaves_the_mailer_usable(): void
    {
        $delegate = new ThrowingMailer(new \RuntimeException('transient'), failuresBeforeSuccess: 1);
        $mailer = new SafeMailer($delegate);

        try {
            $mailer->send($this->email());
            self::fail('The first send did not fail.');
        } catch (MailSendFailedException) {
            self::assertSame(1, $delegate->calls, 'the failed send is attempted exactly once');
        }

        // The same sender sends again. Were ownership left behind by the
        // failure, this call would be refused as a nested send; were the
        // lock left behind, it would never run.
        $mailer->send($this->email());

        self::assertSame(2, $delegate->calls, 'the lock and the ownership were released, and only the second send ran');
    }

    // --- a nested send fails fast -----------------------------------------------

    public function test_a_send_started_inside_the_same_send_on_the_main_stack_is_refused_at_once(): void
    {
        $mailer = null;
        $nested = null;
        $delegate = new CallbackMailer(function () use (&$mailer, &$nested): void {
            try {
                $mailer->send($this->email());
            } catch (Throwable $e) {
                $nested = $e;

                throw $e;
            }
        });
        $mailer = new SafeMailer($delegate);

        try {
            $mailer->send($this->email());
            self::fail('The outer send did not report its listener\'s failure.');
        } catch (MailSendFailedException $outer) {
            self::assertSame(MailSendFailedException::sendFailed()->getMessage(), $outer->getMessage());
            self::assertNull($outer->getPrevious());
        }

        // A nested send that waited instead of failing could not have
        // thrown this: the main stack has no other work to run, so such
        // a wait ends in the event loop's own error, not in a refusal.
        self::assertInstanceOf(MailSendFailedException::class, $nested);
        self::assertSame(MailSendFailedException::nestedSend()->getMessage(), $nested->getMessage());
        self::assertNull($nested->getPrevious());
        self::assertSame(1, $delegate->calls, 'the nested send never reached the delegate');
    }

    public function test_a_send_started_inside_the_same_send_in_a_fiber_is_refused_at_once(): void
    {
        $mailer = null;
        $nested = null;
        $delegate = new CallbackMailer(function () use (&$mailer, &$nested): void {
            try {
                $mailer->send($this->email());
            } catch (Throwable $e) {
                $nested = $e;
            }
        });
        $mailer = new SafeMailer($delegate);

        // The listener swallows the refusal, so the outer send completes.
        async(fn () => $mailer->send($this->email()))->await();

        self::assertInstanceOf(MailSendFailedException::class, $nested);
        self::assertSame(MailSendFailedException::nestedSend()->getMessage(), $nested->getMessage());
        self::assertSame(1, $delegate->calls);
    }

    public function test_a_refused_nested_send_leaves_neither_ownership_nor_the_lock_behind(): void
    {
        $mailer = null;
        $delegate = new CallbackMailer(function (int $call) use (&$mailer): void {
            if ($call === 1) {
                $mailer->send($this->email());
            }
        });
        $mailer = new SafeMailer($delegate);

        try {
            $mailer->send($this->email());
            self::fail('The outer send did not fail.');
        } catch (MailSendFailedException) {
            // The sends below are the assertion.
        }

        $mailer->send($this->email());
        async(fn () => $mailer->send($this->email()))->await();

        self::assertSame(3, $delegate->calls, 'the main stack and a Fiber both sent after the refusal');
    }

    public function test_a_nested_send_by_another_fiber_waits_for_the_lock_rather_than_being_refused(): void
    {
        $mailer = null;
        $inner = null;
        $innerFailure = null;
        $callsWhileHeld = null;
        $attempting = new DeferredFuture();

        $delegate = new CallbackMailer(function (int $call) use (&$mailer, &$inner, &$innerFailure, &$callsWhileHeld, $attempting): void {
            if ($call !== 1) {
                return;
            }

            $inner = async(function () use (&$mailer, &$innerFailure, $attempting): void {
                $attempting->complete(null);

                try {
                    $mailer->send($this->email());
                } catch (Throwable $e) {
                    $innerFailure = $e;
                }
            });

            // Resumed only after the inner Fiber has reached its own
            // send() while this one still holds the lock.
            $attempting->getFuture()->await();
            $callsWhileHeld = $mailer === null ? null : $this->callsOf($mailer);
        });
        $mailer = new SafeMailer($delegate);

        $mailer->send($this->email());
        $inner->await();

        self::assertSame(1, $callsWhileHeld, 'the other Fiber had not entered the delegate while the lock was held');
        self::assertNull($innerFailure, 'a different sender is a waiter, not a nested send');
        self::assertSame(2, $delegate->calls, 'the waiter ran once the lock was released');
    }

    // --- one send at a time ------------------------------------------------------

    public function test_one_mailer_admits_one_send_at_a_time_while_other_work_progresses(): void
    {
        $first = new DeferredFuture();
        $second = new DeferredFuture();
        $delegate = new GatedMailer([$first->getFuture(), $second->getFuture()]);
        $mailer = new SafeMailer($delegate);

        $sends = [
            async(fn () => $mailer->send($this->email())),
            async(fn () => $mailer->send($this->email())),
        ];

        $unrelated = 0;
        $elsewhere = async(static function () use (&$unrelated): void {
            for ($i = 0; $i < 5; ++$i) {
                delay(0);
                ++$unrelated;
            }
        });

        $delegate->entered(1)->await();
        $elsewhere->await();

        self::assertSame(1, $delegate->inside, 'the second send is waiting outside the delegate');
        self::assertSame(0, $delegate->completed);
        self::assertSame(5, $unrelated, 'an unrelated Fiber runs while a send is suspended');

        $first->complete(null);
        $delegate->entered(2)->await();

        self::assertSame(1, $delegate->inside, 'the second send took the lock the first released');
        self::assertSame(1, $delegate->completed);

        $second->complete(null);
        Future\await($sends);

        self::assertSame(1, $delegate->peak, 'never more than one send inside the delegate');
        self::assertSame(2, $delegate->completed);
    }

    public function test_the_lock_is_released_when_a_send_fails_under_concurrency(): void
    {
        $gate = new DeferredFuture();
        $delegate = new GatedMailer([$gate->getFuture()]);
        $mailer = new SafeMailer($delegate);

        $first = async(fn () => $mailer->send($this->email()));
        $delegate->entered(1)->await();

        $gate->error(new \RuntimeException('the connection dropped'));

        try {
            $first->await();
            self::fail('The failing send did not surface as a failure.');
        } catch (MailSendFailedException) {
            // The next send is the assertion.
        }

        $mailer->send($this->email());

        self::assertSame(0, $delegate->inside);
        self::assertSame(1, $delegate->completed, 'the mailer is usable after a failure');
    }

    public function test_two_named_mailers_progress_independently(): void
    {
        $blocked = new DeferredFuture();
        $slowDelegate = new GatedMailer([$blocked->getFuture()]);
        $slow = new SafeMailer($slowDelegate);
        $other = new GatedMailer();
        $fast = new SafeMailer($other);

        $held = async(fn () => $slow->send($this->email()));
        $slowDelegate->entered(1)->await();

        $fast->send($this->email());

        self::assertSame(1, $other->completed, 'a second mailer is not held by the first mailer lock');
        self::assertSame(0, $slowDelegate->completed);

        $blocked->complete(null);
        $held->await();
    }

    // --- helpers -------------------------------------------------------------------

    private function email(): Email
    {
        return new Email()
            ->from('noreply@example.com')
            ->to(self::ADDRESS)
            ->subject('Recovery')
            ->text(self::BODY);
    }

    private function callsOf(SafeMailer $mailer): int
    {
        $delegate = new \ReflectionProperty(SafeMailer::class, 'mailer')->getValue($mailer);
        self::assertInstanceOf(CallbackMailer::class, $delegate);

        return $delegate->calls;
    }
}
