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

(neuron-register-client)=

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
sent. The client turns no redirect following on; an `Http` you built
with `withRedirects()` follows one only to a URL under that base URL.
The provider adds its key to
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

(neuron-scope-request)=

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
| `BedrockProvider` | Request | Like the other providers, it holds the system prompt, the tools and a stream's state; the `SigV4SigningClient` under it is application-scoped ({ref}`neuron-bedrock`). |

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

`chat()` throws `AgentException` until `setThreadId()` names the
conversation. Neuron's default message store keeps the history in the
`Agent`, so it ends with the request.

A controller receives the assistant and answers with JSON:

```{code-block} php
namespace App\Assistant;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class AnswerController
{
    public function __construct(private SupportAssistant $assistant) {}

    /** @return array{answer: ?string} */
    #[Post('/assistant/{conversationId}/answer')]
    public function answer(string $conversationId, #[Body] Question $question): array
    {
        return ['answer' => $this->assistant->answer($conversationId, $question->text)];
    }
}
```

`Question` is an application DTO. Each request follows one path:

1. At boot, `bootstrap.php` registers the one `Providers` instance.
2. The route attribute maps the request to `AnswerController::answer()`.
3. Neither `AnswerController` nor `SupportAssistant` is registered, so
   the container builds both for this request and injects the shared
   `Providers` into `SupportAssistant` ({doc}`container`).
4. `answer()` builds a new provider and a new `Agent`, runs it, and both
   end with the request.
5. The returned array is the JSON body ({doc}`routing-validation`).

## Stream an answer

`Agent::stream()` returns a generator that yields the answer as the
provider reads it. Each read suspends the Fiber, so the worker serves
other requests while the provider writes. Add a streaming method to
`SupportAssistant` that hands each piece of text to the caller as it
arrives:

```{code-block} php
namespace App\Assistant;

use Closure;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class SupportAssistant
{
    // __construct() and answer() as above

    /** @param Closure(string): void $onText */
    public function streamAnswer(string $conversationId, string $question, Closure $onText): void
    {
        $chunks = Agent::make()
            ->setAiProvider($this->providers->openAi())
            ->setInstructions('Answer questions about orders. Be brief.')
            ->setThreadId($conversationId)
            ->stream(new UserMessage($question));

        foreach ($chunks as $chunk) {
            if ($chunk instanceof TextChunk) {
                $onText($chunk->content);
            }
        }
    }
}
```

The stream also yields tool calls, reasoning and other chunk types;
`TextChunk` carries the answer's text. The `withTimeout()` deadline
covers the whole stream. When a stream stops before its end, through a
`break`, an exception thrown by `$onText` or a dropped generator, the
client cancels the connection to the provider. To send the stream to a
browser, use `AgentStream` ({ref}`neuron-browser-stream`).

(neuron-browser-stream)=

## Stream an answer to the browser

`Kinetis\Neuron\AgentStream` sends an agent run to the browser as
Server-Sent Events in one of Neuron's UI protocols: `VercelAIAdapter`
for the Vercel AI SDK's `useChat`, `AGUIAdapter` for AG-UI clients. Set
the adapter on the agent, then pass the same adapter, the request's
`Kinetis\Runtime\ClientConnectionInterface` and the run to
`AgentStream`:

```{code-block} php
namespace App\Assistant;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\StreamedResponse;
use Kinetis\Neuron\AgentStream;
use Kinetis\Runtime\ClientConnectionInterface;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class AssistantController
{
    public function __construct(private Providers $providers) {}

    #[Post('/assistant/{conversationId}')]
    public function ask(
        string $conversationId,
        #[Body] Question $question,
        ClientConnectionInterface $connection,
    ): StreamedResponse {
        $adapter = new VercelAIAdapter();

        $run = Agent::make()
            ->setAiProvider($this->providers->browserOpenAi($connection))
            ->setInstructions('Answer questions about orders. Be brief.')
            ->setThreadId($conversationId)
            ->setStreamAdapter(static fn (): VercelAIAdapter => $adapter)
            ->stream(new UserMessage($question->text));

        return new AgentStream($adapter, $connection)->response($run);
    }
}
```

The controller follows the same path as `AnswerController`, and builds
the agent itself because it hands the run to `AgentStream`. The Kernel
binds `ClientConnectionInterface` for each request, so the controller
receives it like any other dependency. The provider comes from a second
`Providers` method that stops its stream when the browser leaves:

