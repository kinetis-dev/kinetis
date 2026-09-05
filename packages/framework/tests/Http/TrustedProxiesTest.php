<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Config\Config;
use Kinetis\Http\Exception\InvalidTrustedProxyException;
use Kinetis\Http\Exception\UntrustedForwardedHeaderException;
use Kinetis\Http\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who is allowed to speak for a client. The default answer is nobody,
 * which is what makes a forwarded header safe to ignore on a directly
 * reachable listener.
 */
final class TrustedProxiesTest extends TestCase
{
    public function test_an_unconfigured_policy_trusts_nobody(): void
    {
        $proxies = TrustedProxies::fromConfig(new Config([]));

        self::assertTrue($proxies->trustsNobody());
        self::assertFalse($proxies->trusts('127.0.0.1'));
        self::assertNull($proxies->forwardedScheme('127.0.0.1', 'https'));
    }

    public function test_a_blank_configuration_is_the_same_as_none(): void
    {
        self::assertTrue(TrustedProxies::fromConfig(new Config(['TRUSTED_PROXIES' => '  ']))->trustsNobody());
    }

    public function test_a_configured_list_is_parsed_and_whitespace_is_ignored(): void
    {
        $proxies = TrustedProxies::fromConfig(new Config(['TRUSTED_PROXIES' => ' 10.0.0.0/8 , 192.168.1.5 ']));

        self::assertTrue($proxies->trusts('10.4.3.2'));
        self::assertTrue($proxies->trusts('192.168.1.5'));
        self::assertFalse($proxies->trusts('192.168.1.6'));
        self::assertFalse($proxies->trusts('11.0.0.1'));
    }

