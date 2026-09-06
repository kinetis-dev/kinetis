<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/mailer</strong>
  <br>
  <strong>Mail sending for Kinetis, via <a href="https://symfony.com/doc/current/mailer.html"><code>Symfony\Component\Mailer</code></a></strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/mailer"><img src="https://img.shields.io/packagist/v/kinetis/mailer?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/mailer"><img src="https://img.shields.io/packagist/dt/kinetis/mailer" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/mailer"><img src="https://img.shields.io/packagist/php-v/kinetis/mailer" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/mailer"><img src="https://img.shields.io/packagist/l/kinetis/mailer" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

A single DSN selects the transport — SMTP, or one of the Symfony API
bridges in the supported registry. The DSN is parsed and judged by this
package before Symfony sees any part of it, and only a scheme the
registry names is ever built, so a transport that would send mail in the
clear, without authentication, or without verifying the server's
certificate never gets constructed.

```php
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

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `Symfony\Component\Mailer\MailerInterface`,
  built by `MailerFactory::fromConfig()` when `MAILER_DSN` is set. Unset
  or blank means the package binds nothing. The DSN is validated and the
  transport constructed during registration — no Symfony transport
  factory touches the network, so a misconfigured DSN fails the boot
  rather than the first send.

Nothing else. Named connections stay explicit application wiring.

## Configuration

```
MAILER_DSN=smtps://user:pass@smtp.example.com
```

| Key | Default | Purpose |
|---|---|---|
| `MAILER_DSN` | *(required)* | Transport DSN. Unset or blank binds nothing. |
| `MAILER_ALLOW_INSECURE_LOCAL` | `false` | Admit one direct loopback `smtp://` or `smtps://` leaf without authentication, and for `smtp://` without required TLS. Valid only with `APP_ENV=development`. |

Scoped — `MAILER_DSN` + `alerts` → `MAILER_ALERTS_DSN`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

An API transport instead — install the matching Symfony bridge package
too, which stays optional until its own scheme is selected:

```
MAILER_DSN=sendgrid+api://KEY@default
```

## Supported transports

Only these schemes are built. Each one names the exact Symfony factory
that builds it and the exact class that factory must return, both
compared as exact runtime classes rather than by `instanceof`, so an
unknown scheme, a future bridge, a custom factory, an alias of the
official one and an impostor transport — a subclass included — are all
refused.

| Package | Schemes |
|---|---|
| `symfony/mailer` | `smtp`, `smtps`, `null`, `sendmail`, `sendmail+smtp` |
| `symfony/sendgrid-mailer` | `sendgrid+api`, `sendgrid`, `sendgrid+smtp`, `sendgrid+smtps` |
| `symfony/amazon-mailer` | `ses+api`, `ses+https`, `ses`, `ses+smtp`, `ses+smtps` |
| `symfony/mailgun-mailer` | `mailgun+api`, `mailgun+https`, `mailgun` |
| `symfony/postmark-mailer` | `postmark+api` |
| `symfony/google-mailer` | `gmail`, `gmail+smtp`, `gmail+smtps` |

Mailgun's and Postmark's SMTP branches are absent on purpose: those
transports connect on port 587 without requiring STARTTLS, which is the
opportunistic downgrade refused everywhere else here. `native` is absent
too: php.ini decides at construction whether it becomes a local sendmail
process or, on Windows, a raw SMTP socket, and a family that is not known
before construction cannot be judged before it.

## What a DSN is allowed to say

- **SMTP is encrypted, authenticated and peer-verified.** `smtps://`
  carries implicit TLS; `smtp://` must ask for required STARTTLS with
  `require_tls=true`, because opportunistic TLS falls back to plaintext.
  Both need a username and a password, and a half reading exactly `0` is
  refused for these two schemes because Symfony's built-in factory would
  drop it silently. `verify_peer=false` is refused outright.
- **A bridge's `+smtp` scheme is SMTP too.** `gmail+smtp` and `ses+smtp`
  get no exemption from a prefix.
- **API requests are absolute HTTPS with a verified certificate.** The
  client every API transport is handed refuses anything else — a URL
  carrying `user:pass@` included — follows no redirect, and makes one
  attempt.
- **Credentials, host and options match what the factory reads.** A
  missing half, a half nothing reads, a host a bridge hard-codes anyway,
  an unknown option and `require_tls=treu` are all refused rather than
  silently becoming a default. SES API alone may carry no credentials,
  which is how its ambient AWS chain is selected.
- **`null://null` accepts and discards**, in every environment including
  production. Nothing refuses it there and nothing falls back to it, so
  reaching production with it configured means mail is silently lost.
- **`sendmail://default` runs a local process** and needs
  `APP_ENV=development`. A `command` option is refused.
- **Local development opts in explicitly.** Mailpit and friends speak
  unauthenticated plaintext SMTP, so they need
  `MAILER_ALLOW_INSECURE_LOCAL=true` alongside `APP_ENV=development`. The
  profile covers one direct `smtp://` or `smtps://` leaf on loopback and
  nothing else — not a composite, and not any other scheme. It relaxes
  authentication and, for `smtp://`, required TLS; `smtps://` keeps its
  implicit TLS, and peer verification is never relaxed.

`failover(...)` and `roundrobin(...)` are supported, and every member at
every depth is judged before any transport is built.

Full grammar, caps and the complete option table:
[kinetis.dev/docs/mailer.html](https://kinetis.dev/docs/mailer.html).

## Blocking, and what queueing fixes

SMTP opens a raw socket with no Fiber-yield point, so a send occupies its
worker thread for the whole conversation. An API transport's network wait
yields through [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client),
but reading and encoding attachments, DKIM and S/MIME signing and OpenSSL
preparation stay synchronous either way. Sending from a
[`kinetis/queue`](https://github.com/kinetis-dev/queue) job (constructor-inject
`Symfony\Component\Mailer\MailerInterface` in `handle()` — no extra code
needed in either package) moves that cost off an HTTP worker. It does not
make the send concurrent.

One mailer sends one message at a time; two named mailers progress
independently. A send started from inside the same mailer's own send — a
synchronous listener, a decorator — is refused at once with
`MailSendFailedException` rather than left waiting for a lock its caller
holds.

## Installation

```sh
composer require kinetis/mailer
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework), and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/mailer.html](https://kinetis.dev/docs/mailer.html).

## License

MIT — see [LICENSE](LICENSE).
