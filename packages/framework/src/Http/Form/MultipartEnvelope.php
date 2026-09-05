<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Kinetis\Http\Form\Exception\UnparseableFormBodyException;
use Kinetis\Http\MediaType;

/**
 * The `multipart/form-data` contract every Kinetis runtime holds a body
 * to, and the bounded scan that enforces it: where each delimiter is,
 * what each part's header lines say, and where each part's bytes start
 * and end — with {@see FormLimits}' structural ceilings applied while
 * the scan runs, before anything materializes a part.
 *
 * **Why the framework owns this rather than each parser.** Two things
 * would otherwise differ per runtime. The first is cost: every real
 * multipart parser expands the whole body and reports its shape
 * afterwards — `riverline/multipart-parser`'s `getParts()` builds a
 * `StreamedPart` and a stream for every part before a caller can ask how
 * many there are — so a ceiling checked on that result is checked after
 * the cost it exists to bound has been paid. The scan here runs over
 * bytes already bounded by {@see FormLimits::assertBodyWithinLimit()},
 * allocates nothing per part beyond its own offsets while counting, and
 * refuses at the first part or header line past a ceiling.
 *
 * It also counts the two things a parsed result cannot show:
 *
 * - **Every part, named or not.** A part with no usable
 *   `Content-Disposition` name becomes neither a field nor a file, so a
 *   count taken from `getParsedBody()`/`getUploadedFiles()` cannot see
 *   it — and a body of nothing but unnamed parts would cost a parser
 *   everything while appearing empty.
 * - **Header lines, not header names.** A part repeating one header a
 *   thousand times has one entry in any parser's header map and a
 *   thousand lines on the wire; the second number is the one that
 *   bounds what a parser has to hold.
 *
 * The second is meaning, which is what the rest of this class is. A
 * `multipart/form-data` body is not one language: parsers disagree about
 * what a delimiter is, whether a part's bytes are decoded before they are
 * handed over, and what a `Content-Disposition` parameter says. Kinetis
 * accepts one reading of it, the byte-literal RFC 7578 subset, and
 * refuses everything a second reading exists for — so a form means the
 * same thing under FrankenPHP, Lambda and RoadRunner, whether the parse
 * that follows is {@see MultipartFormBuilder}'s or a satellite's own
 * `riverline/multipart-parser`:
 *
 * - **The root `Content-Type` names exactly one boundary.** Its
 *   parameter section is read whole, under the same grammar a part's
 *   own headers are held to, and a section that does not parse as a
 *   complete list of distinct parameters is refused: `boundary=A;
 *   boundary=B` is the first boundary to one parser and the second to
 *   another, and `boundary="A"junk` is `A` to one and `Ajunk` or a
 *   failure to the next. Either way the body has two shapes, and which
 *   one a handler sees would be the runtime's choice.
 * - **A delimiter is `CRLF--boundary` followed by CRLF, or by `--` and
 *   then CRLF or the end of the body.** Nothing else is one. A line
 *   whose boundary token is only a prefix (`--boundaryX`), or that
 *   carries transport padding before its CRLF, is part of the payload —
 *   preserved byte for byte, not a split point and not an error. A line
 *   that a parser splitting on `\n` would take as a delimiter while this
 *   one does not — a boundary after a bare LF, a stray CR before the
 *   CRLF — is refused outright: the two readings are two different
 *   forms.
 * - **A part's bytes are the bytes on the wire.**
 *   `Content-Transfer-Encoding` may only be `7bit` or `binary`, the two
 *   spellings that decode to themselves. `base64`, `quoted-printable`
 *   and `8bit` all send a parser that implements them down a decoding or
 *   charset-conversion path a parser that doesn't will never take, and
 *   RFC 7578 §4.7 does not use the header at all.
 * - **A part's metadata is the text on the wire.** No RFC 2047 encoded
 *   words, no RFC 5987 `name*=`/`filename*=` extended parameters, no
 *   escapes inside a quoted value — each of them is decoded by one
 *   parser and passed through by another, and the extended form decodes
 *   through `mb_convert_encoding()`, where a charset the client invented
 *   raises a `ValueError` from the middle of a parse.
 * - **A part is not itself a multipart body.** Nesting is a second
 *   envelope no ceiling here counted; RFC 7578 §4.3 settles multiple
 *   files as repeated parts under one name.
 * - **A part's header lines are ordinary, complete header lines.** No
 *   obs-fold continuation, no line without a name, no control
 *   characters, and at most one each of `Content-Disposition`,
 *   `Content-Type` and `Content-Transfer-Encoding` — a repeat is
 *   last-wins to one parser, first-wins to another, and neither to a
 *   third.
 *
 * {@see parts()} returns the same scan's results, so the SAPI bridge
 * parses a body from exactly what was counted and checked. A satellite
 * that hands the body to its own parser calls {@see assertWithinLimits()}
 * first, over the same bytes: by the time its parser runs, every input
 * the two of them would have read differently has already been refused.
 */
