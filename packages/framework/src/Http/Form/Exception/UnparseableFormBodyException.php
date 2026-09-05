<?php

declare(strict_types=1);

namespace Kinetis\Http\Form\Exception;

use RuntimeException;

/**
 * The client sent something that does not decode as the form it
 * declared itself to be. Answered on every adapter with a `400` and the
 * fixed {@see \Kinetis\Runtime\RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE}.
 *
 * **Every message here is a fixed sentence chosen from the list below,
 * and nothing else ever reaches one.** A parser's own message is the
 * obvious thing to attach and the one thing that must not be: it is
 * assembled from the input that failed, so it quotes header names, part
 * names, charset labels and body fragments a client chose. That text
 * would then travel into every log line this exception produces, and a
 * log is read, searched, shipped and rendered somewhere. There is no
 * `previous` chain either — an attached cause carries the same text one
 * `getPrevious()` away, and anything that walks a chain or serializes an
 * exception would print it.
 *
 * What is lost is which byte offset upset which parser, which no client
 * is owed and no operator can act on; what is kept is the category, which
 * is what an operator actually triages on. The adapters log
 * {@see $category} and nothing more.
 *
 * The one vocabulary every adapter's parse failure is expressed in,
 * whether the parsing was this framework's own or a satellite's — so
 * "unparseable" means the same thing, and reaches a client the same way,
 * under every runtime. Distinct on both sides:
 * {@see FormLimitExceededException} is input this framework understood
 * and refused (`413`), and {@see FormStagingException} is this worker
 * failing rather than the client.
 */
final class UnparseableFormBodyException extends RuntimeException
{
    /**
     * A short, fixed classification — the only thing an adapter logs for
     * a parse failure. Every value it can take is written in this class.
     */
    public readonly string $category;

    private function __construct(string $category, string $message)
    {
        // No $previous, ever: see the class docblock.
        parent::__construct($message);

        $this->category = $category;
    }

    /**
     * `multipart/form-data` without a `boundary` parameter names no
     * delimiter, so there is nothing to split the body on — not a body
     * that failed to parse but one that cannot be attempted.
     */
    public static function noMultipartBoundary(): self
    {
        return new self('no-boundary', 'The multipart/form-data content type carries no boundary parameter.');
    }

    /**
     * A `multipart/form-data` content type whose parameter section names
     * the boundary twice, or carries syntax the one parameter grammar
     * this framework reads does not cover — `boundary=A; boundary=B`,
     * `boundary="A"junk`. Each of them is a different delimiter to a
     * different parser, so the body has as many shapes as there are
     * runtimes to land on. See
     * {@see \Kinetis\Http\Form\MultipartEnvelope} for the grammar
     * itself.
     */
    public static function ambiguousMultipartBoundary(): self
    {
        return new self('ambiguous-boundary', 'The multipart/form-data content type does not name exactly one boundary.');
    }

    /**
     * A `multipart/form-data` body that yielded no part at all. Refused
     * rather than passed on as an empty form: an empty form is a
     * plausible thing for a handler to act on, and this one is the result
     * of a body that did not decode.
     */
    public static function noParts(): self
    {
        return new self('no-parts', 'The multipart/form-data body decoded to no parts at all.');
    }

    /**
     * The envelope itself did not read as multipart: a delimiter that
     * appears nowhere, a part whose headers never end, a header line
     * with no name, a body whose last part is never closed.
     */
    public static function unreadableMultipart(): self
    {
        return new self('unreadable-multipart', 'The multipart/form-data body could not be read as multipart.');
    }

    /**
     * A part whose bytes or metadata are not the literal ones on the
     * wire: a `Content-Transfer-Encoding` other than `7bit` or `binary`,
     * an RFC 2047 encoded word, an RFC 5987 extended parameter, a quoted
     * parameter carrying an escape. Every one of them is a re-encoding
     * some parser in this framework's supported set performs and another
     * does not, so the part means two different things depending on the
     * runtime it lands on — refused on all of them instead. See
     * {@see \Kinetis\Http\Form\MultipartEnvelope} for the contract this
     * belongs to.
     */
    public static function undecodablePart(): self
    {
        return new self('undecodable-part', 'A multipart/form-data part declares an encoding this framework does not decode.');
    }

    /**
     * A part carrying a `multipart/*` body of its own. RFC 7578 §4.3
     * settles multiple files as repeated parts under one name, not as a
     * nested `multipart/mixed`, and a nested body is a second envelope
     * no ceiling counted: the parts inside it are the outer part's bytes
     * to the scan and a whole further form to a parser that recurses.
     */
    public static function nestedMultipart(): self
    {
        return new self('nested-multipart', 'A multipart/form-data part carries a nested multipart body.');
    }

    /**
     * A line that reads as a delimiter to a parser splitting on lines
     * and not to one matching CRLF delimiters — a boundary after a bare
     * LF, or one followed by a stray CR. It decides where a part ends,
     * so the two readings are two different forms, and the body is
     * refused rather than resolved in favor of whichever parser this
     * runtime happens to use.
     */
    public static function ambiguousDelimiter(): self
    {
        return new self('ambiguous-delimiter', 'The multipart/form-data body carries a boundary line that is not a complete delimiter.');
    }
}
