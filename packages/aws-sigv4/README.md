<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/aws-sigv4</strong>
  <br>
  <strong>A PSR-18 client decorator that signs requests with AWS Signature Version 4</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/aws-sigv4"><img src="https://img.shields.io/packagist/v/kinetis/aws-sigv4?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/aws-sigv4"><img src="https://img.shields.io/packagist/dt/kinetis/aws-sigv4" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/aws-sigv4"><img src="https://img.shields.io/packagist/php-v/kinetis/aws-sigv4" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/aws-sigv4"><img src="https://img.shields.io/packagist/l/kinetis/aws-sigv4" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Wraps any PSR-18 HTTP client and signs every outgoing request with AWS
Signature Version 4 (SigV4) before delegating to it — the signing math
itself is `AsyncAws\Core\Signer\SignerV4`, the same class every AsyncAws
service client already uses internally, reused directly rather than
reimplemented. Usable standalone with any PSR-18 client, not only
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) — and usable outside Kinetis entirely, the
same relationship [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) already has with the wider
PHP ecosystem.

```php
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\HttpClient\Psr18Client;

$signedClient = new SigV4SigningClient(
    client: new Psr18Client(AmpHttpClientFactory::create()),
    region: 'us-east-1',
    service: 'es', // Amazon OpenSearch Service's signing name
);

$response = $signedClient->sendRequest($request);
```

`$service` is the AWS signing service name (`"es"` for Amazon OpenSearch
Service, `"execute-api"` for API Gateway, and so on) — required, with no
default, since guessing wrong produces a signature that fails
verification rather than an obvious error.

## Credentials

Resolved through AsyncAws's own default provider chain
(`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, a shared credentials file,
or an IAM role) unless a `CredentialProvider` is passed directly as the
fourth constructor argument. The chain's own bootstrap HTTP calls (an
instance-metadata or container-credentials lookup) go through
`AmpHttpClientFactory::create()` too, so credential resolution never
blocks the worker either.

## Installation

```sh
composer require kinetis/aws-sigv4
```

Requires PHP 8.4+ and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client). Full documentation:
[kinetis.dev/docs/aws-sigv4.html](https://kinetis.dev/docs/aws-sigv4.html).

## License

MIT — see [LICENSE](LICENSE).
