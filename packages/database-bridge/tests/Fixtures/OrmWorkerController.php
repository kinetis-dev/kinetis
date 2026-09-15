<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures;

use Kinetis\DatabaseBridge\Tests\Fixtures\OrmWorker\Entities\WorkerPost;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post as PostRoute;
use Kinetis\Orm\EntityManager;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Testing\LoopLiveness;
use WeakReference;

/**
 * The two routes OrmWorkerSequenceTest requests through OrmWorker/index.php.
 * Each request's controller receives that request's EntityManager.
 *
 * The static property is the test's observation point, not application
 * state: it holds the drafting request's manager weakly, so it never keeps
 * that manager alive, and a later request reports what became of it.
 */
final class OrmWorkerController
{
    /** @var WeakReference<EntityManager>|null */
    private static ?WeakReference $drafted = null;

    public function __construct(
        private readonly EntityManager $entities,
        private readonly MysqlLink $db,
    ) {}

    /**
     * Changes the post without flushing, then waits on SLEEP through the
     * link the OrmFactory was built from, beside a timer sentinel.
     *
     * @return array{title: string, driver: class-string, loopTurned: bool}
     */
    #[PostRoute('/posts/{id}/draft')]
    public function draft(int $id): array
    {
        self::$drafted = WeakReference::create($this->entities);
        $post = $this->entities->repository(WorkerPost::class)->findOrFail($id);
        $post->title = 'Unflushed';

        return [
            'title' => $post->title,
            'driver' => $this->db::class,
            'loopTurned' => LoopLiveness::turnedDuring(fn (): mixed => $this->db->query('SELECT SLEEP(0.2)')),
        ];
    }

    /**
     * @return array{title: string, draftManager: 'none'|'released'|'reused'|'closed'|'open'}
     */
    #[Get('/posts/{id}')]
    public function show(int $id): array
    {
        $drafted = self::$drafted?->get();

        return [
            'title' => $this->entities->repository(WorkerPost::class)->findOrFail($id)->title,
            'draftManager' => match (true) {
                self::$drafted === null => 'none',
                $drafted === null => 'released',
                $drafted === $this->entities => 'reused',
                $drafted->isClosed() => 'closed',
                default => 'open',
            },
        ];
    }
}
