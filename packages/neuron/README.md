<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/neuron</strong>
  <br>
  <strong>Neuron AI providers over a non-blocking, bounded HTTP client</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/neuron"><img src="https://img.shields.io/packagist/v/kinetis/neuron?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/neuron"><img src="https://img.shields.io/packagist/dt/kinetis/neuron" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/neuron"><img src="https://img.shields.io/packagist/php-v/kinetis/neuron" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/neuron"><img src="https://img.shields.io/packagist/l/kinetis/neuron" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

`Kinetis\Neuron\NeuronHttpClient` implements
[Neuron AI](https://github.com/neuron-core/neuron-ai)'s
`NeuronAI\HttpClient\HttpClientInterface` over
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
A provider call suspends the calling Fiber instead of blocking the
process, has one deadline and one byte ceiling, and can reach only the
provider's origin.

```php
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\Providers\OpenAI\OpenAI;

$client = new NeuronHttpClient(
    new Http()->withTimeout(120)->withMaxResponseBytes(4 * 1024 * 1024),
    'https://api.openai.com/v1',
);

$provider = new OpenAI(key: $apiKey, model: 'gpt-4.1-mini', httpClient: $client);
```

The second argument is the provider's own base URI. An absolute URI
outside it is refused before anything is sent. The client is immutable
and holds no request data, so one instance can serve every request of a
persistent worker. Build the provider and the `Agent` per request: both
hold request data.

`Kinetis\Neuron\McpTools` gives an agent the application's own
`#[McpTool]` tools, called in process with the current request's scope,
validated by the MCP dispatcher, and published to the provider with
their MCP input schema unchanged:

```php
$agent->addTool($mcpTools->select('find_orders', 'cancel_order'));
```

## Installation

```sh
composer require kinetis/neuron
```

Requires PHP 8.4+, [`neuron-core/neuron-ai`](https://github.com/neuron-core/neuron-ai),
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)
and [`kinetis/mcp`](https://github.com/kinetis-dev/mcp).
Full documentation, including request scoping, streaming, failure
mapping and the Neuron features to avoid in a persistent worker:
[kinetis.dev/docs/neuron.html](https://kinetis.dev/docs/neuron.html).

## License

MIT — see [LICENSE](LICENSE).
