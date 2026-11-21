<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests;

use Kinetis\Neuron\InlineReasoning;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InlineReasoningTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> answer as streamed, visible answer, reasoning */
    public static function answers(): iterable
    {
        yield 'thinking, then the answer' => ['<thinking>Query the products.</thinking>Low: products#3.', 'Low: products#3.', 'Query the products.'];
        yield 'think, then the answer' => ['<think>Query the products.</think>Low: products#3.', 'Low: products#3.', 'Query the products.'];
        yield 'any case' => ['<THINKING>Hm.</Thinking>Done.', 'Done.', 'Hm.'];
        yield 'text before, between and after blocks' => ['Before <thinking>one</thinking>between <think>two</think> after.', 'Before between  after.', 'onetwo'];
        yield 'blank lines a leading block leaves' => ["<thinking>Plan.</thinking>\n\n  Answer.\n", "Answer.\n", 'Plan.'];
        yield 'whitespace before a leading block' => ["\n <thinking>Plan.</thinking>\nAnswer.", 'Answer.', 'Plan.'];
        yield 'leading whitespace with no block' => ["\n  Answer.", "\n  Answer.", ''];
        yield 'only whitespace' => ["\n  ", "\n  ", ''];
        yield 'only whitespace after a block' => ["<think>Plan.</think>\n  ", '', 'Plan.'];
        yield 'blank lines after a block mid-answer' => ["A.<thinking>x</thinking>\n\nB.", "A.\n\nB.", 'x'];
        yield 'a block left open' => ['Answer. <thinking>And then I', 'Answer. ', 'And then I'];
        yield 'only reasoning' => ['<think>Nothing to say.</think>', '', 'Nothing to say.'];
        yield 'a comparison' => ['a < b and c<d', 'a < b and c<d', ''];
        yield 'html' => ['<b>bold</b> <thin ice> <thinkpad> <th', '<b>bold</b> <thin ice> <thinkpad> <th', ''];
        yield 'a tag of another family closes nothing' => ['<think>a</thinking>b</think>c', 'c', 'a</thinking>b'];
        yield 'a closing tag outside a block' => ['x</thinking>y', 'x</thinking>y', ''];
        yield 'an opening tag inside a block' => ['<thinking>a<thinking>b</thinking>c', 'c', 'a<thinking>b'];
        yield 'unicode around it' => ['שלום <think>חשיבה</think>עולם 😀', 'שלום עולם 😀', 'חשיבה'];
    }

    #[DataProvider('answers')]
    public function testAnAnswerInOneChunk(string $streamed, string $visible, string $reasoning): void
    {
        $filter = new InlineReasoning();

        self::assertSame($visible, $filter->feed($streamed) . $filter->finish());
        self::assertSame($reasoning, $filter->reasoning());
        self::assertSame('', $filter->reasoning(), 'reasoning() hands it over once');
    }

    #[DataProvider('answers')]
    public function testAnAnswerSplitInTwoAtEveryPosition(string $streamed, string $visible, string $reasoning): void
    {
        for ($at = 0; $at <= strlen($streamed); ++$at) {
            $filter = new InlineReasoning();

            $out = $filter->feed(substr($streamed, 0, $at)) . $filter->feed(substr($streamed, $at)) . $filter->finish();

            self::assertSame($visible, $out, "split at {$at}");
            self::assertSame($reasoning, $filter->reasoning(), "split at {$at}");
        }
    }

    #[DataProvider('answers')]
    public function testAnAnswerStreamedAByteAtATime(string $streamed, string $visible, string $reasoning): void
    {
        $filter = new InlineReasoning();
        $out = '';
        $taken = '';

        foreach (str_split($streamed) as $byte) {
            $out .= $filter->feed($byte);
            $taken .= $filter->reasoning();
        }

        self::assertSame($visible, $out . $filter->finish());
        self::assertSame($reasoning, $taken . $filter->reasoning());
    }

    public function testWhatCouldStillBeATagIsHeldBackOnlyUntilItCannotBe(): void
    {
        $filter = new InlineReasoning();

        self::assertSame('a ', $filter->feed('a <'));
        self::assertSame('<b', $filter->feed('b'), '`<b` is no tag');
        self::assertSame(' ', $filter->feed(' <thin'));
        self::assertSame('<thin ice', $filter->feed(' ice'));
        self::assertSame(' ', $filter->feed(' <th'));
        self::assertSame('<th', $filter->finish(), 'the end of the stream is no tag');
        self::assertSame('', $filter->reasoning());
    }

    public function testABlockClosedInTheNextChunkTakesOutWhatCameBetween(): void
    {
        $filter = new InlineReasoning();

        self::assertSame('', $filter->feed('<thi'));
        self::assertSame('', $filter->feed('nking>I should '));
        self::assertSame('I should ', $filter->reasoning());
        self::assertSame('', $filter->feed('check.</thin'));
        self::assertSame('Here', $filter->feed('king>Here'));
        self::assertSame('check.', $filter->reasoning());
        self::assertSame(' it is.', $filter->feed(' it is.'));
    }

    public function testEachTextPartEndsItsOwnBlockButTheAnswerStartsOnce(): void
    {
        $filter = new InlineReasoning();

        // a step that only reasons before calling a tool, its block left open
        self::assertSame('', $filter->feed("<thinking>Query them.\n"));
        self::assertSame('', $filter->finish());
        self::assertSame("Query them.\n", $filter->reasoning());

        // the next step's text is outside any block, its leading blank line still dropped
        self::assertSame('Low: products#3.', $filter->feed("\nLow: products#3."));
        self::assertSame('', $filter->finish());
    }

    public function testLeadingWhitespaceIsHeldWithinAPartAndReleasedAtItsEnd(): void
    {
        $filter = new InlineReasoning();

        self::assertSame('', $filter->feed("\n"), 'a block may still follow it');
        self::assertSame("\n", $filter->finish(), 'none did, so it is the model\'s own text');
        self::assertSame('Answer.', $filter->feed('Answer.'), 'released once, not again with the next text');
    }
}
