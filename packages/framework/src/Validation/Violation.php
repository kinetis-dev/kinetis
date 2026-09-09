<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use InvalidArgumentException;
use JsonSerializable;

/**
 * One validation failure: where it happened, which rule it broke, the
 * default-English sentence a client with no rule vocabulary of its own
 * can show, and the values that sentence was built from.
 *
 * The path is a list of segments — `['items', 2, 'quantity']` — never a
 * pre-joined string, so a member name containing a dot and a list index
 * stay unambiguous all the way to the transport edge, and no
 * source-specific spelling (a JSON Pointer, say, which would be false
 * for a query, path, form or multipart value) is baked in. An empty
 * path addresses the payload as a whole.
 *
 * `$code` is the stable machine name a client switches on and a
 * translator keys off; `$message` is the text for a client that shows
 * it as-is; `$parameters` carries the values the message was built
 * from, so a renderer rebuilds or translates the sentence without
 * parsing it. Every parameter value is a scalar, null, or a list of
 * scalars, and every float among them is finite — JSON has no spelling
 * for INF or NAN, so a violation carrying one would encode to nothing a
 * client could read — leaving a JSON encoder nothing to convert and
 * nothing to refuse.
 *
 * jsonSerialize() is that encoding, and both transports share it: the
 * `errors` extension of the default HTTP renderer's RFC 9457 document
 * and the `errors` member of the MCP tool-error envelope are the same
 * ordered list of these objects.
 * {@see Exception\ValidationException::grouped()} is a separate lossy
 * convenience for a form-oriented custom renderer, not the wire shape.
 *
 * Application code constructs violations directly, so each of those
 * rules is checked here rather than trusted: a malformed violation is a
 * programming error, and refusing it at construction reports it where
 * it was built rather than at the transport edge, where the stack no
 * longer names the rule that produced it.
 */
final readonly class Violation implements JsonSerializable
{
    /**
     * @param list<string|int> $path
     * @param array<string, scalar|list<scalar>|null> $parameters
     * @throws InvalidArgumentException for a path that is not a list of
     *         string/int segments, an empty code or message, a parameter
     *         outside the scalar/list-of-scalars/null domain, or a
     *         non-finite float anywhere among the parameters
     */
    public function __construct(
        public array $path,
        public string $code,
        public string $message,
        public array $parameters = [],
    ) {
        self::assertPath($path);

        if ($code === '') {
            throw new InvalidArgumentException('A violation code must not be empty.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('A violation message must not be empty.');
        }

        self::assertParameters($parameters);
    }

    /**
     * The same violation one level deeper: `$segments` are prepended in
     * order, so a nested DTO's own `['quantity']` becomes
     * `['items', 2, 'quantity']` under its list element. The only
     * operation nesting needs — the code, message and parameters
     * describe the failure itself, never the route taken to it.
     */
    public function under(string|int ...$segments): self
    {
        // Both operands are lists, so their merge is one; the native
        // `array` property type is all an analyzer reading this back
        // has to go on.
        /** @var list<string|int> $path */
        $path = array_merge($segments, $this->path);

        return new self($path, $this->code, $this->message, $this->parameters);
    }

    /**
     * The one wire form, shared by the RFC 9457 `errors` extension and
     * the MCP tool-error envelope. `parameters` is cast to an object so
     * an empty set encodes as `{}`: it is a map in both documents, and
     * PHP's empty array would otherwise encode as `[]` and change the
     * member's JSON type with its contents.
     *
     * @return array{path: list<string|int>, code: string, message: string, parameters: object}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'path' => $this->path,
            'code' => $this->code,
            'message' => $this->message,
            'parameters' => (object) $this->parameters,
        ];
    }

    /**
     * @param list<string|int> $path
     */
    private static function assertPath(array $path): void
    {
        if (!array_is_list($path)) {
            throw new InvalidArgumentException('A violation path must be a list of segments.');
        }

        foreach ($path as $segment) {
            if (!is_string($segment) && !is_int($segment)) {
                throw new InvalidArgumentException(
                    'A violation path segment must be a string or an int, ' . get_debug_type($segment) . ' given.'
                );
            }
        }
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $parameters
     */
    private static function assertParameters(array $parameters): void
    {
        foreach ($parameters as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('A violation parameter name must be a string.');
            }

            if ($value === null || is_scalar($value)) {
                self::assertFinite($name, $value);

                continue;
            }

            if (!is_array($value) || !array_is_list($value)) {
                throw new InvalidArgumentException(
                    "Violation parameter \"{$name}\" must be a scalar, null, or a list of scalars, "
                    . get_debug_type($value) . ' given.'
                );
            }

            foreach ($value as $element) {
                if (!is_scalar($element)) {
                    throw new InvalidArgumentException(
                        "Violation parameter \"{$name}\" must hold only scalars, "
                        . get_debug_type($element) . ' given.'
                    );
                }

                self::assertFinite($name, $element);
            }
        }
    }

    /**
     * `scalar` admits INF and NAN, and JSON spells neither: an encoder
     * either refuses the whole document or substitutes a value the
     * client never sent. Refusing them here keeps every violation
     * encodable, which is what the two transports rely on.
     */
    private static function assertFinite(string $name, mixed $value): void
    {
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException(
                "Violation parameter \"{$name}\" must hold only finite floats, " . var_export($value, true) . ' given.'
            );
        }
    }
}
