<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Services\SoketiPublisher;
use Kinetis\Config\Config;
use PHPUnit\Framework\TestCase;
use Pusher\Pusher;

/**
 * The real-time push, without a Soketi server. What matters here is the
 * shape of what goes on the wire — the dashboard's JavaScript matches on
 * the channel and event name, so a change to either silently stops every
 * update arriving while every test that only checked "it did not throw"
 * keeps passing.
 */
final class SoketiPublisherTest extends TestCase
{
    public function test_publishes_the_stage_id_and_scenario_the_dashboard_listens_for(): void
    {
        $pusher = $this->createMock(Pusher::class);
        $pusher->expects(self::once())
            ->method('trigger')
            ->with(
                SoketiPublisher::CHANNEL,
                'action',
                ['stage' => 'db', 'id' => 7, 'scenario' => 'direct'],
            );

        new SoketiPublisher($pusher)->actionOccurred('db', 7, 'direct');
    }

    /**
     * The browser flashes a diagram node per stage; a stage with no row
     * yet, or no scenario, still has to arrive rather than be dropped.
     */
    public function test_a_stage_without_an_id_or_scenario_is_still_published(): void
    {
        $pusher = $this->createMock(Pusher::class);
        $pusher->expects(self::once())
            ->method('trigger')
            ->with(SoketiPublisher::CHANNEL, 'action', ['stage' => 'app', 'id' => null, 'scenario' => null]);

        new SoketiPublisher($pusher)->actionOccurred('app', null);
    }

    /**
     * The Pusher client opens no connection until something is
     * triggered, so its settings can be read back without a server. They
     * are read back rather than the object merely being constructed: a
     * key wired to the wrong setting still produces a SoketiPublisher,
     * and only fails once a browser is waiting for an event.
     */
    public function test_configuration_reaches_the_pusher_client(): void
    {
        $settings = $this->settingsOf(SoketiPublisher::fromConfig(new Config([
            'SOKETI_KEY' => 'k',
            'SOKETI_SECRET' => 's',
            'SOKETI_APP_ID' => 'a',
            'SOKETI_HOST' => 'soketi.test',
            'SOKETI_PORT' => '6002',
        ])));

        self::assertSame('k', $settings['auth_key']);
        self::assertSame('s', $settings['secret']);
        self::assertSame('a', $settings['app_id']);
        self::assertSame('soketi.test', $settings['host']);
        self::assertSame(6002, $settings['port']);
        // useTLS is normalised into the scheme, which is what is actually
        // dialled — the local stack runs Soketi without TLS.
        self::assertSame('http', $settings['scheme']);
    }

    /**
     * The defaults match docker-compose.yml's own service name and port,
     * so the stack works with no Soketi variables set at all.
     */
    public function test_defaults_point_at_the_compose_service(): void
    {
        $settings = $this->settingsOf(SoketiPublisher::fromConfig(new Config([])));

        self::assertSame('soketi', $settings['host']);
        self::assertSame(6001, $settings['port']);
        self::assertSame('app-key', $settings['auth_key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsOf(SoketiPublisher $publisher): array
    {
        $pusher = new \ReflectionProperty(SoketiPublisher::class, 'pusher')->getValue($publisher);
        self::assertInstanceOf(Pusher::class, $pusher);

        return $pusher->getSettings();
    }
}
