<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use JsonException;
use Kinetis\McpDocs\Exception\DocsFetchException;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\ToolAnnotations;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;
use stdClass;

/**
 * The Kinetis documentation as MCP resources, plus the one tool that
 * reads a bounded line window of a page. The catalogue is fixed, so
 * there are no prompts, no subscriptions, and nothing a client can
 * change: listing, reading and windowing are the whole surface, and the
 * protocol around them belongs to kinetis/mcp-protocol.
 *
 * {@see READ_TOOL} returns one bounded window of a page, which is how
 * a page is read; a resource read returns the whole page, for a caller
 * that needs all of it. Both fetch the page on every call, so a window
 * is bounded output, not a stored, cursored or snapshotted read:
 * nothing about one call survives into the next.
 *
 * A fetch that fails is reported to the process's own diagnostic stream
 * and answered with a generic protocol error. The URL, the transport's
 * message and the status a failure carries are this server's diagnostics,
 * never a client's.
 */
// Not `readonly`: $diagnostics holds a stream, and `resource` is not a
// type a property can declare, which a readonly property requires.
final class DocsApplication implements McpApplication
{
    public const string SERVER_NAME = 'kinetis-mcp-docs';

    /** Paired against this package's manifest version by the suite. */
    public const string SERVER_VERSION = '1.5.1';

    /**
     * The documentation-window tool's name. Exported because
     * kinetis/orbitron publishes this tool and routes its calls back
     * here, and because the suite drives it: one authority, never a
     * second copy of the string.
     */
    public const string READ_TOOL = 'kinetis_read_doc';

    /** The most lines one window returns, which is also the default. */
    public const int MAX_LINE_COUNT = 200;

    /**
     * The most content bytes one window returns. Some pages carry lines
     * of several kilobytes, so a line count alone does not bound a
     * response.
     */
    public const int MAX_CONTENT_BYTES = 32768;

    private const string INSTRUCTIONS = 'Resources are Kinetis documentation pages, served as published '
        . 'markdown from main — read them instead of answering about Kinetis from memory. Call resources/list, '
        . 'then ' . self::READ_TOOL . ' with a page URI from line 1; start at kinetis://docs/agent-workflow. '
        . 'Continue from the line it reports only while what you came to the page for is unresolved. Read the '
        . 'same URI with resources/read when the whole page is what you need. A page can describe behavior newer '
        . 'than the release installed in this project: establish the installed package versions and inspect '
        . 'matching installed source before treating a version-sensitive claim as settled.';

    private readonly DocsFetcher $fetcher;

    /** @var resource|null */
    private $diagnostics;

    /**
     * @param resource|null $diagnostics stream a fetch failure's real
     *     reason is written to; null discards it. Never stdout, which
     *     carries nothing but JSON-RPC frames.
     */
    public function __construct(?DocsFetcher $fetcher = null, $diagnostics = null)
    {
        $this->fetcher = $fetcher ?? new DocsFetcher();
        $this->diagnostics = $diagnostics;
    }

    /**
     * The identity and instructions this server answers `initialize` with
     * — one authority for the binary and the suite alike.
     */
    public static function serverInfo(): ServerInfo
    {
        return new ServerInfo(self::SERVER_NAME, self::SERVER_VERSION, self::INSTRUCTIONS);
    }

    /**
     * @return list<ToolDescription>
     */
    #[\Override]
    public function tools(): array
    {
        return [self::readTool()];
    }

