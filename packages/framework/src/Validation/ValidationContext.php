<?php

declare(strict_types=1);

namespace Kinetis\Validation;

/**
 * What an {@see ObjectConstraint} is told about the input the DTO in
 * front of it was built from: which of the DTO's own constructor fields
 * the input actually supplied.
 *
 * That fact cannot be recovered from the constructed object. A field the
 * client omitted holds its declared default, and a field the client sent
 * with exactly that value holds the same thing — so a rule like
 * {@see ObjectConstraints\AtLeastOneProvided}, which has to answer "did
 * this update say anything at all", needs presence carried alongside the
 * object rather than guessed from it. A `T|Absent` field can answer for
 * itself (see {@see Absent}); an ordinary defaulted field cannot, and
 * this is what makes both readable the same way.
 *
 * Names are the DTO's own constructor parameter names, and only those:
 * an input member matching no parameter is either already a violation
 * (JSON and MCP close their objects) or deliberately ignored (a form
 * body's CSRF and submit fields), and neither is something a rule about
 * this DTO should see.
 *
 * It carries nothing else — no request, no container, no transport, no
 * DTO — and is built fresh for one hydration, so nothing a rule reads
 * here can outlive the call or reach the next request.
 */
final readonly class ValidationContext
{
    /**
     * Keyed for lookup rather than kept as the list it was given: every
     * read is a membership test, and a field name is a PHP identifier,
     * never a numeric string an array key would coerce to int.
     *
     * @var array<string, true>
     */
    private array $suppliedFields;

    /**
     * @param list<string> $suppliedFields the DTO's own constructor
     *        field names the input carried a value for
     */
    public function __construct(array $suppliedFields)
    {
        $this->suppliedFields = array_fill_keys($suppliedFields, true);
    }

    /**
     * Whether the input carried a member for $field. True for an
     * explicitly-null value too: `null` is something the client said,
     * which is exactly the distinction a presence rule exists to make.
     */
    public function wasSupplied(string $field): bool
    {
        return isset($this->suppliedFields[$field]);
    }
}
