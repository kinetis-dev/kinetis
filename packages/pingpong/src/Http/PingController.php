<?php

declare(strict_types=1);

namespace App\Http;

use App\Dto\ScenarioCounts;
use App\Events\ActionEvent;
use App\Queue\PongJob;
use App\Repositories\PingRepository;
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
     * static files under public/ — only the Soketi connection details are
     * actually dynamic, passed to the static dashboard.js via a
     * type="application/json" data island rather than any inline script
     * logic of the template's own.
     */
    #[Get('/')]
    #[Hidden]
    public function index(): ResponseInterface
    {
        $engine = new Engine(dirname(__DIR__, 2) . '/resources/views');

        return HtmlResponse::create($engine->render('dashboard', [
            'soketiConfig' => [
                'key' => $this->config->string('SOKETI_KEY', 'app-key'),
                'host' => $this->config->string('SOKETI_BROWSER_HOST', 'localhost'),
                'port' => $this->config->int('SOKETI_BROWSER_PORT', 6001),
            ],
        ]));
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