final class MultipartEnvelope
{
    /**
     * A part's header block ends at the first blank line. A part with no
     * blank line at all is a truncated envelope, not a part with no
     * headers.
     */
    private const string HEADER_TERMINATOR = "\r\n\r\n";

    /** The headers a part's meaning is read from, and so may carry only once. */
    private const array SINGULAR_HEADERS = ['content-disposition', 'content-type', 'content-transfer-encoding'];

    /** The two `Content-Transfer-Encoding` values that decode to the bytes they were given. */
    private const array IDENTITY_ENCODINGS = ['7bit', 'binary'];

    /**
     * Counts and checks the envelope without keeping any of it — the
     * preflight for a parser that will expand the body itself.
     */
    public static function assertWithinLimits(string $body, string $contentType, FormLimits $limits): void
    {
        self::scan($body, $contentType, $limits, collect: false);
    }

    /**
     * The parts, in arrival order, from the same scan.
     *
     * @return list<MultipartPart>
     */
    public static function parts(string $body, string $contentType, FormLimits $limits): array
    {
        return self::scan($body, $contentType, $limits, collect: true);
    }

    /**
     * One pass over the body, delimiter to delimiter.
     *
     * The leading CRLF belongs to the delimiter rather than to the
     * preceding part's body (RFC 2046 §5.1.1), which is why the body is
     * scanned with one in front: the first delimiter of a body that
     * starts directly with `--boundary` has none before it. Whatever
     * precedes the first delimiter is the preamble and whatever follows
     * the closing one is the epilogue; both are ignored, as that section
     * requires.
     *
     * @return list<MultipartPart>
     */
    private static function scan(string $body, string $contentType, FormLimits $limits, bool $collect): array
    {
        $boundary = self::boundary($contentType);

        $search = "\r\n" . $body;
        $token = '--' . $boundary;

        $delimiter = self::nextDelimiter($search, $token, 0);

        if ($delimiter === null) {
            throw UnparseableFormBodyException::unreadableMultipart();
        }

        $parts = [];
        $count = 0;

        while (!self::closes($search, $delimiter, $token)) {
            // Past the delimiter's own CRLF: the part's first byte.
            $start = $delimiter + strlen($token) + 4;
            $next = self::nextDelimiter($search, $token, $start);

            if ($next === null) {
                // A body whose last part is never closed. Parsed
                // leniently it yields a plausible, shorter form — the
                // outcome this framework never hands on.
                throw UnparseableFormBodyException::unreadableMultipart();
            }

            $limits->assertMultipartPartCount(++$count);

            $part = self::readPart(substr($search, $start, $next - $start), $limits, $collect);

            if ($part !== null) {
                $parts[] = $part;
            }

            $delimiter = $next;
        }

        if ($count === 0) {
            throw UnparseableFormBodyException::noParts();
        }

        return $parts;
    }

    /**
     * The delimiter the root `Content-Type` names, from a complete parse
     * of its parameter section rather than a search for the first thing
     * that looks like one.
     *
     * A `boundary` is the one header parameter that decides where a
     * body's parts begin and end, so a header carrying two of them, or
     * carrying syntax no parameter grammar covers, has no single answer
     * — and the parsers behind this scan each pick their own. The whole
     * section is therefore held to {@see parameters()}' grammar before
     * any of them sees the header: `boundary` exactly once, spelled in
     * the lowercase every client sends, and nothing unparseable beside
     * it.
     *
     * A header naming no boundary at all is the separate, ordinary case
     * — nothing to split the body at, rather than two ways to split it.
     */
    private static function boundary(string $contentType): string
    {
        $parameters = self::parameters($contentType);

        if ($parameters === null) {
            throw UnparseableFormBodyException::ambiguousMultipartBoundary();
        }

        $boundary = $parameters['boundary'] ?? '';

        if ($boundary === '') {
            throw UnparseableFormBodyException::noMultipartBoundary();
        }

        return $boundary;
    }

    /**
     * The offset of the next complete delimiter line at or after $from —
     * the offset of its leading CRLF — or null when the body holds no
     * further one.
     *
     * Every occurrence of the boundary token is examined rather than
     * only the first: a part's payload may legitimately contain one, and
     * the payload is what it stays. An occurrence that is not a
     * delimiter here but would be one to a parser reading the body line
     * by line ends the scan instead, since a body two parsers split
     * differently has no single meaning to hand on.
     */
    private static function nextDelimiter(string $search, string $token, int $from): ?int
    {
        $offset = $from;

        while (true) {
            $at = strpos($search, $token, $offset);

            if ($at === false) {
                return null;
            }

            if ($at >= 2 && substr($search, $at - 2, 2) === "\r\n" && self::endsDelimiterLine($search, $at + strlen($token))) {
                return $at - 2;
            }

            if (self::readsAsDelimiterLine($search, $at, $token)) {
                throw UnparseableFormBodyException::ambiguousDelimiter();
            }

            $offset = $at + 1;
        }
    }

