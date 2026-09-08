<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchCall;
use Kinetis\Search\Tests\Fixtures\EngineHalf;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The half of an adapter that is the same on either engine: which call
 * is made, with which parameters, and what a 404 means. Each engine
 * package's own suite covers the rest against that engine's real client.
 */
final class AbstractSearchClientTest extends TestCase
{
    /** @var list<array{call: SearchCall, params: array<string, mixed>}> */
    private array $sent = [];

    public function test_index_names_the_target_and_carries_the_document(): void
    {
        $result = $this->clientAnswering(['result' => 'created'])->index('articles', '1', ['title' => 'Kinetis']);

        self::assertSame('created', $result['result']);
        self::assertSame(SearchCall::Index, $this->sent[0]['call']);
        self::assertSame(
            ['index' => 'articles', 'body' => ['title' => 'Kinetis'], 'id' => '1'],
            $this->sent[0]['params'],
        );
    }

    public function test_index_without_an_id_sends_none(): void
    {
        $this->clientAnswering(['result' => 'created'])->index('articles', null, ['title' => 'Kinetis']);

        self::assertArrayNotHasKey('id', $this->sent[0]['params']);
    }

    public function test_refresh_is_only_asked_for_when_it_is_wanted(): void
    {
        $client = $this->clientAnswering(['result' => 'created']);

        $client->index('articles', '1', ['n' => 1], refresh: true);
        $client->delete('articles', '2', refresh: true);
        $client->bulk([BulkOperation::delete('articles', '3')], refresh: true);
        $client->index('articles', '4', ['n' => 1]);

        self::assertSame('true', $this->sent[0]['params']['refresh']);
        self::assertSame('true', $this->sent[1]['params']['refresh']);
        self::assertSame('true', $this->sent[2]['params']['refresh']);
        self::assertArrayNotHasKey('refresh', $this->sent[3]['params']);
    }

    public function test_get_names_one_document_and_answers_the_envelope(): void
    {
        $envelope = $this->clientAnswering(['_version' => 3, '_source' => ['title' => 'Kinetis']])->get('articles', '1');

        self::assertSame(3, $envelope['_version']);
        self::assertSame(SearchCall::Get, $this->sent[0]['call']);
        self::assertSame(['index' => 'articles', 'id' => '1'], $this->sent[0]['params']);
    }

    public function test_search_passes_the_body_through(): void
    {
        $body = ['query' => ['match' => ['title' => 'Kinetis']]];

        $this->clientAnswering(['hits' => ['total' => ['value' => 0], 'hits' => []]])->search('articles,logs', $body);

        self::assertSame(SearchCall::Search, $this->sent[0]['call']);
        self::assertSame(['index' => 'articles,logs', 'body' => $body], $this->sent[0]['params']);
    }

    public function test_bulk_sends_the_lines_every_operation_contributes(): void
    {
        $this->clientAnswering(['errors' => false, 'items' => []])->bulk([
            BulkOperation::index('articles', '1', ['title' => 'Kinetis']),
            BulkOperation::delete('articles', '2'),
        ]);

        self::assertSame(SearchCall::Bulk, $this->sent[0]['call']);
        self::assertSame(
            [
                ['index' => ['_index' => 'articles', '_id' => '1']],
                ['title' => 'Kinetis'],
                ['delete' => ['_index' => 'articles', '_id' => '2']],
            ],
            $this->sent[0]['params']['body'],
        );
    }

    /**
     * The two calls that name one document, and only those two: a 404 is
     * an answer there and a failure everywhere else.
     */
    public function test_a_404_is_an_absence_for_a_call_that_names_one_document(): void
    {
        self::assertNull($this->clientFailing(404)->get('articles', 'missing'));
        self::assertFalse($this->clientFailing(404)->delete('articles', 'missing'));
    }

    public function test_a_404_answering_a_search_is_a_failure(): void
    {
        $this->expectException(SearchRequestException::class);
        $this->clientFailing(404)->search('missing', []);
    }

    public function test_another_status_answering_a_document_call_stays_a_failure(): void
    {
        try {
            $this->clientFailing(409)->delete('articles', '1');
            self::fail('the conflict should have been reported');
        } catch (SearchRequestException $e) {
            self::assertSame(409, $e->status);
        }
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function clientAnswering(array $answer): EngineHalf
    {
        return new EngineHalf(function (SearchCall $call, array $params) use ($answer): array {
            $this->sent[] = ['call' => $call, 'params' => $params];

            return $answer;
        });
    }

    private function clientFailing(int $status): EngineHalf
    {
        return new EngineHalf(static fn (): array => throw SearchRequestException::status(
            $status,
            new RuntimeException('the engine client'),
        ));
    }
}