    /**
     * The documentation-window tool as this server publishes it: name,
     * description, schema and annotations authored here once. A consumer
     * that re-publishes this tool — kinetis/orbitron — includes this
     * description rather than restating it, so no client sees two
     * accounts of one tool.
     *
     * Open-world because the page is fetched from the documentation
     * origin on every call; read-only and idempotent because a call
     * changes nothing and the same window of an unchanged page answers
     * the same way.
     */
    public static function readTool(): ToolDescription
    {
        return new ToolDescription(
            self::READ_TOOL,
            'Reports one line window of one Kinetis documentation page as a JSON document — the way to read a '
            . 'page, starting at line 1. Takes the page URI, as resources/list reports it, and an optional window. '
            . 'A success reports "status", "uri", "startLine", "endLine", "hasMore" and "content"; continue by '
            . 'calling again with "startLine" set to the reported "endLine" plus one, and successive windows '
            . 'reconstruct the page exactly as long as it has not changed on the remote between calls — every '
            . 'call fetches it again, and nothing is cached or snapshotted. A window ends at "lineCount" lines or '
            . self::MAX_CONTENT_BYTES . ' bytes of content, whichever comes first, so "endLine" can fall short of '
            . 'what was asked for; it always carries at least one line, which is the only case a window exceeds '
            . 'that size. A refusal reports "status": "error" and one of "resource_unknown", "line_out_of_range". '
            . 'Reading the same URI as a resource returns the whole page instead, when that is what is '
            . 'needed. Writes nothing.',
            [
                'type' => 'object',
                'properties' => [
                    'uri' => [
                        'type' => 'string',
                        'minLength' => 1,
                        'description' => 'A documentation page URI, as resources/list reports it.',
                    ],
                    'startLine' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'default' => 1,
                        'description' => 'The first line to return, counting from 1.',
                    ],
                    'lineCount' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => self::MAX_LINE_COUNT,
                        'default' => self::MAX_LINE_COUNT,
                        'description' => 'How many lines to return.',
                    ],
                ],
                'required' => ['uri'],
                'additionalProperties' => false,
            ],
            new ToolAnnotations(readOnly: true, destructive: false, idempotent: true, openWorld: true),
        );
    }

    /**
     * @return list<ResourceDescription>
     */
    #[\Override]
    public function resources(): array
    {
        $resources = [];

        foreach (DocsCatalogue::pages() as $page) {
            $resources[] = new ResourceDescription(
                $page->uri(),
                $page->name,
                $page->description,
                DocsCatalogue::MIME_TYPE,
            );
        }

        return $resources;
    }

    /**
     * The one tool this server publishes. The whole schema is validated
     * here before the catalogue is consulted: an argument the schema has
     * no reading of is a protocol error, not a refusal document, while a
     * well-typed URI the catalogue does not carry is a tool that ran and
     * refused.
     *
     * @throws JsonException when the document cannot be encoded, which
     *         {@see \Kinetis\McpProtocol\McpServer} contains
     */
    #[\Override]
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult {
        // The server resolves a name against tools() before calling, so
        // this answers only a caller driving this class directly.
        if ($name !== self::READ_TOOL) {
            throw JsonRpcException::invalidParams("Unknown tool: \"{$name}\".");
        }

        [$uri, $startLine, $lineCount] = self::readArguments($arguments);
        $page = DocsCatalogue::find($uri);

        if ($page === null) {
            return self::refuse('resource_unknown');
        }

        $lines = self::lines($this->text($page));
        $total = count($lines);

        if ($startLine > $total) {
            return self::refuse('line_out_of_range');
        }

        [$content, $endLine] = self::window($lines, $startLine, $lineCount);

        return ToolResult::text(self::document([
            'status' => 'ok',
            'uri' => $page->uri(),
            'startLine' => $startLine,
            'endLine' => $endLine,
            'hasMore' => $endLine < $total,
            'content' => $content,
        ]));
    }

    #[\Override]
    public function readResource(string $uri, ?object $context): ResourceResult
    {
        $page = DocsCatalogue::find($uri);

        if ($page === null) {
            throw JsonRpcException::resourceNotFound($uri);
        }

        return new ResourceResult($page->uri(), DocsCatalogue::MIME_TYPE, $this->text($page));
    }

    /**
     * The page's current markdown. A resource read and a window read
     * share this, so both fetch live, bound the fetch identically, and
     * answer a failure with the same generic error while the real reason
     * goes to this server's own diagnostic stream.
     */
    private function text(DocsPage $page): string
    {
        try {
            $text = $this->fetcher->fetch($page);
        } catch (DocsFetchException $e) {
            $this->reportDiagnostic($e->getMessage());

            throw JsonRpcException::internalError("Could not read \"{$page->uri()}\".");
        }

        // A page that is not valid UTF-8 cannot be put into a JSON frame
        // at all, so it is rejected here rather than left to break the
        // encode of a response already declared successful. It would also
        // let a window split a character in two.
        if (preg_match('//u', $text) !== 1) {
            $this->reportDiagnostic("{$page->sourceUrl()} is not valid UTF-8.");

            throw JsonRpcException::internalError("Could not read \"{$page->uri()}\".");
        }

        return $text;
    }

    /**
     * The page split after every newline, so each line keeps its own
     * ending — CRLF included — and a page with no final newline keeps
     * that. Concatenating the pieces reproduces the page byte for byte,
     * which is what lets successive windows of one unchanged fetch do
     * the same — each call re-fetches and re-splits, so that guarantee
     * holds only while the remote page has not changed between calls.
     *
     * A split point only ever follows a newline, so the sole empty piece
     * PREG_SPLIT_NO_EMPTY can drop is the one past a trailing newline.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/(?<=\n)/', $text, flags: PREG_SPLIT_NO_EMPTY);
        \assert(is_array($lines));

        return $lines;
    }

    /**
     * The window's content and the last line it carries.
     *
     * Two bounds end it: the requested line count, and the byte ceiling
     * that keeps a page of very long lines from returning megabytes for
     * 200 of them. A line that would pass the ceiling ends the window
     * before it, never in the middle of it.
     *
     * The first line is taken before the loop, and so whatever its size:
     * a window that returned nothing would leave a caller no line to
     * continue from, so a single line longer than the ceiling is served
     * whole — the one case a response exceeds it.
     *
     * @param list<string> $lines
     * @param int $startLine one-based, and within $lines: the caller
     *        refuses a start past the page before reaching this
     * @return array{string, int}
     */
    private static function window(array $lines, int $startLine, int $lineCount): array
    {
        $content = $lines[$startLine - 1];
        $endLine = $startLine;
        $total = count($lines);

        for ($line = $startLine + 1; $line < $startLine + $lineCount && $line <= $total; $line++) {
            $next = $lines[$line - 1];

            if (strlen($content) + strlen($next) > self::MAX_CONTENT_BYTES) {
                break;
            }

            $content .= $next;
            $endLine = $line;
        }

        return [$content, $endLine];
    }

    /**
     * The window call's arguments, each validated for presence, type and
     * range, and every key the schema does not name refused.
     *
     * @return array{string, int, int}
     */
    private static function readArguments(stdClass $arguments): array
    {
        $values = get_object_vars($arguments);
        $unknown = array_diff(array_keys($values), ['uri', 'startLine', 'lineCount']);

        if ($unknown !== []) {
            throw JsonRpcException::invalidParams('Unknown argument: "' . implode('", "', $unknown) . '".');
        }

        $uri = $values['uri'] ?? throw JsonRpcException::invalidParams('"uri" is required.');

        if (!\is_string($uri) || $uri === '') {
            throw JsonRpcException::invalidParams('"uri" must be a non-empty string.');
        }

        $startLine = \array_key_exists('startLine', $values) ? $values['startLine'] : 1;

        if (!\is_int($startLine) || $startLine < 1) {
            throw JsonRpcException::invalidParams('"startLine" must be an integer of at least 1.');
        }

        $lineCount = \array_key_exists('lineCount', $values) ? $values['lineCount'] : self::MAX_LINE_COUNT;

        if (!\is_int($lineCount) || $lineCount < 1 || $lineCount > self::MAX_LINE_COUNT) {
            throw JsonRpcException::invalidParams(
                '"lineCount" must be an integer between 1 and ' . self::MAX_LINE_COUNT . '.',
            );
        }

        return [$uri, $startLine, $lineCount];
    }

    /**
     * A refusal carries the code and nothing else, and comes back as an
     * MCP error result: the tool ran and concluded, so the code stays
     * readable rather than becoming a transport error.
     */
    private static function refuse(string $code): ToolResult
    {
        return ToolResult::error(self::document(['status' => 'error', 'code' => $code]));
    }

    /**
     * One document per result: key order as built, slashes and unicode
     * left as written, and a single trailing newline.
     *
     * @param array<string, mixed> $body
     * @throws JsonException
     */
    private static function document(array $body): string
    {
        return json_encode(
            $body,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    private function reportDiagnostic(string $message): void
    {
        if ($this->diagnostics === null) {
            return;
        }

        fwrite($this->diagnostics, self::SERVER_NAME . ": {$message}\n");
    }
}
