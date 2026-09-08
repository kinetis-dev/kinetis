# Mailer

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/mailer
```
````

Mail sending via `Symfony\Component\Mailer`. A single DSN selects the
transport — SMTP, or any of Symfony's own API-based bridges (Sendgrid,
Mailgun, Postmark, SES, ...).

```{code-block} php
use Kinetis\Mailer\MailerFactory;
use Symfony\Component\Mime\Email;

$mailer = MailerFactory::fromConfig($config);

$email = new Email()
    ->from('noreply@example.com')
    ->to('user@example.com')
    ->subject('Welcome!')
    ->text('Thanks for signing up.');

$mailer->send($email);
```

## Configuring

```{code-block} text
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

Or, using an API-based transport instead — the scheme selects it:

```{code-block} text
MAILER_DSN=sendgrid+api://KEY@default
MAILER_TIMEOUT=30
```

`MAILER_TIMEOUT` is seconds, default `30`. It bounds one API send twice
over — as the idle timeout between bytes and as the total duration — and
redirects are disabled, so the provider endpoint the DSN names is the
only one contacted. Zero or a negative value is rejected when the mailer
is built. SMTP ignores all three: that transport carries its own
timeouts from the DSN.

Whichever scheme you use, `composer require` the matching Symfony bridge
package too (`symfony/sendgrid-mailer`, `symfony/mailgun-mailer`,
`symfony/postmark-mailer`, `symfony/amazon-mailer`, ...) — `kinetis/mailer`
has no dispatch logic of its own here; `Symfony\Component\Mailer\Transport`
discovers whichever bridge classes are installed.

```{important}
Only the API-based transports are non-blocking. SMTP opens a raw socket
directly (`stream_socket_client()`), with no Fiber-yield point — sending
over SMTP blocks the worker for as long as the send takes. See
[Queueing mail](#queueing-mail) below for the practical fix, whichever
transport you choose.
```

```{warning}
A Symfony HTTP transport exception carries the provider's own debug data,
which can include the raw request headers — and therefore the API key
that authenticated the send. Kinetis never reads that data; if your own
exception handler does, do not log it.
```

## Named connections

```{code-block} php
$transactional = MailerFactory::fromConfig($config, 'transactional');
```

```{code-block} text
MAILER_TRANSACTIONAL_DSN=sendgrid+api://KEY@default
MAILER_TRANSACTIONAL_TIMEOUT=10
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `MAILER_DSN`/`MAILER_TIMEOUT` above, and any
other name reads `MAILER_{NAME}_DSN`/`MAILER_{NAME}_TIMEOUT` instead.

## Queueing mail

Sending mail from inside a request means the client waits for it — and on
SMTP that wait blocks the worker. `kinetis/mailer` needs to provide
nothing for this: a {doc}`queue <queue>` job that constructor-injects
`MailerInterface` already gets it, with no extra code in either package.

```{code-block} php
use Kinetis\Queue\Job;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SendWelcomeEmailJob implements Job
{
    public function __construct(
        public string $toEmail,
    ) {}

    public function handle(MailerInterface $mailer): void
    {
        $email = new Email()
            ->from('noreply@example.com')
            ->to($this->toEmail)
            ->subject('Welcome!')
            ->text('Thanks for signing up.');

        $mailer->send($email);
    }
}
```

```{code-block} php
$queue->push(new SendWelcomeEmailJob($user->email));
```

With `MAILER_DSN` set, `MailerInterface` is already bound — installing
the package registers it — so any job's `handle()` method can depend on
it exactly like a repository or any other service, with nothing in
`bootstrap.php` at all. The transport is built while the package
registers, so a malformed DSN or a missing bridge package fails at boot
rather than on the first job that sends. An application's own
`bootstrap.php` runs after that and still replaces the binding.

A slow SMTP send then occupies one queue worker's Fiber for one job
instead of the worker handling an HTTP request.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  API-based transport runs through.
- {doc}`queue` — job queues, retries, and named connections in full.
- {doc}`config` — the named-connection convention used above.
