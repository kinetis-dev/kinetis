<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Cluster;

use Amp\Redis\RedisException;
use Error;
use Kinetis\SimpleCache\Cluster\ClusterEndpoint;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ClusterEndpointTest extends TestCase
{
    /**
     * parse() and fromParts() are the only way to get an instance — a
     * private constructor, not a documented-but-unenforced convention,
     * so an invalid ClusterEndpoint can never reach clientFor()/toUri()
     * regardless of how it's constructed. PHP itself refuses the call,
     * from any scope outside the class.
     */
    public function test_direct_construction_is_not_possible(): void
    {
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/private.*ClusterEndpoint::__construct/');

        new ClusterEndpoint('10.0.0.1', 6379);
    }

    public function test_parses_an_ipv4_host_and_port(): void
    {
        $endpoint = ClusterEndpoint::parse('10.0.0.1:6379');

        self::assertSame('10.0.0.1', $endpoint->host);
        self::assertSame(6379, $endpoint->port);
        self::assertSame('tcp://10.0.0.1:6379', $endpoint->toUri());
        self::assertSame('10.0.0.1:6379', $endpoint->key());
    }

    public function test_parses_a_hostname_and_port(): void
    {
        $endpoint = ClusterEndpoint::parse('redis-node-1.internal:6379');

        self::assertSame('redis-node-1.internal', $endpoint->host);
        self::assertSame(6379, $endpoint->port);
        self::assertSame('tcp://redis-node-1.internal:6379', $endpoint->toUri());
    }

    /**
     * The reason this class exists: a bare "host:port" is ambiguous for
     * an IPv6 address, which contains colons itself — bracketing is what
     * disambiguates it. The URI/key both come back correctly bracketed
     * too, not just the parsed host.
     */
    public function test_parses_a_bracketed_ipv6_host_and_port(): void
    {
        $endpoint = ClusterEndpoint::parse('[2001:db8::10]:6379');

        self::assertSame('2001:db8::10', $endpoint->host);
        self::assertSame(6379, $endpoint->port);
        self::assertSame('tcp://[2001:db8::10]:6379', $endpoint->toUri());
        self::assertSame('[2001:db8::10]:6379', $endpoint->key());
    }

    public function test_parses_the_loopback_ipv6_address(): void
    {
        $endpoint = ClusterEndpoint::parse('[::1]:6379');

        self::assertSame('::1', $endpoint->host);
        self::assertSame('tcp://[::1]:6379', $endpoint->toUri());
    }

    /**
     * CLUSTER SHARDS reports a discovered master's ip/port as two
     * already-separate reply fields, so fromParts() never touches
     * parse()'s bracket grammar at all — an IPv6 master address is never
     * round-tripped through a colon-joined string.
     */
    public function test_from_parts_builds_an_ipv6_endpoint_directly(): void
    {
        $endpoint = ClusterEndpoint::fromParts('2001:db8::10', 6379);

        self::assertSame('2001:db8::10', $endpoint->host);
        self::assertSame(6379, $endpoint->port);
        self::assertSame('tcp://[2001:db8::10]:6379', $endpoint->toUri());
    }

    public function test_from_parts_builds_an_ipv4_endpoint_directly(): void
    {
        $endpoint = ClusterEndpoint::fromParts('127.0.0.1', 7000);

        self::assertSame('tcp://127.0.0.1:7000', $endpoint->toUri());
    }

    public function test_rejects_an_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('');
    }

    public function test_rejects_a_host_with_no_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('hostonly');
    }

    /**
     * An unbracketed IPv6 address has no way to say which of its own
     * colons is the port separator — rejected outright rather than
     * guessed at, the exact bug this class exists to close.
     */
    public function test_rejects_an_unbracketed_ipv6_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Redis Cluster endpoint "2001:db8::10:6379"');

        ClusterEndpoint::parse('2001:db8::10:6379');
    }

    public function test_rejects_a_bracketed_host_with_no_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('[2001:db8::10]');
    }

    public function test_rejects_an_empty_bracketed_host(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('[]:6379');
    }

    public function test_rejects_an_unclosed_bracket(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('[2001:db8::10');
    }

    public function test_rejects_a_trailing_colon_with_no_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('host:');
    }

    public function test_rejects_a_non_numeric_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('host:abc');
    }

    public function test_rejects_a_negative_port(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('host:-1');
    }

    public function test_rejects_port_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port 0 is outside the valid 1-65535 range');

        ClusterEndpoint::parse('host:0');
    }

    public function test_rejects_a_port_above_the_valid_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port 65536 is outside the valid 1-65535 range');

        ClusterEndpoint::parse('host:65536');
    }

    public function test_accepts_the_minimum_valid_port(): void
    {
        self::assertSame(1, ClusterEndpoint::parse('host:1')->port);
    }

    public function test_accepts_the_maximum_valid_port(): void
    {
        self::assertSame(65535, ClusterEndpoint::parse('host:65535')->port);
    }

    /**
     * A CLUSTER SHARDS reply carrying a bad port is a server-side
     * protocol problem, not something the application configured wrong
     * — RedisException, not the seed-configuration
     * InvalidArgumentException parse() throws for the same shape of
     * mistake.
     */
    public function test_from_parts_rejects_a_port_outside_the_valid_range(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('CLUSTER SHARDS reported an invalid port 70000 for host "127.0.0.1".');

        ClusterEndpoint::fromParts('127.0.0.1', 70000);
    }

    /**
     * Bracketing is documented as the IPv6 form, but the brackets
     * themselves only establish the delimiter grammar — the enclosed
     * text still has to actually be an IPv6 address. A bracketed
     * non-address is rejected rather than silently accepted as "close
     * enough."
     */
    public function test_rejects_a_bracketed_host_that_is_not_a_real_ipv6_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('[not-ipv6]:6379');
    }

    public function test_rejects_an_unbracketed_host_containing_whitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('bad host:6379');
    }

    /**
     * A URI delimiter inside the host would otherwise reach toUri() and
     * change the meaning of the URI it builds — rejected here, before
     * any URI is ever constructed from it.
     */
    public function test_rejects_an_unbracketed_host_containing_a_uri_delimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('host/path:6379');
    }

    /**
     * A dotted-quad-shaped host that fails strict IPv4 validation must
     * not be silently reclassified as "close enough to a hostname" —
     * confirmed directly that PHP's own FILTER_VALIDATE_DOMAIN would
     * otherwise accept "10.0.0.999" outright, which this class refuses
     * to do.
     */
    public function test_rejects_an_invalid_dotted_ipv4_literal_instead_of_treating_it_as_a_hostname(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusterEndpoint::parse('10.0.0.999:6379');
    }

    /** An ordinary internal DNS name must still work — the dotted-quad guard above must not catch anything that isn't shaped like an IP literal. */
    public function test_accepts_an_ordinary_internal_hostname(): void
    {
        self::assertSame('redis-node-1.internal', ClusterEndpoint::parse('redis-node-1.internal:6379')->host);
    }

    /**
     * A discovered host containing a colon can only honestly mean IPv6
     * — anything else with a colon in it is a malformed reply, mapped
     * to RedisException like every other fromParts() failure.
     */
    public function test_from_parts_rejects_a_colon_containing_host_that_is_not_real_ipv6(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('CLUSTER SHARDS reported an invalid node address "not:ipv6:either".');

        ClusterEndpoint::fromParts('not:ipv6:either', 7000);
    }

    /** The same dotted-quad reclassification guard parse() has, applied to a discovered (not configured) host. */
    public function test_from_parts_rejects_an_invalid_dotted_ipv4_literal(): void
    {
        $this->expectException(RedisException::class);

        ClusterEndpoint::fromParts('10.0.0.999', 7000);
    }

    public function test_from_parts_accepts_an_ordinary_hostname(): void
    {
        self::assertSame('tcp://valid-host:7000', ClusterEndpoint::fromParts('valid-host', 7000)->toUri());
    }
}
