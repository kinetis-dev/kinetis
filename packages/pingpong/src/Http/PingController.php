<?php

declare(strict_types=1);

namespace App\Http;

use App\Dto\ScenarioCounts;
use App\Events\ActionEvent;
use App\Queue\PongJob;
use App\Repositories\PingRepository;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Events\EventDispatcher;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Queue\QueueInterface;
use League\Plates\Engine;
use Psr\Http\Message\ResponseInterface;

final readonly class PingController
{
    public function __construct(
        private PingRepository $pings,
        private QueueInterface $queue,
        private EventDispatcher $events,
        private Config $config,
    ) {}

    /**
     * Renders the dashboard page. The logo, stylesheet, and script are
     * static files under public/ — only the broadcast connection details
     * are actually dynamic, passed to the static dashboard.js via a
     * type="application/json" data island rather than any inline script
     * logic of the template's own.
     */
    #[Get('/')]
    #[Hidden]
    public function index(): ResponseInterface
    {
        $engine = new Engine(dirname(__DIR__, 2) . '/resources/views');

        return HtmlResponse::create($engine->render('dashboard', [
            'broadcastConfig' => [
                'key' => $this->config->string('BROADCAST_KEY', 'app-key'),
                'host' => $this->config->string('BROADCAST_BROWSER_HOST', 'localhost'),
                'port' => self::browserPort($this->config),
            ],
        ]));
    }

    /**
     * Extracted out of index() as its own testable seam: the smallest
     * possible unit — a plain Config in, an int out — rather than
     * needing a full controller construction (PingRepository/
     * QueueInterface/EventDispatcher, none of which this check has
     * anything to do with) just to exercise one config bound.
     */
    public static function browserPort(Config $config): int
    {
        $port = $config->int('BROADCAST_BROWSER_PORT', 6001);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("BROADCAST_BROWSER_PORT must be a valid TCP port (1-65535), got {$port}.");
        }

        return $port;
    }

    /**
     * The current tally per scenario, read once by the dashboard on page
     * load so its counters start from the real totals instead of zero.
     */
    #[Get('/pong/tally')]
    public function tally(): ScenarioCounts
    {
        return $this->pings->countByScenario();
    }

    /**
     * The "direct" ping scenario: ponged synchronously, in this request.
     */
    #[Post('/pong/direct')]
    public function direct(): void
    {
        $id = $this->pings->create('direct');
        $this->events->dispatch(new ActionEvent('app', $id, 'direct'));
        $this->pings->markPonged($id);
        $this->events->dispatch(new ActionEvent('socket', $id, 'direct'));
    }

    /**
     * The "queued" ping scenario: pushed onto the queue with a delay,
     * ponged later by the queue worker.
     */
    #[Post('/pong/queued')]
    public function queued(): void
    {
        $id = $this->pings->create('queued');
        $this->events->dispatch(new ActionEvent('app', $id, 'queued'));
        $this->queue->push(new PongJob($id), 5);
    }
}
