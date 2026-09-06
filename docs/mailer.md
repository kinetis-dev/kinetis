# Mailer

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/mailer
```
````

Mail sending via `Symfony\Component\Mailer`. A single DSN selects the
transport — SMTP, or one of the Symfony API bridges in the
[supported registry](#supported-transports).

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
MAILER_DSN=smtps://user:pass@smtp.example.com
```

Or, using an API-based transport instead — the scheme selects it:

```{code-block} text
MAILER_DSN=sendgrid+api://KEY@default
```

Whichever scheme you use, `composer require` the matching bridge package
too. Every bridge stays optional: the registry names each factory as a
plain string and loads it only once its own scheme is selected, so an
application using SMTP installs nothing extra.

With `MAILER_DSN` set, installing the package binds
`Symfony\Component\Mailer\MailerInterface` for you. The DSN is parsed,
judged and built during registration rather than on first use: no Symfony
transport factory touches the network, so proving the configuration costs
a boot and a DSN that would have sent mail in the clear fails the deploy
instead of the first send. A DSN that fails publishes no binding at all,
leaving whatever was already registered untouched.

## Supported transports

Only the schemes in this table are built. Each row names the exact
Symfony 8.1 factory that builds it and the exact class that factory must
return, both read off that factory's own source — nothing about a bridge
is inferred, because nothing about one can be. Symfony's
`SesHttpAsyncAwsTransport` extends `AbstractTransport` rather than
`AbstractHttpTransport`, so a family check would reject a legitimate SES
API transport while accepting an impostor that merely extends the wider
class. Both names are held as exact runtime classes rather than by
`instanceof`: a subclass of the named transport is exactly the shape an
impostor takes, and is refused.

| Package | Schemes | Credentials | Custom endpoint | Options |
|---|---|---|---|---|
| `symfony/mailer` | `smtp`, `smtps` | user and password | host and port | the ESMTP set below |
| `symfony/mailer` | `null` | none | no | none |
| `symfony/mailer` | `sendmail`, `sendmail+smtp` | none | no | none |
| `symfony/sendgrid-mailer` | `sendgrid+api` | one API key | yes | `region` |
| `symfony/sendgrid-mailer` | `sendgrid`, `sendgrid+smtp`, `sendgrid+smtps` | one API key | no | `region` |
| `symfony/amazon-mailer` | `ses+api`, `ses+https`, `ses` | key and secret, **or neither** | yes | `region`, `session_token` |
| `symfony/amazon-mailer` | `ses+smtp`, `ses+smtps` | user and password | yes | `region`, `require_tls`, `ping_threshold` |
| `symfony/mailgun-mailer` | `mailgun+api`, `mailgun+https`, `mailgun` | key and domain | yes | `region` |
| `symfony/postmark-mailer` | `postmark+api` | one server token | yes | `message_stream` |
| `symfony/google-mailer` | `gmail`, `gmail+smtp`, `gmail+smtps` | user and password | no | none |

A scheme outside the table is refused, and so is a factory class that
turns out to be something other than the one named — a `class_alias()`
registered under the official name resolves to another class, and is
refused with it — and a transport that comes back as anything but
exactly the class the scheme resolves to.

Three absences have reasons. **Mailgun's and Postmark's SMTP branches**
build transports that connect on port 587 with TLS off and never call
`setRequireTls()`, so they negotiate opportunistic STARTTLS — the
downgrade refused everywhere else here. Their API schemes carry the same
mail over HTTPS. **`native`** is decided by php.ini at construction:
`NativeTransportFactory` returns a local sendmail process where
`sendmail_path` is set, and on Windows a raw SMTP socket to whatever host
`SMTP` and `smtp_port` name. A family that is not known until the object
exists cannot be judged before it is built, and every rule here runs
before construction. And **`ses+api` with no credentials at all** is the
one admitted way to configure a transport without them: Symfony passes
`null` straight into an AsyncAws `Configuration`, where it means "resolve
the ambient AWS chain" — an instance role or a shared profile.

Adding a transport means reading its factory and writing its row. That is
the cost of the guarantee rather than an obstacle to it.

## What a DSN is allowed to say

The complete DSN is read by `kinetis/mailer` itself, into a tree of its
own, before any part of it reaches Symfony. Every member of every
`failover(...)` and `roundrobin(...)`, at every depth, is judged before a
single transport is constructed, so one insecure or malformed member
rejects the whole DSN with nothing built — a composite cannot hide a
plaintext relay behind a secure first choice.

