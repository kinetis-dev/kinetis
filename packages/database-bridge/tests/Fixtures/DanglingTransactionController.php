<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\Http\Attributes\Post;
use Kinetis\Persistence\TransactionGuard;

/**
 * Deliberately begins a transaction and never commits or rolls it back —
 * proving the cleanup registered on the request's scope, when the
 * controller resolves its guard, is what actually closes it, not the
 * controller.
 */
final readonly class DanglingTransactionController
{
    public function __construct(
        private TransactionGuard $guard,
    ) {}

    #[Post('/begin-transaction')]
    public function begin(): array
    {
        $link = new FakeSqlLink();
        $this->guard->beginTransaction($link);

        DanglingTransactionHolder::$link = $link;

        return ['started' => true];
    }
}
