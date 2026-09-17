<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use Kinetis\McpDocs\Exception\DocsFetchException;
use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;
use stdClass;

/**
 * The Kinetis documentation as MCP resources. The catalogue is fixed, so
 * there are no tools, no prompts, no subscriptions, and nothing a client
 * can change: listing and reading are the whole surface, and the protocol
 * around them belongs to kinetis/mcp-protocol.
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
    public const string SERVER_VERSION = '1.4.0';

    private const string INSTRUCTIONS = 'Resources are Kinetis documentation pages, served as published '
        . 'markdown from main — read them instead of answering about Kinetis from memory. Call resources/list, '
        . 'then resources/read with a page URI; start at kinetis://docs/agent-workflow. A page can describe '
        . 'behavior newer than the release installed in this project: establish the installed package versions '
        . 'and inspect matching installed source before treating a version-sensitive claim as settled.';

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
     * No tools: every page is a resource, and there is nothing to invoke.
     * The server advertises no `tools` capability for an empty list, so no
     * client ever reaches callTool() below.
     *
     * @return list<ToolDescription>
     */
    #[\Override]
    public function tools(): array
    {
        return [];
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
     * Unreachable: {@see tools()} is empty and the server resolves every
     * name against it before calling. Answered rather than left to become
     * a generic internal error if a caller ever drives this class without
     * the server in front of it.
     */
    #[\Override]
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult {
        return ToolResult::error('This server publishes documentation resources and no tools.');
    }

    #[\Override]
    public function readResource(string $uri, ?object $context): ResourceResult
    {
        $page = DocsCatalogue::find($uri);

        if ($page === null) {
            throw JsonRpcException::resourceNotFound($uri);
        }

        try {
            $text = $this->fetcher->fetch($page);
        } catch (DocsFetchException $e) {
            $this->reportDiagnostic($e->getMessage());

            throw JsonRpcException::internalError("Could not read \"{$uri}\".");
        }

        // A page that is not valid UTF-8 cannot be put into a JSON frame
        // at all, so it is rejected here rather than left to break the
        // encode of a response already declared successful.
        if (preg_match('//u', $text) !== 1) {
            $this->reportDiagnostic("{$page->sourceUrl()} is not valid UTF-8.");

            throw JsonRpcException::internalError("Could not read \"{$uri}\".");
        }

        return new ResourceResult($page->uri(), DocsCatalogue::MIME_TYPE, $text);
    }

    private function reportDiagnostic(string $message): void
    {
        if ($this->diagnostics === null) {
            return;
        }

        fwrite($this->diagnostics, self::SERVER_NAME . ": {$message}\n");
    }
}
