<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use InvalidArgumentException;
use Kinetis\Validation\Violation;
use RuntimeException;

/**
 * The transport-neutral failure every validated input source raises: a
 * request body field, a #[Query]/path value, an MCP tool argument, or
 * an application service that validated something hydration cannot see.
 *
 * It carries an ordered, non-empty list of {@see Violation} — the order
 * fields were checked in — and nothing about how any transport should
 * present them. HTTP rendering belongs to
 * `Kinetis\Http\ValidationExceptionRendererInterface`, which the
 * terminal `Kinetis\Http\Middleware\ExceptionHandlerMiddleware` resolves;
 * `Kinetis\Mcp\McpServer` renders its own tool-result envelope. Both
 * reach the same violations and serialize them the same way.
 *
 * Empty is not a failure, so it is refused at construction rather than
 * producing an exception that claims a failure it cannot describe.
 */
final class ValidationException extends RuntimeException
{
    /**
     * The dotted key {@see grouped()} gives a violation whose path is
     * empty. `$` is JSONPath's root, and no DTO field, query key or
     * path parameter can be spelled that way.
     */
    private const string ROOT_PATH = '$';

    /**
     * @param non-empty-list<Violation> $violations
     */
    private function __construct(
        public readonly array $violations,
    ) {
        parent::__construct('Validation failed.');
    }

    /**
     * @param list<Violation> $violations
     * @throws InvalidArgumentException for an empty list, a non-list
     *         array, or an element that is not a Violation
     */
    public static function fromViolations(array $violations): self
    {
        if ($violations === [] || !array_is_list($violations)) {
            throw new InvalidArgumentException('A validation failure needs a non-empty list of violations.');
        }

        foreach ($violations as $violation) {
            if (!$violation instanceof Violation) {
                throw new InvalidArgumentException(
                    'A validation failure holds Violation instances, ' . get_debug_type($violation) . ' given.'
                );
            }
        }

        return new self($violations);
    }

    /**
     * Messages grouped under a dotted `field`/`field.nested`/`field.index`
     * key, in the order they were raised — a convenience for a custom
     * renderer painting an HTML form, where one flat message list per
     * field name is exactly what the markup needs.
     *
     * Not the wire shape: the default HTTP renderer and MCP both
     * serialize `$violations` directly. It is lossy by construction —
     * codes, parameters, and the boundary between a dot inside a member
     * name and a path separator do not survive it — so a renderer
     * needing any of those reads `$violations` instead.
     *
     * A violation addressing the payload as a whole has no segments and
     * is keyed `$`, the JSONPath root: joining no segments produces no
     * text at all, and an empty map key is not a field name any form
     * consumer can act on.
     *
     * @return array<string, list<string>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->violations as $violation) {
            $key = $violation->path === [] ? self::ROOT_PATH : implode('.', $violation->path);
            $grouped[$key][] = $violation->message;
        }

        return $grouped;
    }
}