```{code-block} php
use Kinetis\Runtime\ClientConnectionInterface;
use NeuronAI\HttpClient\StoppableHttpClient;

public function browserOpenAi(ClientConnectionInterface $connection): OpenAI
{
    return new OpenAI(
        key: $this->apiKey,
        model: 'gpt-4.1-mini',
        httpClient: new StoppableHttpClient($this->client, $connection->isGone(...)),
    );
}
```

`Providers` is application-scoped, so the connection is an argument of
the call rather than a constructor dependency.

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

**When the browser leaves.** `StoppableHttpClient` asks
`$connection->isGone()` before each event the provider reads. Once the
runtime reports the browser gone, Neuron ends the answer with the text
streamed so far, with the stop reason `StoppableHttpClient::STOP_REASON`
(`'stopped'`), and closes the provider stream. A stop before the first
word fails the run with a `ProviderException`. `AgentStream` does not
rethrow a failure once the browser has gone, because no one is left to
read it; Neuron's observers still record it. Which runtimes report the
departure, and when, is listed in {ref}`runtime-client-connection`:
under FrankenPHP the answer stops as described here, and under PHP-FPM
the script ends at the next write, so no stopped answer is recorded.

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

(neuron-remote-mcp)=

## Use a remote MCP server

Neuron's `McpConnector` reaches an MCP server over Streamable HTTP
through `StreamableHttpTransport`, which sends every message through the
HTTP client it is given. Give it a `NeuronHttpClient` whose base URL is
the server's origin:

```{code-block} php
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\MCP\McpConnector;

$connector = new McpConnector(
    ['url' => 'https://tools.example.com/mcp', 'token' => $serviceToken, 'timeout' => 20],
    new NeuronHttpClient(
        new Http()->withMaxResponseBytes(1024 * 1024),
        'https://tools.example.com',
    ),
);

$agent->addTool($connector->tools());
```

- **The base URL is the origin, not the endpoint.** `NeuronHttpClient`
  sends a URL under its base URL as a path relative to it. The base URL
  itself is not such a path, so an endpoint given as the base URL is
  refused before anything is sent, as is a `url` on another origin:
  `token` and `headers` reach only the server's origin.
- **`timeout`** is the deadline of each MCP message, 30 seconds when it
  is not set. Neuron passes it with every message, so it takes the place
  of `withTimeout()`.
- **`withMaxResponseBytes()`** bounds every response. A larger one fails
  the message with `ResponseTooLarge`.
- **A failure** of `tools()` or of a tool call is a
  `NeuronAI\MCP\McpException`, with the `HttpException` of
  {ref}`neuron-call-fails` as its previous exception; a failed tool call
  ends the agent run. Nothing is sent again, so a tool call that timed
  out may still have run on the server.

**Connector lifetime.** The first `tools()` call opens the connector's
MCP session: it sends `initialize` and the `initialized` notification,
and keeps the session, its ID if the server issues one, and the
connector's `token` and `headers` for as long as the connector lives.
Every `tools()` call sends `tools/list`. Neuron never ends a session it
opened: a server that issues session IDs keeps each one until it expires
it.

| Connector | Each request sends | Every request uses |
|---|---|---|
| Built in the request | `initialize`, `tools/list` | Its own session and credentials; the server keeps one session per request until it expires them |
| Registered in the application scope | `tools/list` | The connector's session and one set of credentials for the whole worker |

Two requests that make the first `tools()` call of an application-scoped
connector concurrently each open a session; the connector keeps the one
opened last. Call `tools()` once when the worker starts to open its
single session before requests arrive.

Register a connector in the application scope only when its credentials
are the application's own service credentials, which every request may
use. When the token or the session belongs to a user or to a request,
build the connector in the request.

(neuron-vector-stores)=

## Retrieve from a vector store

`ChromaVectorStore`, `MeilisearchVectorStore`, `PineconeVectorStore`,
`QdrantVectorStore` and `WeaviateVectorStore` send every request through
the `HttpClientInterface` they are given, and so does every Neuron
embeddings provider except `AwsBedrockEmbeddingsProvider`. Without one,
Neuron builds `CurlHttpClient`. Give each a `NeuronHttpClient` whose
base URL is the server's origin:

