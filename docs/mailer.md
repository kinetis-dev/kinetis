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

$email = (new Email())
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
```

Whichever scheme you use, `composer require` the matching Symfony bridge
package too (`symfony/sendgrid-mailer`, `symfony/mailgun-mailer`,
`symfony/postmark-mailer`, `symfony/amazon-mailer`, ...) — `kinetis/mailer`
has no dispatch logic of its own here; `Symfony\Component\Mailer\Transport`
discovers whichever bridge classes are actually installed.

```{important}
Only the API-based transports are non-blocking. SMTP opens a raw socket
directly (`stream_socket_client()`), with no Fiber-yield point at all —
sending over SMTP blocks the worker for as long as the send takes, the
same way any other genuinely blocking call would. See
[Queueing mail](#queueing-mail) below for the practical fix, regardless
of which transport you choose.
```

## Named connections

```{code-block} php
$transactional = MailerFactory::fromConfig($config, 'transactional');
```

```{code-block} text
MAILER_TRANSACTIONAL_DSN=sendgrid+api://KEY@default
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `MAILER_DSN` above, and any other name reads
`MAILER_{NAME}_DSN` instead.

## Queueing mail

Sending mail from inside a request means the client waits for it — and if
you're on SMTP, that wait is a genuinely blocking one. The fix isn't
anything `kinetis/mailer` needs to provide: a {doc}`queue <queue>` job
that constructor-injects `MailerInterface` already gets this for free,
with no extra code in either package.

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
        $email = (new Email())
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
`bootstrap.php` at all.

Now a slow SMTP send only occupies one queue worker's own Fiber for one
job, instead of the worker handling that HTTP request — the same
reasoning behind queueing any other slow, non-critical-path work.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  API-based transport actually runs through.
- {doc}`queue` — job queues, retries, and named connections in full.
- {doc}`config` — the named-connection convention used above.
