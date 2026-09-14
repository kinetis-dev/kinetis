<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Persistence\TransactionGuard;

/**
 * The MCP counterpart of DanglingTransactionController: a tool that
 * begins a transaction and never closes it, proving the cleanup
 * registered on each message's own scope is what actually rolls it back,
 * over either transport.
 */
final readonly class DanglingTransactionToolController
{
    public function __construct(
        private TransactionGuard $guard,
    ) {}

    /**
     * @return array{started: bool}
     */
    #[McpTool(name: 'begin_transaction', description: 'Opens a transaction and leaves it open')]
    public function begin(): array
    {
        $link = new FakeSqlLink();
        $this->guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;

        return ['started' => true];
    }
}