### SMTP

```{code-block} text
MAILER_DSN=smtps://user:pass@smtp.example.com
MAILER_DSN=smtp://user:pass@smtp.example.com:587?require_tls=true
```

Both spellings are encrypted, authenticated and peer-verified, which is
what production SMTP has to be here:

- `smtps://` carries implicit TLS. `smtp://` has to ask for **required**
  STARTTLS with `require_tls=true` — opportunistic TLS negotiates down to
  plaintext against a server that declines the offer, and against
  anything able to strip it.
- A username and a password are both required, and neither half may be
  empty. `smtp://user:@host` and `smtp://:pass@host` are refused as
  malformed rather than read as "no password".
- A half reading exactly `0`, written as `0` or escaped as `%30`, is
  refused for `smtp://` and `smtps://`. Symfony's built-in factory applies
  each half under a truthiness test, so that value would pass every other
  check and configure nothing. A bridge's factory configures it as the
  token it is, and is not held to this rule.
- `verify_peer=false` is refused outright. No profile here relaxes it.

A bridge's own SMTP scheme — `gmail+smtp`, `ses+smtp` — is SMTP too, so a
prefix buys no exemption. It still needs the credentials its factory
reads, and an option cannot turn its required TLS off.

### API transports

```{code-block} text
MAILER_DSN=sendgrid+api://KEY@default
MAILER_DSN=ses+api://KEY:SECRET@default?region=eu-west-1
```

The HTTP client every API transport receives enforces the wire boundary
itself, on every request a bridge makes:

- **The target is absolute HTTPS.** A `http://` endpoint, a
  protocol-relative `//host/path`, a path resolved against a `base_uri`,
  and anything unparseable are refused before the request is made. A
  bridge builds its own endpoint from the DSN's host, so this is the last
  place that endpoint can be checked.
- **No credential rides in the URL.** A target carrying `user:pass@` is
  refused too. Every bridge in the registry sends its credential in a
  header or a signed request, and a URL is what reaches an access log, a
  proxy and a trace.
- **The certificate is verified.** `verify_peer` and `verify_host` are
  forced on over anything a caller or a factory default supplied.
- **No redirect is followed**, and **no request is repeated**.

A mail POST carries the provider authorization header, every recipient
address, the message headers and body, and any attachment bytes;
following a `Location` would replay all of it against whatever host the
response named. Repeating one is worse: a POST whose response never
arrived may already have been accepted, and this package publishes no
idempotency contract that would make a second attempt safe.

The rules are written on the way through rather than merged under, and a
client derived with `withOptions()` carries them too, so a bridge cannot
derive its way out of one.

What the client promises about exceptions is narrower than what the
mailer promises. Its own refusal is
`Kinetis\Mailer\Exception\InsecureRequestException`, with one fixed
message and no cause, and every parameter of every method — the wrapped
client, the URL, the options, the responses handed to `stream()` — is
marked `#[\SensitiveParameter]`, so the frames the client contributes to
a trace render redacted. Failures raised by the client underneath are not
rewritten: Symfony's contract has a bridge catch them by interface and
read the response they carry, and an exception replaced here would take
both away from the bridge that handles them. The boundary that makes a
*send* secret-free is the mailer's own — see [Errors](#errors).

### Discarding, and local processes

```{code-block} text
MAILER_DSN=null://null
```