```{code-block} php
use Kinetis\Neuron\NeuronHttpClient;
use Kinetis\RevoltHttpClient\Http;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;

$qdrant = new NeuronHttpClient(new Http()->withTimeout(10), 'http://qdrant:6333');

$store = new QdrantVectorStore(
    'http://qdrant:6333/collections/articles',
    dimension: 1536,
    httpClient: $qdrant,
);
$embeddings = new OpenAIEmbeddingsProvider(
    key: $apiKey,
    model: 'text-embedding-3-small',
    dimensions: 1536,
    httpClient: $openAi,
);
```

`$qdrant` and `$openAi` are built once at boot and shared, as in
{ref}`neuron-register-client`. Build the stores
and the embeddings providers in the request, like the provider.

- **The base URL is the server's origin, not the collection.** A store
  builds its URLs from the one it is given, and some requests go to that
  URL itself: Qdrant creates and deletes a collection at the collection
  URL, Chroma creates one at its collections URL. `NeuronHttpClient`
  refuses its own base URL as a request URL, so a client pinned at the
  collection fails those requests with `InvalidRequest` before anything
  is sent.
- **An embeddings provider** takes the base URI it sends to, as a chat
  provider does: `https://api.openai.com/v1` for
  `OpenAIEmbeddingsProvider`, so the client the OpenAI chat provider
  uses serves it too.
- **`AwsBedrockEmbeddingsProvider`** takes aws-sdk-php's
  `BedrockRuntimeClient` and has no `HttpClientInterface` parameter. It
  sends through the AWS SDK, which blocks the worker. Use
  `Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider` instead
  ({ref}`neuron-bedrock`).
- **`MemoryVectorStore`** keeps its documents in the object. Build it
  in the request; registered in the application scope it would carry
  one request's documents into the next and grow without bound.

`FileVectorStore`, `MariaDBVectorStore`, `MongoDBVectorStore`,
`TypesenseVectorStore`, `FileDataLoader`, `PdfReader` and
`Neo4jGraphStore` block the worker ({ref}`neuron-not-in-worker`).

### Elasticsearch and OpenSearch

`ElasticsearchVectorStore` and `OpenSearchVectorStore` take the vendor's
SDK client and send every request through it. Give them the client
{doc}`search-elasticsearch` or {doc}`search-opensearch` builds:
`ElasticsearchClientFactory::fromConfig()` and
`OpenSearchClientFactory::fromConfig()` put the SDK on the non-blocking
search transport, and with `SEARCH_ELASTICSEARCH_HOST` or
`SEARCH_OPENSEARCH_HOST` set, the package binds that client in the
container. Inject it into a class the container builds for the request,
and build the store there:

```{code-block} php
namespace App\Knowledge;

use Elastic\Elasticsearch\Client;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;

final readonly class ArticleSearch
{
    public function __construct(private Client $elasticsearch) {}

    /**
     * @param list<float> $embedding
     * @return iterable<Document>
     */
    public function nearest(array $embedding): iterable
    {
        $store = new ElasticsearchVectorStore($this->elasticsearch, 'articles', topK: 5);

        return $store->search(new SearchRequest($embedding));
    }
}
```

For OpenSearch, inject `OpenSearch\Client` and build
`new OpenSearchVectorStore($client, 'articles', topK: 5)` the same way.

- **Scope.** `ArticleSearch` is not registered, so the container builds
  it, and the store, for each request. Each resolution of the
  Elasticsearch client is a new client over the shared transport,
  because the SDK keeps the last request and response it sent
  ({ref}`why-the-client-is-short-lived`); do not keep it, or a store
  holding it, in a worker-lifetime service. The OpenSearch client is one
  instance shared by the worker; build its store in the request like
  every other store.
- **Other clients block.** A client built with the SDK's own
  `ClientBuilder`, `SymfonyClientFactory` or `GuzzleClientFactory`
  sends through a blocking HTTP client. For a second connection, build
  the client with the factory and a connection name
  ({ref}`search-reference-named`).
- **Deadlines and failures** are the search transport's: the package's
  `..._TIMEOUT` and `..._MAX_RESPONSE_BYTES` bound each call, and
  nothing is sent twice. The store raises the SDK's exceptions
  ({ref}`search-reference-failures`), and `VectorStoreException` for
  documents the bulk request rejected. A failed `addDocuments()` may
  already have indexed some of the documents.

