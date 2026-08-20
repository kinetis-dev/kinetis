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

A single DSN selects the transport — SMTP, or any of Symfony's own
API-based bridges (Sendgrid, Mailgun, Postmark, SES, ...). API-based
transports run through `kinetis/revolt-http-client`
instead of blocking.

```php
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

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `Symfony\Component\Mailer\MailerInterface`,
  built by `MailerFactory::fromConfig()` when `MAILER_DSN` is set. Unset
  means the package binds nothing. The binding is lazy, so an
  application that never sends mail never builds a transport — including
  from a queued job, which resolves it in the worker process.

Nothing else. Named connections stay explicit application wiring.

## Configuration

```
MAILER_DSN=smtp://user:pass@smtp.example.com:587
```

| Key | Default | Purpose |
|---|---|---|
| `MAILER_DSN` | *(required)* | Symfony Mailer transport DSN. |

Scoped — `MAILER_DSN` + `alerts` → `MAILER_ALERTS_DSN`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

Or an API-based transport instead — install the matching Symfony bridge
package too (`symfony/sendgrid-mailer`, `symfony/mailgun-mailer`, ...):

```
MAILER_DSN=sendgrid+api://KEY@default
```

SMTP is not non-blocking — it opens a raw socket directly, with no
Fiber-yield point. Send mail from a `kinetis/queue` job (constructor-inject
`Symfony\Component\Mailer\MailerInterface` in `handle()` — no extra code
needed in either package) if that matters for your app.

## Installation

```sh
composer require kinetis/mailer
```

Requires PHP 8.4+, `kinetis/framework`, and `kinetis/revolt-http-client`.
Full documentation:
[kinetis.dev/docs/mailer.html](https://kinetis.dev/docs/mailer.html).

## License

MIT — see [LICENSE](../../LICENSE).
