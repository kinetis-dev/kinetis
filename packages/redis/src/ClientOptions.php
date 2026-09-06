<?php

declare(strict_types=1);

namespace Kinetis\Redis;

use Amp\Socket\ClientTlsContext;
use InvalidArgumentException;

/**
 * Immutable connection settings shared by every node a client reaches.
 * A cluster authenticates and encrypts identically on every node, so
 * one instance is propagated to seeds, discovered masters, and redirect
 * targets alike.
 *
 * $timeout is the whole per-operation budget in seconds, not a connect
 * timeout: see {@see Deadline}.
 *
 * The password is held for the lifetime of the client and reaches the
 * wire only as the AUTH frame's argument. No exception this package
 * throws carries it, and Endpoint::toUri() never builds a URI holding
 * it.
 */
final class ClientOptions
{
    public function __construct(
        public readonly float $timeout = 5.0,
        #[\SensitiveParameter] public readonly ?string $password = null,
        public readonly int $database = 0,
        public readonly ?ClientTlsContext $tls = null,
    ) {
        if ($timeout <= 0.0) {
            throw new InvalidArgumentException("The Redis operation budget must be positive, got {$timeout}.");
        }

        if ($database < 0) {
            throw new InvalidArgumentException("The Redis database index must not be negative, got {$database}.");
        }
    }

    public function withTimeout(float $seconds): self
    {
        return new self($seconds, $this->password, $this->database, $this->tls);
    }

    public function withPassword(#[\SensitiveParameter] ?string $password): self
    {
        return new self($this->timeout, $password, $this->database, $this->tls);
    }

    public function withDatabase(int $database): self
    {
        return new self($this->timeout, $this->password, $database, $this->tls);
    }

    public function withTls(?ClientTlsContext $tls): self
    {
        return new self($this->timeout, $this->password, $this->database, $tls);
    }

    public function deadline(): Deadline
    {
        return Deadline::in($this->timeout);
    }
}