```{warning}
`null://null` accepts every message and drops it. It is a successful send
that delivers nothing, in **every** environment including production —
nothing here refuses it there, and nothing falls back to it. Reaching
production with this configured means mail is being silently lost.
```

`sendmail://default` and `sendmail+smtp://default` spawn a local blocking
process, so they are available only with `APP_ENV=development`. Only
Symfony's own default command runs: a `command` option in the DSN is
refused rather than turned into a shell command chosen by configuration.
`native://default` is not admitted at all — the
[supported transports](#supported-transports) say why.

### Local development

Mailpit, MailHog and a local Postfix speak unauthenticated plaintext SMTP
on loopback, which is exactly what the rules above refuse. One narrow,
explicit profile admits it:

```{code-block} text
APP_ENV=development
MAILER_DSN=smtp://127.0.0.1:1025
MAILER_ALLOW_INSECURE_LOCAL=true
```

It is valid only when `APP_ENV` reads `development` out of the same
`Config` snapshot the DSN came from, and it covers **one direct
`smtp://` or `smtps://` leaf** whose host is literally loopback —
`localhost`, anything in `127.0.0.0/8`, or `::1`. It relaxes
authentication and, for `smtp://`, the required STARTTLS; `smtps://` on
loopback keeps its implicit TLS, and peer verification is never relaxed.
An unfamiliar `APP_ENV` such as `staging` is production here, as
everywhere else in Kinetis (see {doc}`configuration <config>`).

Selecting the profile anywhere it does not apply is an error in its own
right rather than a flag that quietly does nothing. It fails the boot
when `APP_ENV` is not `development`, when the host is routable, against
`null://`, `sendmail://`, an API scheme or a provider `+smtp` scheme,
against any `failover(...)` or `roundrobin(...)` at all —
including one whose members are every one of them loopback, since the
contract says a direct leaf — and alongside `verify_peer=false`, which no
profile relaxes.

### Failover and round-robin

```{code-block} text
MAILER_DSN=failover(sendgrid+api://KEY@default smtps://user:pass@smtp.example.com)?retry_period=15
```

Members are separated by exactly one ASCII space. A tab, two spaces, a
leading or trailing space, an empty group, an unknown keyword and
anything after the closing parenthesis are all refused. A one-member
group is accepted and behaves as the member alone does. Groups nest.

`retry_period` is a composite-only option, written once, from 1 to 86400
seconds, defaulting to 60. It marks a child dead for *later* selections
after that child fails. It is not a backoff inside the current call.

```{important}
An explicit `failover(...)` or `roundrobin(...)` can try another child
after a failure that was ambiguous — a POST whose response never arrived
may still have been accepted — so a composite is at-least-once and can
deliver the same message twice. That is the trade being made by writing
one, not a defect. A single leaf never retries.
```

### Query options

Nothing publishes which options a bridge reads: a factory takes the ones
it wants and ignores the rest, so a misspelled option and an invented one
both build a transport running on the default you meant to change.
`kinetis/mailer` therefore records the consumed options on each registry
row, keyed by exact scheme, and refuses anything not on that row.

Keying by scheme rather than by provider is the point. Symfony's SES
factory reads `session_token` in its API branch and not in its SMTP one,
and reads `require_tls` and `ping_threshold` only in the SMTP branch;
SendGrid reads `region` in both. A single per-provider table would accept
each of those everywhere.

For `smtp://` and `smtps://`, the row is exactly what Symfony's
`EsmtpTransportFactory` reads:

| Option | Accepts |
|---|---|
| `auto_tls` | `true`, `false`, `1`, `0` |
| `require_tls` | `true`, `false`, `1`, `0` |
| `verify_peer` | `true`, `false`, `1`, `0` |
| `source_ip` | an IPv4 address, or a bracketed IPv6 one |
| `peer_fingerprint` | 32, 40 or 64 hex characters |
| `local_domain` | a hostname or a bracketed address literal |
| `max_per_second` | a decimal from 0 to 1000 |
| `restart_threshold` | an integer from 1 to 1000000 |
| `restart_threshold_sleep` | an integer from 0 to 86400; needs `restart_threshold` |
| `ping_threshold` | an integer from 1 to 86400 |

A bridge's own `+smtp` scheme is a different factory reading none of
these, so it draws from its own row in the
[supported transports](#supported-transports) table instead.

An option's value is checked against the vendor's own use of it, not
against a generic token shape. `region` is interpolated into a hostname
(`smtp.%s.sendgrid.net`, `email-smtp.%s.amazonaws.com`), so it is one DNS
label; `message_stream` becomes an unstructured MIME header, so it is an
identifier; `source_ip` is concatenated with `:0` into a `bindto`, so a
bare IPv6 address there would produce a string no socket can bind and the
bracketed form is required instead.

Booleans are canonical and strict. `require_tls=treu`, `verify_peer=treu`
and `require_tls=on` are refused — `FILTER_VALIDATE_BOOLEAN`, which
everything downstream eventually applies, reads a typo as `false`, which
is the opposite of what was written. Numbers are range-checked as digits
before any cast, so a value that would saturate to `PHP_INT_MAX` or cast
to `INF` is refused rather than silently applied.

`restart_threshold_sleep` without `restart_threshold` is refused too: it
reaches the transport only as the second argument of a call the factory
makes when the threshold is set, so on its own it does nothing.

### Grammar and caps

A leaf reads exactly `scheme://authority[?query]` in printable ASCII: no
path, no fragment, no whitespace, no control byte, no non-ASCII, and
every `%` starting a complete two-digit escape. A query is a flat list of
unique `key=value` pairs separated by `&`; brackets, dots, semicolons,
empty keys, empty values and keys colliding only by case are all refused
before anything can collapse them into each other.

A host is a bracketed IPv6 literal, an IPv4 address, or a DNS name whose
labels are real ones — no empty label, no underscore or tilde, no leading
or trailing hyphen, none over 63 bytes. A credential is checked again
*after* percent decoding, since `%0D%0A` is two ordinary characters until
it decodes into a line break inside an SMTP AUTH exchange; an escaped
delimiter such as `%40` still arrives as the `@` it means.

Five caps bound the work, each checked before the recursion or
allocation it guards:

| Cap | Limit |
|---|---|
| complete DSN | 8192 bytes |
| group nesting | 8 levels |
| leaf transports | 16 |
| query pairs per leaf | 32 |
| credential or option value | 1024 bytes |

### Errors

Every failure — a missing key, a grammar violation, a refused transport,
a factory that throws while being asked or while building, anything
Config or Symfony raises underneath — arrives as
`Kinetis\Mailer\Exception\MailerConfigurationException`, thrown fresh
with no `$previous`. The message names the config key and the rule that
was broken, and nothing else: no DSN, username, password, API token,
query string or provider credential reaches the message, the
stringification, a serialized copy or an ordinary log line. A send that
fails arrives as `Kinetis\Mailer\Exception\MailSendFailedException` under
the same rule, with an empty `getDebug()` so an SMTP AUTH exchange or a
provider's error body has nowhere to surface. A send started from inside
the same mailer's own send arrives as the same exception with its own
fixed message, before anything waits — see
[one send at a time](#blocking-and-what-queueing-fixes).

## Named connections

```{code-block} php
$transactional = MailerFactory::fromConfig($config, 'transactional');
```

```{code-block} text
MAILER_TRANSACTIONAL_DSN=sendgrid+api://KEY@default
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `MAILER_DSN` above, and any other name reads
`MAILER_{NAME}_DSN` instead. `MAILER_ALLOW_INSECURE_LOCAL` is scoped the
same way, so one connection's local profile never applies to another's.

A connection name is one or more lowercase `[a-z0-9]` segments joined by
`_`, up to 32 characters. The name chooses which environment variable is
read, so anything outside that grammar is refused rather than allowed to
select a key nobody wrote down.

## Blocking, and what queueing fixes

```{important}
An API transport's network wait yields, through
{doc}`revolt-http-client`. Nothing else about a send does. Symfony MIME
reads attachments off disk, encodes them, and runs DKIM, S/MIME and
OpenSSL preparation synchronously, and SMTP opens a raw socket with no
Fiber-yield point at all — an SMTP send occupies its worker thread for
the whole conversation.
```

Sending mail from inside a request means the client waits for it.
`kinetis/mailer` doesn't need to provide anything for this: a
{doc}`queue <queue>` job that constructor-injects `MailerInterface`
already gets it, with no extra code in either package.

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
`bootstrap.php` at all.

Queueing moves the cost off the worker handling that HTTP request and
onto a queue worker. It does not make the send Fiber-concurrent or
non-blocking; a blocking SMTP conversation is still blocking, in a
process where nothing else is waiting on it.

One mailer sends one message at a time. The mailer holds a mutex around
the whole Symfony `send()` call, because a Symfony transport is not safe
to re-enter: `EsmtpTransport` holds a live socket and a message counter
across calls, a composite holds a cursor and a dead-transport set, and
`max_per_second` is a running timestamp. Two named connections are two
mailers and progress independently; waiting senders are not promised any
particular order.

The mutex is not reentrant, and a send started from inside that `send()`
by the same sender — a synchronous listener on `MessageEvent`, a
decorator, a transport calling back into the mailer that called it —
would wait for a lock its own caller releases only after it returns,
which in a persistent worker is forever. The mailer records who holds the
lock, the running Fiber or the main stack, and refuses that sender at
once with `MailSendFailedException`, before touching the mutex. The outer
send then fails the way any send whose delegate threw does, and the lock
is released. A nested send by a different Fiber is an ordinary waiter and
runs once the lock is free.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  API-based transport actually runs through.
- {doc}`queue` — job queues, retries, and named connections in full.
- {doc}`config` — the named-connection convention used above.
