<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use Kinetis\Http\Form\Exception\FormParserConfigurationException;
use Kinetis\Http\Form\FormPairs;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The `&` separator contract, checked against a real `parse_str()`
 * under a real `arg_separator.input` rather than argued about.
 *
 * Every case that matters runs in a subprocess, because that setting is
 * `PHP_INI_PERDIR` — which is also why reading it immediately before the
 * parse is enough: no request can change what the next one parses
 * under.
 */
final class FormPairsTest extends TestCase
{
    public function test_the_setting_cannot_be_moved_from_inside_a_request(): void
    {
        self::assertFalse(@ini_set('arg_separator.input', ';'), 'arg_separator.input is PHP_INI_PERDIR');
        self::assertSame('&', ini_get('arg_separator.input'));
    }

    public function test_a_pair_list_is_parsed_the_way_php_parses_it(): void
    {
        self::assertSame(
            ['name' => 'Url Encoded', 'tags' => ['a', 'b']],
            FormPairs::parse('name=Url+Encoded&tags[]=a&tags[]=b'),
        );
    }

    public function test_the_default_separator_is_the_one_this_framework_owns(): void
    {
        self::assertSame(FormPairs::SEPARATOR, ini_get('arg_separator.input'));
    }

    /**
     * A separator that replaces `&` reshapes the body rather than
     * shortening it: every pair after the first becomes part of the
     * first one's value, so a form the preflight counted as many fields
     * arrives as one whose value is the rest of the request. The first
     * two assertions are that behavior in the same process that then
     * refuses it.
     */
    public function test_a_replaced_separator_is_refused_rather_than_read_as_one_field(): void
    {
        $probe = $this->probe(';', 'name=Alon&role=admin&csrf_token=t');

        self::assertSame(';', $probe['separator']);
        self::assertSame(['name' => 'Alon&role=admin&csrf_token=t'], $probe['parse_str']);

        self::assertNull($probe['parsed'], 'the handler must never receive a form read under another separator');
        self::assertIsString($probe['refused']);
        self::assertStringContainsString('arg_separator.input', $probe['refused'], 'the refusal names the setting an operator can fix');
    }

    /**
     * `arg_separator.input` is a set of characters, so a runtime can
     * keep `&` and add another. That is the direction that hides pairs
     * rather than merging them: `a=1;b=2;…` is one pair to a count taken
     * on `&` and as many as the client likes to the parser, which is how
     * a form walks past `MAX_INPUT_VARS` and is then cut back to this
     * runtime's own `max_input_vars` — with a warning no handler can see
     * and no way afterwards to tell the shortened form from a complete
     * one.
     */
    public function test_an_added_separator_is_refused_rather_than_smuggling_pairs_past_the_count(): void
    {
        $pairs = [];

        for ($i = 0; $i < 12; $i++) {
            $pairs[] = "field{$i}=value";
        }

        $probe = $this->probe('&;', implode(';', $pairs), maxInputVars: 8);

        self::assertSame('&;', $probe['separator']);
        self::assertIsArray($probe['parse_str']);
        self::assertCount(8, $probe['parse_str'], 'parse_str() truncates the twelve pairs this body really carries to eight');

        self::assertNull($probe['parsed'], 'the handler must never receive a form this runtime would have shortened');
        self::assertIsString($probe['refused']);
        self::assertStringContainsString('arg_separator.input', $probe['refused']);
    }

    /**
     * The same body under the same `max_input_vars`, separated the way
     * the contract says. Nothing is smuggled past the raw count, so the
     * refusal is the one that names the runtime setting rather than the
     * separator — the form is still refused whole, never truncated.
     */
    public function test_the_owned_separator_leaves_the_runtime_ceiling_to_do_its_own_job(): void
    {
        $pairs = [];

        for ($i = 0; $i < 12; $i++) {
            $pairs[] = "field{$i}=value";
        }

        $probe = $this->probe('&', implode('&', $pairs), maxInputVars: 8);

        self::assertSame('&', $probe['separator']);
        self::assertNull($probe['refused'], 'the separator is the one this framework owns');
        self::assertNull($probe['parsed'], 'a form this runtime would have shortened is refused before it is parsed');
        self::assertIsString($probe['overLimit']);
        self::assertStringContainsString('max_input_vars', $probe['overLimit'], 'the refusal names the setting an operator can fix');
    }

    /**
     * One process, one `arg_separator.input`, one body.
     *
     * @return array{separator: mixed, parse_str: mixed, parsed: mixed, refused: mixed, overLimit: mixed}
     */
    private function probe(string $separator, string $body, ?int $maxInputVars = null): array
    {
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'arg_separator.input=' . $separator,
                ...($maxInputVars === null ? [] : ['-d', 'max_input_vars=' . $maxInputVars]),
                __DIR__ . '/Fixtures/separator-probe.php',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...getenv(), 'KINETIS_PROBE_BODY' => $body],
        );

        if ($process === false) {
            throw new RuntimeException('Could not start the separator probe.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        /** @var array{separator: mixed, parse_str: mixed, parsed: mixed, refused: mixed, overLimit: mixed} $decoded */
        $decoded = json_decode($stdout, true) ?? self::fail("the separator probe wrote no JSON: {$stdout}{$stderr}");

        return $decoded;
    }

    /**
     * The refusal itself carries what an operator needs, and nothing
     * from the request: a form body is client text, and this message is
     * read by whatever the worker's failure path logs.
     */
    public function test_the_refusal_names_the_setting_and_nothing_from_the_request(): void
    {
        $message = FormParserConfigurationException::unownedInputSeparator(';')->getMessage();

        self::assertStringContainsString('arg_separator.input', $message);
        self::assertStringContainsString('PHP_INI_PERDIR', $message);
        self::assertStringContainsString('";"', $message);
    }
}