    /**
     * What may follow the boundary token for the line to be a delimiter
     * at all: the CRLF that ends it, or the closing `--` and then CRLF
     * or the end of the body. Transport padding is not accepted — RFC
     * 2046 allows it and no client sends it, and a padded line is
     * payload to the parsers this contract has to agree with.
     */
    private static function endsDelimiterLine(string $search, int $end): bool
    {
        if (substr($search, $end, 2) === "\r\n") {
            return true;
        }

        if (substr($search, $end, 2) !== '--') {
            return false;
        }

        return strlen($search) === $end + 2 || substr($search, $end + 2, 2) === "\r\n";
    }

    /**
     * Whether a parser splitting the body on `\n` and trimming line
     * endings would read this occurrence as a delimiter. True only for
     * an occurrence this scan already rejected as one, which is exactly
     * what makes it ambiguous.
     */
    private static function readsAsDelimiterLine(string $search, int $at, string $token): bool
    {
        if ($at !== 0 && $search[$at - 1] !== "\n") {
            return false;
        }

        $lineEnd = strpos($search, "\n", $at);
        $end = $lineEnd === false ? strlen($search) : $lineEnd;

        // Back over the line ending the way a line-based parser trims
        // it, in place: a body may carry a great many of these, and
        // copying each line out to trim it is what turns a scan into a
        // cost a client controls.
        while ($end > $at && ($search[$end - 1] === "\r" || $search[$end - 1] === "\n")) {
            $end--;
        }

        $length = $end - $at;

        if ($length === strlen($token)) {
            return substr_compare($search, $token, $at, $length) === 0;
        }

        return $length === strlen($token) + 2 && substr_compare($search, $token . '--', $at, $length) === 0;
    }

    /**
     * Whether the delimiter at $offset is the closing one. The line was
     * already checked by {@see nextDelimiter()}, so `--` right after the
     * token is the whole answer.
     */
    private static function closes(string $search, int $offset, string $token): bool
    {
        return substr($search, $offset + strlen($token) + 2, 2) === '--';
    }

    /**
     * One part's own bytes: header lines up to the first blank line,
     * then everything after it.
     */
    private static function readPart(string $raw, FormLimits $limits, bool $collect): ?MultipartPart
    {
        // A part with no headers at all begins with the blank line
        // itself, so there is no CRLFCRLF to find — the terminator is the
        // whole header section. Legal, and the shape an unnamed padding
        // part takes, which is exactly the one this scan exists to count.
        if (str_starts_with($raw, "\r\n")) {
            $headerBlock = '';
            $body = substr($raw, 2);
        } else {
            $split = strpos($raw, self::HEADER_TERMINATOR);

            if ($split === false) {
                throw UnparseableFormBodyException::unreadableMultipart();
            }

            $headerBlock = substr($raw, 0, $split);
            $body = substr($raw, $split + strlen(self::HEADER_TERMINATOR));
        }

        $headers = [];
        $lines = $headerBlock === '' ? [] : explode("\r\n", $headerBlock);
        $seen = 0;

        foreach ($lines as $line) {
            // Counted before it is read, and every line counts — a
            // repeated name is a repeated line here, which is the number
            // this ceiling exists to bound.
            $limits->assertPartHeaderCount(++$seen);
            $limits->assertPartHeaderLength(strlen($line));

            $headers[] = self::readHeaderLine($line);
        }

        self::assertPartContract($headers);

        $disposition = self::headerValue($headers, 'Content-Disposition');
        $parameters = $disposition === null ? [] : self::dispositionParameters($disposition);

        if (!$collect) {
            return null;
        }

        return new MultipartPart(
            $headers,
            $parameters['name'] ?? null,
            $parameters['filename'] ?? null,
            self::headerValue($headers, 'Content-Type'),
            $body,
        );
    }