(neuron-trace-inference)=

## Trace inference

`Kinetis\Neuron\InferenceTelemetry::observe()` reports every provider
call of one agent through the framework's instrumentation hooks, and
returns the agent:

```{code-block} php
use Kinetis\Neuron\InferenceTelemetry;

return InferenceTelemetry::observe(Agent::make())
    ->setAiProvider($this->providers->openAi())
    ->setThreadId($conversationId)
    ->chat(new UserMessage($question))
    ->getMessage()
    ?->getContent();
```

With {doc}`telemetry` exporting, each call is a `chat {model}` span
carrying the provider, the model and the token usage
({ref}`telemetry-inference`). Without it, the hooks do nothing.

- **One agent.** The listener belongs to the agent it observes and ends
  with it. Call `observe()` on every agent you build; concurrent agents
  in one worker report separate spans.
- **How a call ends.** A completed call, an answer stopped by
  `StoppableHttpClient` included, ends with the usage the provider
  reported. A call that throws, and a stream whose reader stops reading
  it, ends with the failure Neuron reports for the run.
- **Tool calls.** An `McpTools` tool already has its tool-call span, so
  `observe()` adds no tool span.
- **Provider names.** Neuron's provider classes map to OpenTelemetry's
  `gen_ai.system` names: `OpenAI` to `openai`, `Anthropic` to
  `anthropic`, `Gemini` to `gcp.gemini`, `BedrockProvider` to
  `aws.bedrock`, and so on. A subclass such as
  `OpenAILike` can reach any service and reports `_OTHER`.

(neuron-call-fails)=

## When a call fails

Every failure of a call through `NeuronHttpClient` is a
`NeuronAI\Exceptions\HttpException`, the exception Neuron's providers
expect. The Bedrock providers fail differently ({ref}`neuron-bedrock`).

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

(neuron-vertex)=

## Vertex AI providers

`GeminiVertex` and `AnthropicVertex` send their model calls through the
`NeuronHttpClient` they are given, but authenticate with a Google
service-account token that does not go through it. Neuron gets the token
from `google/auth`'s `ServiceAccountCredentials::fetchAuthToken()` and
passes it no HTTP handler, so `google/auth` posts to
`https://oauth2.googleapis.com/token` through a Guzzle client of its own.
That request blocks the worker and its whole event loop until Google
answers. Guzzle's default client sets no overall timeout, so only its
connect timeout and the network bound the stall.

The provider keeps the token it fetched and fetches a new one when the
token is within 60 seconds of expiring; Google issues it for an hour.
The provider is built for each request
({ref}`neuron-scope-request`), so the first Vertex call of every request
makes one blocking token request, and later calls in the same request
reuse the token. Neuron builds the credentials inside the provider and
has no option to route the token request through another client.
`Gemini`, which authenticates with an API key, makes no token request.

Neuron does not require `google/auth`; install it to use these
providers.

(neuron-bedrock)=

## Amazon Bedrock

Neuron's `BedrockRuntime` provider and `AwsBedrockEmbeddingsProvider`
send through aws-sdk-php, which blocks the worker. Use
`Kinetis\Neuron\Bedrock\BedrockProvider` and
`Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider` instead. They call
Bedrock Runtime's Converse, ConverseStream and InvokeModel APIs through a
`Kinetis\AwsSigV4\SigV4SigningClient` ({doc}`aws-sigv4`), so a call
suspends the calling Fiber like any other provider call. aws-sdk-php is
not needed.

Build the signing client once at boot, for the region's Bedrock Runtime
endpoint and the `bedrock` signing service:

```{code-block} php
:caption: bootstrap.php

use App\Assistant\BedrockProviders;
use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

return static function (AppScope $app, Config $config): void {
    $region = $config->required('AWS_REGION');

    $app->instance(BedrockProviders::class, new BedrockProviders(new SigV4SigningClient(
        origin: "https://bedrock-runtime.{$region}.amazonaws.com",
        region: $region,
        service: 'bedrock',
        transport: SignedTransport::create(['timeout' => 120, 'max_duration' => 120]),
    )));
};
```

