<?php

declare(strict_types=1);

namespace Kinetis\Neuron;

/**
 * Takes the reasoning a model writes inline out of its answer: what it
 * writes between `<thinking>` and `</thinking>` (Amazon Nova, Claude 3) or
 * `<think>` and `</think>` (DeepSeek R1, Qwen3, QwQ), case-insensitive and
 * without attributes, is reasoning, never part of the answer. A block is
 * closed only by its own closing tag.
 *
 * Fed an answer's text deltas in order (feed()), it returns the visible
 * part of each: a tag may be split across deltas at any byte, so the end
 * of a delta that could still begin a tag is held back, and released as
 * soon as it can no longer be one; `a < b`, `<b>` and `<thin ice>` pass
 * through unchanged. finish() ends a text part: what is held back is
 * released as text, unless it is inside a block, and a block left open is
 * reasoning to its end. Whitespace a stripped block leaves at the very
 * start of the answer is dropped, so the answer does not open with blank
 * lines. reasoning() hands over the reasoning taken out since it was last
 * asked.
 *
 * One instance per answer: its text parts are one answer, so it is never
 * shared between answers or requests.
 *
 * {@link https://kinetis.dev/docs/neuron.html} states the contract.
 */
final class InlineReasoning
{
    /** The opening tags, each with the tag that closes it. */
    private const array TAGS = ['<thinking>' => '</thinking>', '<think>' => '</think>'];

    /** The tag that closes the block being read, or null outside one. */
    private ?string $closer = null;

    /** The end of the text so far that may still be the start of a tag. */
    private string $held = '';

    /** The reasoning taken out since reasoning() was last asked. */
    private string $reasoning = '';

    /** Whether nothing but whitespace is visible yet. */
    private bool $atStart = true;

    /** Whether a block was taken out before anything was visible. */
    private bool $strippedAtStart = false;

    /** Whitespace at the start of the answer, held until it is known whether a block follows it. */
    private string $leading = '';

    /** The visible part of $delta, the next piece of the answer's text. */
    public function feed(string $delta): string
    {
        $text = $this->held . $delta;
        $this->held = '';
        $visible = '';
        $at = 0;
        $length = strlen($text);

        while ($at < $length) {
            if ($this->closer !== null) {
                $end = stripos($text, $this->closer, $at);

                if ($end === false) {
                    // the end may be the start of the closing tag: held back, the rest is reasoning
                    $keep = self::prefixAtEnd($text, $at, [$this->closer]);
                    $this->reasoning .= substr($text, $at, $length - $at - $keep);
                    $this->held = substr($text, $length - $keep);

                    break;
                }

                $this->reasoning .= substr($text, $at, $end - $at);
                $at = $end + strlen($this->closer);
                $this->closer = null;

                continue;
            }

            $open = strpos($text, '<', $at);

            if ($open === false) {
                $visible .= $this->visible(substr($text, $at));

                break;
            }

            $visible .= $this->visible(substr($text, $at, $open - $at));
            $tag = self::tagAt($text, $open);

            if ($tag !== null) {
                $this->closer = self::TAGS[$tag];
                $this->strippedAtStart = $this->strippedAtStart || $this->atStart;
                $at = $open + strlen($tag);

                continue;
            }

            if (self::prefixAtEnd($text, $open, array_keys(self::TAGS)) === $length - $open) {
                // `<th`: a tag, unless the next delta says otherwise
                $this->held = substr($text, $open);

                break;
            }

            $visible .= $this->visible('<');
            $at = $open + 1;
        }

        return $visible;
    }

    /**
     * Ends a text part: the text held back, now known to be no tag, or ''
     * when a block is still open, which is then reasoning to the end of
     * the part. Whitespace held at the start of the answer is released
     * too, unless a block was taken out before it.
     */
    public function finish(): string
    {
        $held = $this->held;
        $this->held = '';

        if ($this->closer !== null) {
            $this->closer = null;
            $this->reasoning .= $held;

            return '';
        }

        $visible = $this->visible($held);

        if ($this->atStart && !$this->strippedAtStart) {
            // no block came before it: whitespace the model wrote, not one a block left
            $visible = $this->leading;
            $this->leading = '';
        }

        return $visible;
    }

    /** The reasoning taken out since the last call. */
    public function reasoning(): string
    {
        $reasoning = $this->reasoning;
        $this->reasoning = '';

        return $reasoning;
    }

    /**
     * $text as the answer shows it: at its start, whitespace is held until
     * something visible follows, and dropped when a block came before that.
     */
    private function visible(string $text): string
    {
        if (!$this->atStart || $text === '') {
            return $text;
        }

        $trimmed = ltrim($text);

        if ($trimmed === '') {
            $this->leading .= $text;

            return '';
        }

        $this->atStart = false;
        $leading = $this->leading;
        $this->leading = '';

        return $this->strippedAtStart ? $trimmed : $leading . $text;
    }

    /** The opening tag that starts at $at in $text, as TAGS names it, or null. */
    private static function tagAt(string $text, int $at): ?string
    {
        foreach (array_keys(self::TAGS) as $tag) {
            if (substr_compare($text, $tag, $at, strlen($tag), true) === 0) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * How many bytes at the end of $text, from $from on, are the start of
     * one of $tags but not the whole of it: the longest such suffix.
     *
     * @param non-empty-list<string> $tags
     */
    private static function prefixAtEnd(string $text, int $from, array $tags): int
    {
        $length = strlen($text);

        for ($start = max($from, $length - max(array_map(strlen(...), $tags)) + 1); $start < $length; ++$start) {
            if ($text[$start] !== '<') {
                continue;
            }

            $rest = substr($text, $start);

            foreach ($tags as $tag) {
                if (strlen($rest) < strlen($tag) && strncasecmp($rest, $tag, strlen($rest)) === 0) {
                    return $length - $start;
                }
            }
        }

        return 0;
    }
}