    /**
     * One header line, as a name/value pair. A line that is not one —
     * folded onto the previous line, carrying no name, or carrying a
     * control character a log line or a header map could be broken with
     * — ends the parse rather than being repaired into something
     * plausible.
     *
     * @return array{0: string, 1: string}
     */
    private static function readHeaderLine(string $line): array
    {
        if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
            throw UnparseableFormBodyException::unreadableMultipart();
        }

        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $line) === 1) {
            throw UnparseableFormBodyException::unreadableMultipart();
        }

        $colon = strpos($line, ':');

        if ($colon === false || $colon === 0) {
            throw UnparseableFormBodyException::unreadableMultipart();
        }

        return [substr($line, 0, $colon), trim(substr($line, $colon + 1), " \t")];
    }

    /**
     * The rules a part's headers carry beyond being well-formed lines —
     * see this class's own docblock for what each one is protecting, and
     * why it is a refusal rather than a normalization.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function assertPartContract(array $headers): void
    {
        $seen = [];

        foreach ($headers as [$name, $value]) {
            $lowercased = strtolower($name);

            if (in_array($lowercased, self::SINGULAR_HEADERS, true)) {
                if (isset($seen[$lowercased])) {
                    throw UnparseableFormBodyException::unreadableMultipart();
                }

                $seen[$lowercased] = true;
            }

            // An RFC 2047 encoded word, anywhere in any value: one
            // parser decodes it into different text than the wire
            // carries, and the next one hands the wire's own bytes on.
            if (preg_match('/=\?[^?]*\?[BbQq]\?[^?]*\?=/', $value) === 1) {
                throw UnparseableFormBodyException::undecodablePart();
            }
        }

        $encoding = self::headerValue($headers, 'Content-Transfer-Encoding');

        if ($encoding !== null && !in_array(strtolower($encoding), self::IDENTITY_ENCODINGS, true)) {
            throw UnparseableFormBodyException::undecodablePart();
        }

        $contentType = self::headerValue($headers, 'Content-Type');

        if ($contentType !== null && MediaType::isMultipart($contentType)) {
            throw UnparseableFormBodyException::nestedMultipart();
        }
    }

    /**
     * The first value for a header name — first, not last, so a part
     * repeating a header cannot append a second meaning that overrides
     * the one a scanner reading forwards would have taken. Only reached
     * for headers {@see assertPartContract()} has already held to one
     * occurrence, so first and last are the same value.
     *
     * @param list<array{0: string, 1: string}> $headers
     */
    private static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as [$headerName, $value]) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A `Content-Disposition`'s parameters, as the wire spells them.
     *
     * What survives — `form-data; name="user[address][city]";
     * filename="café.txt"` — is what a browser sends, returned exactly as
     * sent: {@see FormFieldName} is what makes a name safe to register,
     * and nothing here interprets a filename at all. A header outside
     * the grammar is a part two parsers read differently, which is a
     * part this framework does not decode.
     *
     * @return array<string, string>
     */
    private static function dispositionParameters(string $disposition): array
    {
        $parameters = self::parameters($disposition);

        if ($parameters === null) {
            throw UnparseableFormBodyException::undecodablePart();
        }

        return $parameters;
    }

    /**
     * The parameters after a header value's media type or disposition
     * type, or null when the section is not spelled in the one grammar
     * this framework accepts — the same grammar for the root
     * `Content-Type` and for a part's own headers, so one body cannot be
     * held to two.
     *
     * That grammar is narrower than RFC 9110's on purpose, and each
     * narrowing is a place two parsers read one header differently: a
     * name spelled in any case but lowercase, a parameter given twice,
     * an RFC 5987 `filename*=`, a quoted value carrying an escape, a
     * semicolon or surrounding space, anything trailing a value the
     * grammar has already ended. Refused rather than resolved, since
     * whichever reading this class picked would be the other parser's
     * bug. An empty segment carries no parameter and means the same
     * thing to every parser, so it is passed over rather than refused.
     *
     * @return array<string, string>|null
     */
    private static function parameters(string $headerValue): ?array
    {
        $parameters = [];
        $segments = explode(';', $headerValue);
        array_shift($segments);

        foreach ($segments as $segment) {
            $segment = trim($segment, " \t");

            if ($segment === '') {
                continue;
            }

            // name=token or name="text", anchored at both ends: a
            // parameter name in the lowercase every client spells it in,
            // and a quoted value holding no quote, backslash, semicolon
            // or control character — the four a parser splitting on `;`
            // and stripping quotes reads differently from one that does
            // not.
            $matched = preg_match('/^([a-z0-9!#$%&\'*+.^_`|~-]+)=(?:"([^"\\\\;\x00-\x1F\x7F]*)"|([a-zA-Z0-9!#$%&\'*+.^_`|~-]+))$/', $segment, $captured);

            if ($matched !== 1 || str_ends_with($captured[1], '*')) {
                return null;
            }

            $value = $captured[2] ?? '';

            if (($captured[3] ?? '') !== '') {
                $value = $captured[3];
            }

            if (trim($value, ' ') !== $value) {
                return null;
            }

            if (array_key_exists($captured[1], $parameters)) {
                return null;
            }

            $parameters[$captured[1]] = $value;
        }

        return $parameters;
    }
}