```{code-block} php
namespace App\Assistant;

use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Neuron\Bedrock\BedrockEmbeddingsProvider;
use Kinetis\Neuron\Bedrock\BedrockProvider;

final readonly class BedrockProviders
{
    public function __construct(private SigV4SigningClient $bedrock) {}

    public function claude(): BedrockProvider
    {
        return new BedrockProvider($this->bedrock, 'anthropic.claude-3-haiku-20240307-v1:0', ['maxTokens' => 1024]);
    }

    public function embeddings(): BedrockEmbeddingsProvider
    {
        return new BedrockEmbeddingsProvider($this->bedrock);
    }
}
```

Inject `BedrockProviders` where `SupportAssistant` injects `Providers`,
and pass `claude()` to `setAiProvider()` ({ref}`neuron-scope-request`).

- **Scope.** The signing client holds resolved credentials and reuses its
  connections, and keeps no request state, so one instance serves the
  worker. `BedrockProvider` holds the system prompt, the tools and a
  stream's state: build it in the request, like Neuron's providers.
- **Credentials** come from the signing client's default chain
  ({doc}`aws-sigv4`, "Credentials"). The role needs
  `bedrock:InvokeModel`, and `bedrock:InvokeModelWithResponseStream` to
  stream.
- **The deadline is the transport's.** `SignedTransport::create()`
  bounds a request at 30 seconds idle and 30 in total, short for a model
  answer; the example allows 120 for both.
- **The byte ceiling** is the providers' last argument: 8 MiB for
  `BedrockProvider`, 1 MiB for `BedrockEmbeddingsProvider`. Every
  response byte counts, a streamed answer's included. Past it the call
  throws and the connection is closed.
- **The model** is a model ID or an inference profile ID or ARN. It is
  sent in the request path to the signing client's origin; the provider
  never chooses a host.
- **What is sent** is what Neuron's `BedrockRuntime` sends: Neuron's own
  Bedrock message and tool mappers, the last four cache points,
  structured output as the schema appended to the system prompt for that
  call, and `inferenceConfig` as given. `BedrockEmbeddingsProvider` sends
  the Titan text embeddings body, `inputText`.
- **Nothing is sent twice.** A call is one signed request, with no retry
  in the provider, the signing client or its transport.

Every failure is a `NeuronAI\Exceptions\ProviderException`:

- **A status outside 2xx** names the status and Bedrock's error
  `message`, read under the byte ceiling.
- **A signing-client failure** keeps the `Kinetis\AwsSigV4\Exception`
  behind it as `$e->getPrevious()`. After a `NetworkFailureException`,
  Bedrock may have run and billed the request
  ({doc}`aws-sigv4`, "When a request fails").
- **A stream** that carries an exception or error event names its type
  and message. A frame that fails its checksum, a stream cut inside a
  frame, and a stream that ends before Bedrock's `messageStop` each fail
  with a fixed message.

No message holds the request or a credential.

(neuron-not-in-worker)=

## What not to use in a worker

These Neuron features block the worker, fork it, or keep state between
requests:

| Feature | Why |
|---|---|
| `CurlHttpClient`, Neuron's default when no client is passed | `curl_exec()` blocks the process for the whole call. |
| `AmpHttpClient` | `withBaseUri()` and `withHeaders()` change the instance they are called on, and it reads a body with no size limit. A shared instance carries one request's settings into the next. |
| `Agent::parallelToolCalls()` (`ParallelToolNode`) | It forks the worker process to run tools. |
| `FilePersistence`, `DatabasePersistence`, `FileMessageStore`, `SQLMessageStore` | Blocking file and PDO I/O. |
| `RedisPersistence`, `EloquentPersistence`, `EloquentMessageStore` | Blocking phpredis and Eloquent database I/O. |
| `PhpSerializer`, `IgbinarySerializer` | They unserialize workflow records with no class restriction. |
| `MySQLToolkit`, `PGSQLToolkit` and their tools | Blocking PDO queries. |
| `FileSystemToolkit` and its tools | Blocking file I/O; `BashTool` waits on a child process. |
| `SESTool` from the AWS toolkit | The AWS SDK client sends synchronously. |
| `BedrockRuntime`, `AwsBedrockEmbeddingsProvider` | The AWS SDK client sends synchronously. Use `BedrockProvider` and `BedrockEmbeddingsProvider` ({ref}`neuron-bedrock`). |
| `FileDataLoader`, `PdfReader` | Blocking file reads; `PdfReader` waits on a `pdftotext` child process. |
| `FileVectorStore`, `MariaDBVectorStore`, `MongoDBVectorStore` | Blocking file, PDO and MongoDB driver I/O. |
| `TypesenseVectorStore` | It sends through the Typesense SDK client, for which Kinetis has no non-blocking transport. |
| `Neo4jGraphStore` | The Bolt driver blocks on its socket. |
| `FileEvaluationCache` from Neuron's evaluation tools | Blocking file I/O. |
| `GeminiVertex`, `AnthropicVertex` | Each request's first call fetches a service-account token with a blocking request ({ref}`neuron-vertex`). |
| `OpenAISpeechToText`, `ElevenLabsSpeechToText` | They upload the audio as a multipart body, which `NeuronHttpClient` refuses ({ref}`neuron-call-fails`). |
| A workflow interrupt and resume | A resumed node runs again from its start and repeats whatever it did before the interrupt that was not memoized. |
| `StdioTransport` for MCP, an `McpConnector` configured with `command` | `proc_open()` and blocking pipe reads. |
| `SseHttpTransport` for MCP, an `McpConnector` configured with `async: true` | `fopen()` on the server URL blocks. |
| `UsageMeter` from Neuron's evaluation tools | It records into a static, shared by every request in the worker. |
| Streaming an answer to the client under RoadRunner | RoadRunner answers a `StreamedResponse` with `501` ({doc}`runtime-adapters`). |