    public function test_an_ipv6_range_matches_the_same_way_an_ipv4_one_does(): void
    {
        $proxies = TrustedProxies::fromList(['2001:db8::/32']);

        self::assertTrue($proxies->trusts('2001:db8:1234::1'));
        self::assertFalse($proxies->trusts('2001:db9::1'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unusableRanges(): iterable
    {
        yield 'not an address' => ['not-an-address'];
        yield 'a subnet that is not an address' => ['nope/24'];
        yield 'a non-numeric prefix' => ['10.0.0.0/eight'];
        yield 'a prefix past the family width' => ['10.0.0.0/33'];
    }

    /**
     * A range decides who may rewrite a request's identity, so an entry
     * this framework cannot read is refused at construction. An unusable
     * range that silently never matched would look, from the outside,
     * exactly like a correct one that is never reached.
     */
    #[DataProvider('unusableRanges')]
    public function test_an_unusable_range_is_refused_at_construction(string $range): void
    {
        $this->expectException(InvalidTrustedProxyException::class);

        TrustedProxies::fromList([$range]);
    }

    /**
     * One IPv6 address has many spellings, and a peer arrives spelled
     * however whatever is in front of it spells it — which is not how an
     * operator wrote `TRUSTED_PROXIES`. Compared as text, the expanded
     * form of the configured edge is a different host, so the edge is
     * disbelieved: the scheme falls back to the listener's own and the
     * client behind it is invisible. Compared as `inet_pton()` bytes, it
     * is the address it is.
     */
    public function test_an_exact_ipv6_address_is_matched_by_what_it_is_not_how_it_is_spelled(): void
    {
        $proxies = TrustedProxies::fromList(['2001:db8::1']);

        self::assertTrue($proxies->trusts('2001:0db8:0000:0000:0000:0000:0000:0001'));
        self::assertTrue($proxies->trusts('2001:DB8::1'), 'hex digits are case-insensitive');
        self::assertTrue($proxies->trusts('2001:db8:0:0::1'));
        self::assertFalse($proxies->trusts('2001:db8::2'));

        // And from the other side: a policy written out in full trusts
        // the compressed peer a proxy actually connects as.
        self::assertTrue(TrustedProxies::fromList(['2001:0db8:0000:0000:0000:0000:0000:0001'])->trusts('2001:db8::1'));
    }

    public function test_an_exact_ipv4_address_is_matched_the_same_way(): void
    {
        $proxies = TrustedProxies::fromList(['192.168.1.5']);

        self::assertTrue($proxies->trusts('192.168.1.5'));
        self::assertFalse($proxies->trusts('192.168.1.6'));
        self::assertFalse($proxies->trusts('::ffff:192.168.1.5'), 'an IPv4-mapped IPv6 peer is not the IPv4 address an operator named');
        self::assertFalse($proxies->trusts('not-an-address'));
    }

    /**
     * The scheme an edge names decides every absolute URL, whether a
     * `Secure` cookie is set and where an OAuth redirect points, so an
     * edge that stops being recognised over a spelling costs exactly
     * that.
     */
    public function test_a_forwarded_scheme_is_read_from_an_edge_written_in_another_spelling(): void
    {
        $proxies = TrustedProxies::fromList(['2001:db8::1']);

        self::assertSame('https', $proxies->forwardedScheme('2001:0db8:0000:0000:0000:0000:0000:0001', 'https'));
        self::assertNull($proxies->forwardedScheme('2001:db8::2', 'https'), 'another address is still another address');
    }

    /**
     * And the chain walk, which is what a rate-limit bucket is keyed on:
     * a hop that is the configured edge under another spelling has to be
     * stepped over, or every request from behind that proxy keys one
     * shared bucket named for the proxy.
     */
    public function test_a_forwarded_chain_steps_over_an_edge_written_in_another_spelling(): void
    {
        $proxies = TrustedProxies::fromList(['2001:db8::1']);

        self::assertSame(
            '203.0.113.9',
            $proxies->clientAddress('2001:0DB8::1', '203.0.113.9, 2001:0db8:0000:0000:0000:0000:0000:0001'),
        );
    }

    public function test_a_forwarded_scheme_is_read_only_from_a_trusted_peer(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.1']);

        self::assertSame('https', $proxies->forwardedScheme('10.0.0.1', 'https'));
        self::assertSame('http', $proxies->forwardedScheme('10.0.0.1', 'HTTP'));
        self::assertNull($proxies->forwardedScheme('203.0.113.9', 'https'), 'a client cannot speak for itself');
        self::assertNull($proxies->forwardedScheme(null, 'https'), 'a request with no peer address has no edge either');
        self::assertNull($proxies->forwardedScheme('10.0.0.1', ''), 'an absent header decides nothing');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unreadableSchemes(): iterable
    {
        yield 'a scheme that is neither' => ['gopher'];
        yield 'two schemes folded together' => ['https, http'];
        yield 'the same scheme twice' => ['https, https'];
        yield 'a trailing empty entry' => ['https,'];
    }

    /**
     * There is no rule that picks the right answer out of two — the
     * first entry is the client's hop under one convention and the last
     * under another — so a trusted proxy sending several has to be
     * fixed, not guessed at.
     */
    #[DataProvider('unreadableSchemes')]
    public function test_an_unreadable_scheme_from_a_trusted_peer_throws(string $value): void
    {
        $this->expectException(UntrustedForwardedHeaderException::class);

        TrustedProxies::fromList(['10.0.0.1'])->forwardedScheme('10.0.0.1', $value);
    }

    public function test_an_unreadable_scheme_from_an_untrusted_peer_is_simply_ignored(): void
    {
        // Refusing here would let any client that can reach the listener
        // turn its own header into an error response.
        self::assertNull(TrustedProxies::fromList(['10.0.0.1'])->forwardedScheme('203.0.113.9', 'gopher'));
    }

    public function test_the_client_address_is_walked_back_through_trusted_hops_only(): void
    {
        $proxies = TrustedProxies::fromList(['10.0.0.0/8']);

        self::assertSame('203.0.113.9', $proxies->clientAddress('10.0.0.1', '203.0.113.9, 10.0.0.5'));
        self::assertSame('10.0.0.1', $proxies->clientAddress('10.0.0.1', ''), 'no chain means the peer itself');
        self::assertSame('198.51.100.7', $proxies->clientAddress('198.51.100.7', '203.0.113.9'), 'an untrusted peer speaks only for itself');
        self::assertSame('10.0.0.9', $proxies->clientAddress('10.0.0.1', '10.0.0.9, 10.0.0.5'), 'a chain of only proxies falls back to its oldest entry');
    }
}
