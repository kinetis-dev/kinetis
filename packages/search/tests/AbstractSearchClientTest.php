<?php

declare(strict_types=1);

namespace Kinetis\Search\Tests;

use InvalidArgumentException;
use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchCall;
use Kinetis\Search\Tests\Fixtures\EngineHalf;
use Kinetis\Search\WriteCondition;
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

    public function test_a_condition_joins_the_parameters_of_the_call_it_applies_to(): void
    {
        $client = $this->clientAnswering(['result' => 'updated']);

        $client->index('articles', '1', ['n' => 1], condition: WriteCondition::external(7));
        $client->delete('articles', '1', condition: WriteCondition::ifUnchanged(4, 2));

        self::assertSame(
            ['index' => 'articles', 'body' => ['n' => 1], 'id' => '1', 'version' => 7, 'version_type' => 'external'],
            $this->sent[0]['params'],
        );
        self::assertSame(
            ['index' => 'articles', 'id' => '1', 'if_seq_no' => 4, 'if_primary_term' => 2],
            $this->sent[1]['params'],
        );
    }

    /**
     * Both are optional and independent, so a call asking for one must
     * not lose the other.
     */
    public function test_a_condition_and_a_refresh_both_reach_the_call(): void
    {
        $client = $this->clientAnswering(['result' => 'updated']);

        $client->index('articles', '1', ['n' => 1], refresh: true, condition: WriteCondition::externalOrEqual(7));
        $client->delete('articles', '1', refresh: true, condition: WriteCondition::external(8));

        self::assertSame(
            [
                'index' => 'articles',
                'body' => ['n' => 1],
                'id' => '1',
                'refresh' => 'true',
                'version' => 7,
                'version_type' => 'external_gte',
            ],
            $this->sent[0]['params'],
        );
        self::assertSame(
            ['index' => 'articles', 'id' => '1', 'refresh' => 'true', 'version' => 8, 'version_type' => 'external'],
            $this->sent[1]['params'],
        );
    }

    public function test_an_unconditional_call_carries_no_condition_parameter(): void
    {
        $this->clientAnswering(['result' => 'created'])->index('articles', '1', ['n' => 1]);

        self::assertSame(['index' => 'articles', 'body' => ['n' => 1], 'id' => '1'], $this->sent[0]['params']);
    }

    /**
     * A cluster-assigned id is not a document a condition can name, and
     * the refusal happens here rather than as the engine's own 400, so
     * nothing is sent.
     */
    public function test_a_conditional_index_without_an_id_is_refused_before_anything_is_sent(): void
    {
        $client = $this->clientAnswering(['result' => 'created']);

        try {
            $client->index('articles', null, ['n' => 1], condition: WriteCondition::external(7));
            self::fail('the conditional write without an id should have been refused');
        } catch (InvalidArgumentException $e) {
            self::assertSame('A conditional index names the document it writes; $id is null.', $e->getMessage());
        }

        self::assertSame([], $this->sent);
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
