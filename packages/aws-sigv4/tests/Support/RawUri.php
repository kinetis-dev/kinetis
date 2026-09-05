<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 URI that stores and returns exactly what it is given.
 *
 * PSR-7 requires no normalization, and implementations differ: Nyholm
 * percent-encodes a backslash, drops a control character, and rejects a
 * port outside 1–65535 at construction, so a target carrying any of
 * those never reaches the origin check through it. This one carries them
 * through, which is what lets a test exercise the check itself rather
 * than the PSR-7 implementation in front of it.
 *
 * $rendered decouples the string form from the components. PSR-7
 * requires no agreement between the two, and the signer reads the string
 * form, so a URI whose components pass the origin check while its string
 * form does not parse is a shape this package has to survive. Any
 * withX() call drops it: a string form pinned to one set of components
 * says nothing about another.
 */
final class RawUri implements UriInterface
{
    public function __construct(
        private readonly string $scheme = '',
        private readonly string $userInfo = '',
        private readonly string $host = '',
        private readonly ?int $port = null,
        private readonly string $path = '',
        private readonly string $query = '',
        private readonly string $fragment = '',
        private readonly ?string $rendered = null,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $authority = $this->getAuthority();

        return ($this->scheme === '' ? '' : $this->scheme . ':')
            . ($authority === '' ? '' : '//' . $authority)
            . $this->path
            . ($this->query === '' ? '' : '?' . $this->query)
            . ($this->fragment === '' ? '' : '#' . $this->fragment);
    }

    #[\Override]
    public function getScheme(): string
    {
        return $this->scheme;
    }

    #[\Override]
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        return ($this->userInfo === '' ? '' : $this->userInfo . '@')
            . $this->host
            . ($this->port === null ? '' : ':' . $this->port);
    }

    #[\Override]
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    #[\Override]
    public function getHost(): string
    {
        return $this->host;
    }

    #[\Override]
    public function getPort(): ?int
    {
        return $this->port;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }

    #[\Override]
    public function getQuery(): string
    {
        return $this->query;
    }

    #[\Override]
    public function getFragment(): string
    {
        return $this->fragment;
    }

    #[\Override]
    public function withScheme(string $scheme): UriInterface
    {
        return new self(
            $scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $this->path,
            $this->query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $userInfo = $password === null || $password === '' ? $user : $user . ':' . $password;

        return new self(
            $this->scheme,
            $userInfo,
            $this->host,
            $this->port,
            $this->path,
            $this->query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withHost(string $host): UriInterface
    {
        return new self(
            $this->scheme,
            $this->userInfo,
            $host,
            $this->port,
            $this->path,
            $this->query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withPort(?int $port): UriInterface
    {
        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $port,
            $this->path,
            $this->query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withPath(string $path): UriInterface
    {
        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $path,
            $this->query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withQuery(string $query): UriInterface
    {
        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $this->path,
            $query,
            $this->fragment,
        );
    }

    #[\Override]
    public function withFragment(string $fragment): UriInterface
    {
        return new self(
            $this->scheme,
            $this->userInfo,
            $this->host,
            $this->port,
            $this->path,
            $this->query,
            $fragment,
        );
    }
}
