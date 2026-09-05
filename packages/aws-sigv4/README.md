<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/aws-sigv4</strong>
  <br>
  <strong>A PSR-18 client that signs requests with AWS Signature Version 4</strong>
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

A PSR-18 HTTP client that signs every outgoing request with AWS
Signature Version 4 (SigV4) and sends it to one configured origin — the
signing math is `AsyncAws\Core\Signer\SignerV4`, the same class every
AsyncAws service client uses internally, reused directly rather than
reimplemented. Usable outside Kinetis entirely, the same relationship
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) already has with the wider PHP ecosystem.

```php
use Kinetis\AwsSigV4\SigV4SigningClient;

$signedClient = new SigV4SigningClient(
    origin: 'https://search-my-domain.us-east-1.es.amazonaws.com',
    region: 'us-east-1',
    service: 'es', // Amazon OpenSearch Service's signing name
);

$response = $signedClient->sendRequest($request);
```

`$origin` is the only scheme, host, and port this client will sign for,
with an optional path prefix that binds every request. A relative
request resolves against it; anything else — another host, another port,
an `http` target under an `https` origin, a path that leaves the prefix
— is rejected before credentials are resolved, before the body is read,
and before the network is touched. The target is put into the exact form
it will be sent in before both the check and the signature, so the
signature covers the bytes that go out. A 3xx response is returned as it
is: nothing is re-signed and no `Location` is followed, for the signed
request or for the credential lookups.

`$service` is the AWS signing service name (`"es"` for Amazon OpenSearch
Service, `"execute-api"` for API Gateway, and so on) — required, with no
default, since guessing wrong produces a signature that fails
verification rather than an obvious error.

## Credentials

Resolved through AsyncAws's own default provider chain
(`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, a shared credentials file,
or an IAM role) unless a `CredentialProvider` is passed as the fourth
constructor argument.

## Installation

```sh
composer require kinetis/aws-sigv4
```

Requires PHP 8.4+ and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client). Full documentation,
including the origin grammar, failure behavior, and what is buffered or
blocking:
[kinetis.dev/docs/aws-sigv4.html](https://kinetis.dev/docs/aws-sigv4.html).

## License

MIT — see [LICENSE](LICENSE).