(neuron-phpstan-rule)=

### Flagging these with PHPStan

`Kinetis\Neuron\Linting\NoWorkerUnsafeNeuronRule` is a PHPStan rule for
application code. Like the framework's `NoBlockingIoRule`
({ref}`non-blocking-application-io`), it ships under the package's main
autoload and needs nothing beyond PHPStan. Add it to the application's
`phpstan.neon`:

```{code-block} yaml
:caption: phpstan.neon

rules:
    - Kinetis\Neuron\Linting\NoWorkerUnsafeNeuronRule
```

Every report carries the identifier `kinetis.neuronWorkerUnsafe` and a
message naming the reason from the table above and what to use instead.
It reports:

- `new X(...)` and `X::make(...)` when X's constructor takes a nullable
  `NeuronAI\HttpClient\HttpClientInterface` and the call leaves it out or
  passes a literal `null`, which is how Neuron falls back to
  `CurlHttpClient`. Every provider, toolkit tool, `McpConnector`, HTTP
  vector store and embeddings provider built without a `NeuronHttpClient`
  is reported this way.
- `new CurlHttpClient` and `new AmpHttpClient`.
- `parallelToolCalls()` on an `Agent`, unless its argument is a literal
  `false`.
- `new` of `FilePersistence`, `DatabasePersistence`, `RedisPersistence`,
  `EloquentPersistence`, `FileMessageStore`, `SQLMessageStore`,
  `EloquentMessageStore`, `PhpSerializer` and `IgbinarySerializer`.
- `new` and `make()` of the MySQL, PGSQL, file-system and AWS toolkits
  and of each of their tools.
- `new BedrockRuntime` and `new AwsBedrockEmbeddingsProvider`.
- `new FileDataLoader`, `FileDataLoader::for()`, `new PdfReader`,
  `new Neo4jGraphStore`, `new FileEvaluationCache`, and `new` of
  `FileVectorStore`, `MariaDBVectorStore` and `MongoDBVectorStore`.
- `new StdioTransport`, `new SseHttpTransport`, and an `McpConnector`
  whose configuration is an array literal with a `command` key or with
  `async` set to `true`.
- `UsageMeter::watch()` and `new UsageMeter`.

It does not report a workflow interrupt and resume, streaming under
RoadRunner, `TypesenseVectorStore`, an Elasticsearch or OpenSearch store given a client built outside the
Kinetis factories, a speech-to-text provider given a
`NeuronHttpClient`, an `McpConnector` configuration that is not an array
literal, a call whose class name is an expression or whose arguments are
spread, or an object built outside the analysed code, such as by a
library.

## See also

- {doc}`revolt-http-client` — the `Http` client: base URLs, deadlines,
  byte ceilings and failure categories.
- {doc}`concurrency` — running several calls at once.
- {doc}`container` — application and request scope.
- {doc}`mcp` — declaring `#[McpTool]` tools.
