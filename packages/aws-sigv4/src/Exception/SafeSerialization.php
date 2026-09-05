<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use SensitiveParameter;
use UnexpectedValueException;

/**
 * Serialization that carries no secrets, shared by every exception this
 * package throws.
 *
 * PHP's own exception serialization includes the stack trace, and a
 * trace holds the arguments each frame was called with. The arguments
 * this package's frames carry are marked `#[SensitiveParameter]`, which
 * makes them `SensitiveParameterValue` objects — objects PHP refuses to
 * serialize at all, so an untouched exception from here would throw on
 * `serialize()` rather than round-trip. Dropping the trace answers both:
 * the serialized form is the message, code, file, and line.
 *
 * A user of this trait that carries more state overrides `__serialize()`
 * and `__unserialize()` and calls baseState()/restoreBaseState() from
 * inside them. The override belongs on the class that declares the extra
 * state: `unserialize()` skips the constructor, and a `readonly`
 * property may only be initialized from the scope declaring it — see
 * {@see ClientFailureException}.
 */
trait SafeSerialization
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return $this->baseState();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        $this->restoreBaseState($data);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function baseState(): array
    {
        return [
            'message' => $this->message,
            'code' => $this->code,
            'file' => $this->file,
            'line' => $this->line,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    final protected function restoreBaseState(#[SensitiveParameter] array $data): void
    {
        if (!\is_string($data['message'] ?? null) || !\is_int($data['code'] ?? null)) {
            throw new UnexpectedValueException('The serialized payload is not a valid exception state.');
        }

        $this->message = $data['message'];
        $this->code = $data['code'];
        $this->file = \is_string($data['file'] ?? null) ? $data['file'] : '';
        $this->line = \is_int($data['line'] ?? null) ? $data['line'] : 0;
    }
}
