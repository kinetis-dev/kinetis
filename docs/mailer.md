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

The sender is set on each message with `Email::from()`. There is no
default-sender key; `MAILER_DSN` and `MAILER_TIMEOUT` are the only keys
the package reads.

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
is built. SMTP ignores all three. Symfony's SMTP socket instead inherits
PHP's `default_socket_timeout` for the connection and each blocking
stream operation. One SMTP exchange performs several operations, so
that setting is an inactivity bound, not a total deadline for the send.

Whichever scheme you use, `composer require` the matching Symfony bridge
package too (`symfony/sendgrid-mailer`, `symfony/mailgun-mailer`,
`symfony/postmark-mailer`, `symfony/amazon-mailer`, ...) — `kinetis/mailer`
has no dispatch logic of its own here; `Symfony\Component\Mailer\Transport`
discovers whichever bridge classes are installed.

```{important}
Only the API-based transports are non-blocking. SMTP opens a raw socket
directly (`stream_socket_client()`), with no Fiber-yield point — sending
over SMTP blocks the worker for as long as the send takes. A blocking
send also gives the worker no chance to renew the job's reservation (see
{doc}`queue`), and neither a visibility timeout nor a shutdown grace
period can be set high enough to guarantee an SMTP send finished inside
it, so at-least-once handling must stay safe when a reservation expires
or the process is killed. See [Queueing mail](#queueing-mail) below for the practical fix,
whichever transport you choose.
```

```{warning}
A Symfony HTTP transport exception carries the provider's own debug data,
which can include the raw request headers — and therefore the API key
that authenticated the send. Kinetis never reads that data; if your own
exception handler does, do not log it.
```

## Amazon SES

Install `symfony/amazon-mailer` and use an `ses+api://` or `ses://` DSN.
Credentials in the DSN, or a platform role (EC2 instance profile, ECS
task role, or web identity), resolve without blocking: the credential
requests go through the same non-blocking HTTP client as the send.

Static keys combined with `AWS_ROLE_ARN` are the exception. Symfony's SES
transport passes AsyncAws no credential provider, so AsyncAws builds its
default chain, and that chain's `ConfigurationProvider` has no injected
HTTP client. It assumes the role through AsyncAws's own blocking HTTP
client at the first send and again at each credential expiry, and each
call blocks the worker until STS answers. Prefer a platform role, or
credentials that need no role assumption.

A credential chain that resolved nothing at the first send is remembered
until the worker restarts: AsyncAws caches the empty result, so fixing the
environment afterwards does not recover a running worker.

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

(mailer-testing)=
## Testing

`MailerInterface` is bound only when `MAILER_DSN` is set. A test that
should send nothing can set `'MAILER_DSN' => 'null://null'` in
`configOverrides()` (see {doc}`testing`), which installs Symfony's
`NullTransport`. To assert on what the code sent, replace the binding in
`registerTestDoubles()` with a recording mailer:

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class RecordingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = $message;
    }
}

protected function registerTestDoubles(AppScope $app, Config $config): void
{
    $app->instance(MailerInterface::class, new RecordingMailer());
}
```

Symfony Mailer ships no in-memory transport, so the recorder is the
application's own class.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  API-based transport runs through.
- {doc}`queue` — job queues, retries, and named connections in full.
- {doc}`config` — the named-connection convention used above.
