# Neuron AI

Install the package:

```{code-block} sh
composer require kinetis/neuron
```

[Neuron AI](https://github.com/neuron-core/neuron-ai)
(`neuron-core/neuron-ai`) provides agents and LLM provider clients.
Its providers send HTTP through `NeuronAI\HttpClient\HttpClientInterface`,
and the default implementation, `CurlHttpClient`, blocks the process for
the whole call. `Kinetis\Neuron\NeuronHttpClient` implements that
interface over {doc}`revolt-http-client`'s `Http`. A provider call
suspends the calling Fiber, so timers and other tasks in the same
request keep running while it waits. Each call has one deadline and one
byte ceiling, and requests can reach only the provider's origin.

## Register the client

Build one `NeuronHttpClient` per provider origin at boot, and keep it
with the API key in an application-owned factory that builds a new
provider on every call:

```{code-block} php
:caption: bootstrap.php

use App\Assistant\Providers;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;

return static function (AppScope $app, Config $config): void {
    $app->instance(Providers::class, new Providers(
        new NeuronHttpClient(
            new Http()->withTimeout(120)->withMaxResponseBytes(4 * 1024 * 1024),
            'https://api.openai.com/v1',
        ),
        $config->required('OPENAI_API_KEY'),
    ));
};
```

```{code-block} php
namespace App\Assistant;

use Kinetis\Neuron\NeuronHttpClient;
use NeuronAI\Providers\OpenAI\OpenAI;
use SensitiveParameter;

final readonly class Providers
{
    public function __construct(
        private NeuronHttpClient $client,
        #[SensitiveParameter] private string $apiKey,
    ) {}

    public function openAi(): OpenAI
    {
        return new OpenAI(key: $this->apiKey, model: 'gpt-4.1-mini', httpClient: $this->client);
    }
}
```

`Providers` and `OPENAI_API_KEY` belong to the application; this package
reads no configuration.

The second constructor argument is the provider's own base URI, written
as the provider writes it: `https://api.openai.com/v1` for Neuron's
`OpenAI` provider, `https://api.anthropic.com/v1` for `Anthropic`, and
the `baseUri` you pass to `OpenAILike`. It becomes the `Http` base URL.
A provider request under it goes out relative to it. Any other absolute
URI is refused with the `InvalidRequest` category before anything is
sent, and redirects are never followed. The provider adds its key to
every request in its own header (`Authorization` for OpenAI, `x-api-key`
for Anthropic), and the key can reach only that origin. To reach a
second provider, build a second client.

The `Http` you pass sets the rest:

- **`withTimeout()`** bounds the whole call: sending, waiting for the
  first byte and reading the last one, a streamed answer included. Set
  it to the longest answer you accept
  ({ref}`http-client-reference-deadline`).
- **`withMaxResponseBytes()`** bounds the body, buffered or streamed
  ({ref}`http-client-reference-ceiling`).
- **`withRetries()`** repeats only idempotent methods. Chat, streaming
  and structured-output calls are `POST` requests, so it never repeats
  one, and this package adds no retry of its own.

## Scope providers and agents to the request

`NeuronHttpClient` is immutable and holds no request data, so one
instance serves every request of a persistent worker. Neuron's
providers and agents do hold request data:

| Object | Scope | Why |
|---|---|---|
| `NeuronHttpClient`, and the `Http` inside it | Application | Immutable; nothing from a request is kept. |
| The provider (`OpenAI`, `Anthropic`, ...) | Request | Every agent run sets the system prompt and the tool list on the provider it uses. A shared provider carries one request's instructions and tools into the next. |
| The `Agent` | Request | It holds the chat history, the thread ID and any pending tool call. |
| `McpTools` and the tools it returns | Request | They run each call with the request scope they were built in ({ref}`neuron-mcp-tools`). |

Build both inside the request:

```{code-block} php
namespace App\Assistant;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class SupportAssistant
{
    public function __construct(private Providers $providers) {}

    public function answer(string $conversationId, string $question): ?string
    {
        return Agent::make()
            ->setAiProvider($this->providers->openAi())
            ->setInstructions('Answer questions about orders. Be brief.')
            ->setThreadId($conversationId)
            ->chat(new UserMessage($question))
            ->getMessage()
            ?->getContent();
    }
}
```

`SupportAssistant` is not registered, so the container builds it for
each request ({doc}`container`). `chat()` throws `AgentException` until
`setThreadId()` names the conversation. Neuron's default message store
keeps the history in the `Agent`, so it ends with the request.

## Stream an answer

`Agent::stream()` returns a generator. The provider reads the answer as
it arrives, and each read suspends the Fiber:

```{code-block} php
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

$answer = '';

foreach ($agent->stream(new UserMessage($question)) as $chunk) {
    if ($chunk instanceof TextChunk) {
        $answer .= $chunk->content;
    }
}
```

The `withTimeout()` deadline covers the whole stream. When a stream
stops before its end, through a `break`, an exception or a dropped
generator, the client cancels the connection to the provider.

(neuron-browser-stream)=

## Stream an answer to the browser

`Kinetis\Neuron\AgentStream` sends an agent run to the browser as
Server-Sent Events in one of Neuron's UI protocols: `VercelAIAdapter`
for the Vercel AI SDK's `useChat`, `AGUIAdapter` for AG-UI clients. Set
the adapter on the agent, then pass the same adapter and the run to
`AgentStream`:

```{code-block} php
namespace App\Assistant;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\StreamedResponse;
use Kinetis\Neuron\AgentStream;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class AssistantController
{
    public function __construct(private Providers $providers) {}

    #[Post('/assistant/{conversationId}')]
    public function ask(string $conversationId, #[Body] Question $question): StreamedResponse
    {
        $adapter = new VercelAIAdapter();

        $run = Agent::make()
            ->setAiProvider($this->providers->browserOpenAi())
            ->setInstructions('Answer questions about orders. Be brief.')
            ->setThreadId($conversationId)
            ->setStreamAdapter(static fn (): VercelAIAdapter => $adapter)
            ->stream(new UserMessage($question->text));

        return new AgentStream($adapter)->response($run);
    }
}
```

`Question` is an application DTO. The provider comes from a factory
method that stops its stream when the browser leaves:

```{code-block} php
use NeuronAI\HttpClient\StoppableHttpClient;

public function browserOpenAi(): OpenAI
{
    return new OpenAI(
        key: $this->apiKey,
        model: 'gpt-4.1-mini',
        httpClient: new StoppableHttpClient(
            $this->client,
            static fn (): bool => connection_aborted() === 1,
        ),
    );
}
```

The response is `200` with the adapter's headers: `Content-Type:
text/event-stream`, `X-Accel-Buffering: no` for nginx, and the
protocol's own. The run starts when the response is emitted, after the
headers, still inside the request scope. Each protocol event is
written as one `data:` frame and flushed. FrankenPHP and PHP-FPM stream
it. RoadRunner answers a streamed response with `501`
({doc}`runtime-adapters`).

**When the run fails.** The status and headers are already sent, so
the browser receives the protocol's error frame instead: `error` for
the AI SDK, `RUN_ERROR` for AG-UI. Its text is the adapter's neutral
"The run failed.", never the exception message. The exception is then
rethrown, and the framework logs it.

**When the browser leaves.** `connection_aborted()` turns true at the
first flush after the browser has gone. `StoppableHttpClient` asks its
predicate before each event the provider reads. Neuron then ends the
answer with the text streamed so far, with the stop reason
`StoppableHttpClient::STOP_REASON` (`'stopped'`), and closes the
provider stream. A stop before the first word fails the run with a
`ProviderException`. `AgentStream` does not rethrow a failure once the
browser has gone, because no one is left to read it; Neuron's observers
still record it. What each runtime does:

| Runtime | When the browser leaves |
|---|---|
| FrankenPHP (worker mode) | The script keeps running and the answer stops as described above. The provider connection closes when the request ends ({ref}`runtime-reference-frankenphp-fpm`). |
| PHP-FPM behind nginx | nginx closes the FastCGI connection, and PHP ends the script at the next write. The provider connection closes with the request. No stopped answer is recorded. |

## Several calls at once

```{code-block} php
use function Kinetis\Async\concurrently;

[$summary, $tags] = concurrently([
    fn (): ?string => $this->summarizer->answer($ticketId, $text),
    fn (): ?string => $this->tagger->answer($ticketId, $text),
]);
```

The two calls overlap, and the request takes about as long as the
slower one. The gain is inside one request: the event loop keeps
running, `concurrently()` overlaps provider calls with each other and
with other I/O, and timers fire on time. It does not let a worker take a
second request while the first waits. A FrankenPHP thread or a
RoadRunner process serves one request at a time
({ref}`concurrency-overlap`), so cross-request capacity still comes from
the worker count.

(neuron-mcp-tools)=

## Give an agent the application's tools

An agent can call the application's own `#[McpTool]` methods
({doc}`mcp`) in process, without an MCP connection.
`Kinetis\Neuron\McpTools` turns registered tools into Neuron tools:

```{code-block} php
namespace App\Assistant;

use Kinetis\Neuron\McpTools;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class OrderAssistant
{
    public function __construct(
        private Providers $providers,
        private McpTools $tools,
    ) {}

    public function answer(string $conversationId, string $question): ?string
    {
        return Agent::make()
            ->setAiProvider($this->providers->openAi())
            ->setThreadId($conversationId)
            ->addTool($this->tools->select('find_orders', 'cancel_order'))
            ->chat(new UserMessage($question))
            ->getMessage()
            ?->getContent();
    }
}
```

`select()` takes tool names, the `name` of each `#[McpTool]`, and
returns the tools in that order. A name no tool is registered under
throws `InvalidArgumentException` when the tools are built, before any
agent runs.

**Scope.** `McpTools` holds the request scope it was resolved from, and
every tool call resolves its controller and the controller's
dependencies from that scope: the tool runs with the current request's
identity and request-scoped services, as an MCP call does over HTTP.
Resolve `McpTools` inside the request, as `OrderAssistant` above is
resolved, and never register it, or a tool from it, in the application
scope.

**Arguments and results.** The provider receives each tool's MCP
`inputSchema` unchanged, so the model sees the same types, enums,
required members, constraints and `additionalProperties: false` an MCP
client sees. Neuron does not check or cast the model's arguments; the
MCP dispatcher validates them as it does an MCP call. The tool's answer
then goes back to the model:

- A return value is JSON-encoded, as for an MCP client.
- A returned `ToolResult` gives its text, as an error when it is one.
- Invalid arguments give an error result carrying the
  `{"errors": [...]}` feedback an MCP client receives
  ({doc}`appendix-mcp`, "Error catalogue"), so the model can correct
  its next call.
- Any other exception is not turned into a result. It ends the agent
  run, and `chat()` or `stream()` throws it. Neuron's `Agent` can
  install a tool error handler to change that.

A `ProgressReporter` parameter receives a reporter that discards
progress. The dispatcher records the tool-call span, as for an MCP call.

Neuron decodes the model's arguments into PHP arrays before a tool sees
them, which loses the difference between an empty JSON object and an
empty list, and between a list and an object whose keys are `"0"`,
`"1"`, and so on. An empty object sent for an `#[ObjectMap]` argument is
therefore refused as a JSON array, with feedback the model cannot act
on. Give such an argument at least one member, or make it optional.

## When a call fails

Every failure is a `NeuronAI\Exceptions\HttpException`, the exception
Neuron's providers expect:

- **A status of 400 or more** raises `HttpException::statusError()`.
  `$e->response` holds the status and the body, read under the byte
  ceiling.
- **Every other failure** raises `HttpException::networkError()` with
  `$e->response` null, a stream that fails mid-answer included.
  `$e->getPrevious()` is the `HttpRequestException` behind it. Branch on
  its `category` ({doc}`revolt-http-client`, "When a request fails"):
  `InvalidRequest` for a request refused before it was sent, `Timeout`,
  `ResponseTooLarge` and `Transport`.
- **A multipart request**, which Neuron builds for audio and file
  uploads, is refused with `networkError()` before anything is sent.
  This package does not support multipart bodies.

After `Transport` or `Timeout`, the provider may have received the
request, run it and billed it. Nothing here sends it again. Decide in
application code whether a repeat is worth it.

Each message starts with Neuron's own text and the request URI without
user info:

- A `networkError()` message ends with the `Http` message, which names
  only the method and the origin.
- A `statusError()` message ends with the provider's error body, up to
  the byte ceiling. That body is the provider's text and can quote the
  prompt or other request content, so treat the message as you treat
  request data before logging it, or log the status alone
  (`$e->response->statusCode`).

`$e->request` is Neuron's `HttpRequest`, headers included, so it holds
the API key: never log that property.

## What not to use in a worker

These Neuron features block the worker, fork it, or keep state between
requests:

| Feature | Why |
|---|---|
| `CurlHttpClient`, Neuron's default when no client is passed | `curl_exec()` blocks the process for the whole call. |
| `AmpHttpClient` | `withBaseUri()` and `withHeaders()` change the instance they are called on, and it reads a body with no size limit. A shared instance carries one request's settings into the next. |
| `Agent::parallelToolCalls()` (`ParallelToolNode`) | It forks the worker process to run tools. |
| `FilePersistence`, `DatabasePersistence`, `FileMessageStore`, `SQLMessageStore` | Blocking file and PDO I/O. |
| `PhpSerializer` | It calls `unserialize()` with no class restriction. |
| A workflow interrupt and resume | A resumed node runs again from its start and repeats whatever it did before the interrupt that was not memoized. |
| `StdioTransport` for MCP | `proc_open()` and blocking pipe reads. |
| `SseHttpTransport` for MCP | `fopen()` on the server URL blocks. |
| `UsageMeter` from Neuron's evaluation tools | It records into a static, shared by every request in the worker. |
| Streaming an answer to the client under RoadRunner | RoadRunner answers a `StreamedResponse` with `501` ({doc}`runtime-adapters`). |

Telemetry spans and remote MCP servers are not part of this package.

## See also

- {doc}`revolt-http-client` — the `Http` client: base URLs, deadlines,
  byte ceilings and failure categories.
- {doc}`concurrency` — running several calls at once.
- {doc}`container` — application and request scope.
- {doc}`mcp` — declaring `#[McpTool]` tools.
