<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Integration;

use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Driver\MysqliAsyncClient;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\Query;
use PHPUnit\Framework\TestCase;

/**
 * Two requests through tests/Fixtures/OrmWorker/index.php under a real
 * SAPI. The first loads a post through its request-scoped EntityManager,
 * changes it without flushing and waits on a SLEEP beside a timer sentinel;
 * the second loads the same post through its own manager. The database is
 * read on this test's own connection between the two.
 *
 * Under a FrankenPHP worker both requests reach one PHP interpreter, the
 * auto driver is the native one, and the first manager must be gone by the
 * second request. Under PHP-FPM every request runs the script again, on the
 * PDO driver, whose blocking SLEEP leaves the sentinel unturned.
 *
 * Environment-gated: skips unless KINETIS_ORM_WORKER_HOST names the
 * host:port serving the fixture, KINETIS_ORM_WORKER_SAPI names that SAPI
 * (frankenphp or fpm), and MYSQL_HOST names the server the fixture's DB_*
 * configuration uses. CI's integration workflow runs it in the orm-runtime
 * job.
 */
final class OrmWorkerSequenceTest extends TestCase
{
    private ?MysqlLink $db = null;

    protected function tearDown(): void
    {
        $this->db?->close();
        $this->db = null;
    }

    public function test_each_request_gets_its_own_manager_and_disposal_writes_nothing(): void
    {
        $host = getenv('KINETIS_ORM_WORKER_HOST');
        $sapi = getenv('KINETIS_ORM_WORKER_SAPI');
        $mysql = getenv('MYSQL_HOST');

        if ($host === false || $mysql === false || ($sapi !== 'frankenphp' && $sapi !== 'fpm')) {
            self::markTestSkipped('KINETIS_ORM_WORKER_HOST, KINETIS_ORM_WORKER_SAPI and MYSQL_HOST are not all set — the worker sequence is environment-gated.');
        }

        $db = $this->db = SqlConnectionFactory::create(new ConnectionDefinition(
            dialect: 'mysql',
            host: $mysql,
            database: getenv('MYSQL_DATABASE') ?: 'testdb',
            user: getenv('MYSQL_USER') ?: 'testuser',
            password: getenv('MYSQL_PASSWORD') ?: 'testpass',
            driver: 'pdo',
        ));
        $db->execute('DROP TABLE IF EXISTS kinetis_orm_worker_posts');
        $db->execute('CREATE TABLE kinetis_orm_worker_posts (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL)');
        new Query($db)->table('kinetis_orm_worker_posts')->insert(['id' => 1, 'title' => 'Stored']);

        [$draftWorker, $persistent, $draft] = self::send($host, 'POST', '/posts/1/draft');
        $stored = new Query($db)->table('kinetis_orm_worker_posts')->where('id', '=', 1)->value('title');
        [$showWorker, , $show] = self::send($host, 'GET', '/posts/1');

        self::assertSame('Unflushed', $draft['title']);
        self::assertSame('Stored', $stored, "The first request's disposal wrote its unflushed change.");
        self::assertSame('Stored', $show['title'], "The second request loaded the first request's changed entity.");
        self::assertSame(
            match ($sapi) {
                'frankenphp' => ['persistent' => 'true', 'sameWorker' => true, 'driver' => MysqliAsyncClient::class, 'loopTurned' => true, 'draftManager' => 'released'],
                'fpm' => ['persistent' => 'false', 'sameWorker' => false, 'driver' => PdoMysqlClient::class, 'loopTurned' => false, 'draftManager' => 'none'],
            },
            [
                'persistent' => $persistent,
                'sameWorker' => $draftWorker === $showWorker,
                'driver' => $draft['driver'],
                'loopTurned' => $draft['loopTurned'],
                'draftManager' => $show['draftManager'],
            ],
        );
    }

    /**
     * @return array{string, string, array<string, mixed>} the X-Worker and X-Persistent values and the decoded JSON body
     */
    private static function send(string $host, string $method, string $path): array
    {
        $body = @file_get_contents("http://{$host}{$path}", false, stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Length: 0\r\nConnection: close",
            'ignore_errors' => true,
            'timeout' => 10,
        ]]));
        $head = http_get_last_response_headers() ?? [];

        self::assertIsString($body, "{$method} {$path}: no response from {$host}.");
        self::assertMatchesRegularExpression('~^HTTP/\S+ 200 ~', $head[0] ?? '', "{$method} {$path}: " . ($head[0] ?? 'no status line') . "\n{$body}");

        $headers = [];

        foreach (array_slice($head, 1) as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        return [$headers['x-worker'] ?? '', $headers['x-persistent'] ?? '', $decoded];
    }
}
