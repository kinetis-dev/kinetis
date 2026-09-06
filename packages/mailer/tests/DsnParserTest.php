<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests;

use Kinetis\Mailer\Dsn\CompositeDsn;
use Kinetis\Mailer\Dsn\CompositeKind;
use Kinetis\Mailer\Dsn\DsnParser;
use Kinetis\Mailer\Dsn\LeafDsn;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DsnParserTest extends TestCase
{
    private const string KEY = 'MAILER_DSN';

    public function test_a_leaf_is_split_into_the_components_symfony_is_handed(): void
    {
        $leaf = $this->leaf('smtp://alice:s3cr%40t@mail.example.com:587?require_tls=true');

        self::assertSame('smtp', $leaf->scheme);
        self::assertSame('mail.example.com', $leaf->host);
        self::assertSame('alice', $leaf->user);
        self::assertSame('s3cr@t', $leaf->password, 'a percent escape is decoded exactly once');
        self::assertSame(587, $leaf->port);
        self::assertSame(['require_tls' => 'true'], $leaf->options);
    }

    public function test_a_credential_free_leaf_reports_null_rather_than_an_empty_string(): void
    {
        $leaf = $this->leaf('smtp://127.0.0.1:1025');

        self::assertNull($leaf->user);
        self::assertNull($leaf->password);
        self::assertSame(1025, $leaf->port);
        self::assertSame([], $leaf->options);
    }

    public function test_a_bracketed_ipv6_host_keeps_its_brackets_and_still_finds_its_port(): void
    {
        $leaf = $this->leaf('smtp://[::1]:1025');

        self::assertSame('[::1]', $leaf->host);
        self::assertSame(1025, $leaf->port);
        self::assertTrue($leaf->hasLoopbackHost());
    }

    public function test_an_address_literal_host_is_told_apart_from_a_name(): void
    {
        self::assertTrue($this->leaf('smtp://[2001:db8::1]:587')->isAddressLiteral());
        self::assertTrue($this->leaf('smtp://198.51.100.7:587')->isAddressLiteral());
        self::assertFalse($this->leaf('smtp://mail.example.com:587')->isAddressLiteral());
    }

    // --- composite grammar ---------------------------------------------

    public function test_a_composite_holds_its_members_in_order(): void
    {
        $node = DsnParser::parse('failover(null://null roundrobin(null://null null://null))', self::KEY);

        self::assertInstanceOf(CompositeDsn::class, $node);
        self::assertSame(CompositeKind::Failover, $node->kind);
        self::assertCount(2, $node->members);
        self::assertInstanceOf(LeafDsn::class, $node->members[0]);

        $inner = $node->members[1];
        self::assertInstanceOf(CompositeDsn::class, $inner);
        self::assertSame(CompositeKind::RoundRobin, $inner->kind);
        self::assertCount(2, $inner->members);
    }

    public function test_a_one_member_composite_is_accepted(): void
    {
        $node = DsnParser::parse('roundrobin(null://null)', self::KEY);

        self::assertInstanceOf(CompositeDsn::class, $node);
        self::assertCount(1, $node->members);
    }

    public function test_retry_period_defaults_to_symfonys_own_sixty_seconds(): void
    {
        $node = DsnParser::parse('failover(null://null)', self::KEY);

        self::assertInstanceOf(CompositeDsn::class, $node);
        self::assertSame(DsnParser::DEFAULT_RETRY_PERIOD, $node->retryPeriod);
    }

    public function test_retry_period_is_read_off_the_composite_it_follows(): void
    {
        $node = DsnParser::parse('failover(null://null null://null)?retry_period=15', self::KEY);

        self::assertInstanceOf(CompositeDsn::class, $node);
        self::assertSame(15, $node->retryPeriod);
    }

    public function test_a_nested_composite_carries_its_own_retry_period(): void
    {
        $node = DsnParser::parse('failover(roundrobin(null://null)?retry_period=5 null://null)?retry_period=9', self::KEY);

        self::assertInstanceOf(CompositeDsn::class, $node);
        self::assertSame(9, $node->retryPeriod);

        $inner = $node->members[0];
        self::assertInstanceOf(CompositeDsn::class, $inner);
        self::assertSame(5, $inner->retryPeriod);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedComposites(): iterable
    {
        yield 'two spaces between members' => ['failover(null://null  null://null)', MailerConfigurationException::EMPTY_MEMBER];
        yield 'a tab between members' => ["failover(null://null\tnull://null)", MailerConfigurationException::LEAF_CHARSET];
        yield 'a newline between members' => ["failover(null://null\nnull://null)", MailerConfigurationException::LEAF_CHARSET];
        yield 'a leading space' => ['failover( null://null)', MailerConfigurationException::EMPTY_MEMBER];
        yield 'a trailing space' => ['failover(null://null )', MailerConfigurationException::EMPTY_MEMBER];
        yield 'an empty group' => ['failover()', MailerConfigurationException::EMPTY_MEMBER];
        yield 'an unclosed group' => ['failover(null://null', MailerConfigurationException::BAD_COMPOSITE];
        yield 'a stray closing parenthesis' => ['null://null)', MailerConfigurationException::TRAILING_GARBAGE];
        yield 'an unknown keyword' => ['fallback(null://null)', MailerConfigurationException::LEAF_CHARSET];
        yield 'trailing garbage' => ['failover(null://null)junk', MailerConfigurationException::TRAILING_GARBAGE];
        yield 'a comma between members' => ['failover(null://null,null://null)', MailerConfigurationException::LEAF_SHAPE];
        yield 'retry_period out of range' => ['failover(null://null)?retry_period=86401', MailerConfigurationException::BAD_RETRY_PERIOD];
        yield 'retry_period of zero' => ['failover(null://null)?retry_period=0', MailerConfigurationException::BAD_RETRY_PERIOD];
        yield 'retry_period written twice' => ['failover(null://null)?retry_period=5&retry_period=6', MailerConfigurationException::BAD_RETRY_PERIOD];
        yield 'a composite option that is not retry_period' => ['failover(null://null)?ttl=5', MailerConfigurationException::BAD_RETRY_PERIOD];
    }

    #[DataProvider('malformedComposites')]
    public function test_the_composite_grammar_is_exact(string $dsn, string $reason): void
    {
        $this->expectRejection($dsn, $reason);
    }

    // --- leaf grammar ---------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedLeaves(): iterable
    {
        yield 'no scheme' => ['mail.example.com:587', MailerConfigurationException::LEAF_SHAPE];
        yield 'a single slash' => ['smtp:/mail.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a path' => ['smtp://mail.example.com/send', MailerConfigurationException::LEAF_SHAPE];
        yield 'a fragment' => ['smtp://mail.example.com#frag', MailerConfigurationException::LEAF_CHARSET];
        yield 'an empty authority' => ['smtp://', MailerConfigurationException::LEAF_SHAPE];
        yield 'an empty host with a port' => ['smtp://:587', MailerConfigurationException::LEAF_SHAPE];
        yield 'an uppercase scheme' => ['SMTP://mail.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a non-ASCII host' => ['smtp://mäil.example.com', MailerConfigurationException::LEAF_CHARSET];
        yield 'a control byte' => ["smtp://mail.example.com\x01", MailerConfigurationException::LEAF_CHARSET];
        yield 'an empty username half' => ['smtp://:pass@mail.example.com', MailerConfigurationException::EMPTY_CREDENTIAL_HALF];
        yield 'an empty password half' => ['smtp://user:@mail.example.com', MailerConfigurationException::EMPTY_CREDENTIAL_HALF];
        yield 'an empty credential' => ['smtp://@mail.example.com', MailerConfigurationException::EMPTY_CREDENTIAL_HALF];
        yield 'a truncated percent escape' => ['smtp://user:pa%s@mail.example.com', MailerConfigurationException::BAD_PERCENT_ESCAPE];
        yield 'a dangling percent' => ['smtp://user:pass%@mail.example.com', MailerConfigurationException::BAD_PERCENT_ESCAPE];
        yield 'a port of zero' => ['smtp://mail.example.com:0', MailerConfigurationException::LEAF_SHAPE];
        yield 'a port above 65535' => ['smtp://mail.example.com:65536', MailerConfigurationException::LEAF_SHAPE];
        yield 'a zero-padded port' => ['smtp://mail.example.com:0587', MailerConfigurationException::LEAF_SHAPE];
        yield 'an empty host label' => ['smtp://mail..example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a trailing dot' => ['smtp://mail.example.com.', MailerConfigurationException::LEAF_SHAPE];
        yield 'an underscore in a label' => ['smtp://mail_relay.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a tilde in a label' => ['smtp://mail~relay.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a hyphen-led label' => ['smtp://-relay.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a hyphen-trailed label' => ['smtp://relay-.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a label over 63 bytes' => ['smtp://' . str_repeat('a', 64) . '.example.com', MailerConfigurationException::LEAF_SHAPE];
        yield 'a bracket string that is not IPv6' => ['smtp://[not-an-address]:587', MailerConfigurationException::LEAF_SHAPE];
        yield 'a bracketed IPv4' => ['smtp://[198.51.100.7]:587', MailerConfigurationException::LEAF_SHAPE];
        yield 'an encoded line break in a password' => ['smtp://user:pa%0D%0Ass@mail.example.com', MailerConfigurationException::CREDENTIAL_BYTES];
        yield 'an encoded NUL in a username' => ['smtp://us%00er:pass@mail.example.com', MailerConfigurationException::CREDENTIAL_BYTES];
        yield 'an encoded DEL in a password' => ['smtp://user:pa%7Fss@mail.example.com', MailerConfigurationException::CREDENTIAL_BYTES];
        yield 'an encoded high byte in a password' => ['smtp://user:pa%C3%A9ss@mail.example.com', MailerConfigurationException::CREDENTIAL_BYTES];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedHosts(): iterable
    {
        yield 'a name' => ['smtp://mail.example.com'];
        yield 'a single label' => ['smtp://localhost'];
        yield 'a label of exactly 63 bytes' => ['smtp://' . str_repeat('a', 63) . '.example.com'];
        yield 'digits in a label' => ['smtp://mx1.example.com'];
        yield 'a hyphen inside a label' => ['smtp://mail-relay.example.com'];
        yield 'IPv4' => ['smtp://198.51.100.7'];
        yield 'bracketed IPv6' => ['smtp://[2001:db8::1]'];
        yield 'bracketed IPv6 loopback' => ['smtp://[::1]'];
    }

    #[DataProvider('acceptedHosts')]
    public function test_a_real_host_is_kept(string $dsn): void
    {
        self::assertNotSame('', $this->leaf($dsn)->host);
    }

    public function test_a_half_reading_zero_is_kept_as_written_for_the_policy_to_judge(): void
    {
        $leaf = $this->leaf('smtp://0:%30@mail.example.com');

        self::assertSame('0', $leaf->user);
        self::assertSame('0', $leaf->password);
    }

    public function test_an_escaped_delimiter_still_decodes_into_a_credential(): void
    {
        $leaf = $this->leaf('smtp://us%40er:p%3Ass%2Fword@mail.example.com');

        self::assertSame('us@er', $leaf->user);
        self::assertSame('p:ss/word', $leaf->password);
    }

    #[DataProvider('malformedLeaves')]
    public function test_the_leaf_grammar_is_exact(string $dsn, string $reason): void
    {
        $this->expectRejection($dsn, $reason);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedQueries(): iterable
    {
        yield 'an empty query' => ['smtp://mail.example.com?'];
        yield 'an empty pair' => ['smtp://mail.example.com?require_tls=true&'];
        yield 'a valueless key' => ['smtp://mail.example.com?require_tls'];
        yield 'an empty value' => ['smtp://mail.example.com?require_tls='];
        yield 'an empty key' => ['smtp://mail.example.com?=true'];
        yield 'a bracketed key' => ['smtp://mail.example.com?require_tls[]=true'];
        yield 'a nested key' => ['smtp://mail.example.com?a[b]=true'];
        yield 'a dotted key parse_str would rewrite' => ['smtp://mail.example.com?require.tls=true'];
        yield 'a semicolon separator' => ['smtp://mail.example.com?require_tls=true;verify_peer=true'];
        yield 'a repeated key' => ['smtp://mail.example.com?require_tls=true&require_tls=false'];
        yield 'a case-colliding key' => ['smtp://mail.example.com?require_tls=true&REQUIRE_TLS=false'];
    }

    #[DataProvider('malformedQueries')]
    public function test_the_query_grammar_is_flat_and_unique(string $dsn): void
    {
        $this->expectRejection($dsn, MailerConfigurationException::BAD_QUERY);
    }

    // --- caps ------------------------------------------------------------

    public function test_a_dsn_of_exactly_the_byte_cap_parses(): void
    {
        $dsn = self::dsnOfExactly(DsnParser::MAX_BYTES);

        self::assertSame(DsnParser::MAX_BYTES, strlen($dsn));
        self::assertInstanceOf(CompositeDsn::class, DsnParser::parse($dsn, self::KEY));
    }

    public function test_a_dsn_one_byte_over_the_cap_is_refused_before_anything_is_parsed(): void
    {
        $this->expectRejection(self::dsnOfExactly(DsnParser::MAX_BYTES + 1), MailerConfigurationException::TOO_LONG);
    }

    public function test_nesting_of_exactly_the_depth_cap_parses(): void
    {
        self::assertInstanceOf(CompositeDsn::class, DsnParser::parse(self::nested(DsnParser::MAX_DEPTH), self::KEY));
    }

    public function test_nesting_one_level_over_the_cap_is_refused(): void
    {
        $this->expectRejection(self::nested(DsnParser::MAX_DEPTH + 1), MailerConfigurationException::TOO_DEEP);
    }

    public function test_exactly_the_leaf_cap_parses(): void
    {
        self::assertInstanceOf(CompositeDsn::class, DsnParser::parse(self::leaves(DsnParser::MAX_LEAVES), self::KEY));
    }

    public function test_one_leaf_over_the_cap_is_refused(): void
    {
        $this->expectRejection(self::leaves(DsnParser::MAX_LEAVES + 1), MailerConfigurationException::TOO_MANY_LEAVES);
    }

    public function test_exactly_the_query_pair_cap_parses(): void
    {
        $leaf = $this->leaf('smtp://mail.example.com?' . self::pairs(DsnParser::MAX_QUERY_PAIRS));

        self::assertCount(DsnParser::MAX_QUERY_PAIRS, $leaf->options);
    }

    public function test_one_query_pair_over_the_cap_is_refused(): void
    {
        $this->expectRejection(
            'smtp://mail.example.com?' . self::pairs(DsnParser::MAX_QUERY_PAIRS + 1),
            MailerConfigurationException::TOO_MANY_QUERY_PAIRS,
        );
    }

    public function test_a_value_of_exactly_the_byte_cap_parses(): void
    {
        $leaf = $this->leaf('smtp://mail.example.com?local_domain=' . str_repeat('a', DsnParser::MAX_VALUE_BYTES));

        self::assertSame(DsnParser::MAX_VALUE_BYTES, strlen($leaf->options['local_domain']));
    }

    public function test_a_value_one_byte_over_the_cap_is_refused(): void
    {
        $this->expectRejection(
            'smtp://mail.example.com?local_domain=' . str_repeat('a', DsnParser::MAX_VALUE_BYTES + 1),
            MailerConfigurationException::VALUE_TOO_LONG,
        );
    }

    public function test_a_credential_one_byte_over_the_cap_is_refused(): void
    {
        $this->expectRejection(
            'smtp://user:' . str_repeat('a', DsnParser::MAX_VALUE_BYTES + 1) . '@mail.example.com',
            MailerConfigurationException::VALUE_TOO_LONG,
        );
    }

    // --- helpers ---------------------------------------------------------

    private function leaf(string $dsn): LeafDsn
    {
        $node = DsnParser::parse($dsn, self::KEY);
        self::assertInstanceOf(LeafDsn::class, $node);

        return $node;
    }

    private function expectRejection(string $dsn, string $reason): void
    {
        try {
            DsnParser::parse($dsn, self::KEY);
        } catch (MailerConfigurationException $e) {
            self::assertStringContainsString($reason, $e->getMessage());

            return;
        }

        self::fail('The DSN was accepted.');
    }

    /**
     * A structurally valid composite padded, through one leaf's password,
     * to an exact byte length.
     */
    private static function dsnOfExactly(int $bytes): string
    {
        $leaves = [];

        for ($i = 0; $i < 7; ++$i) {
            $leaves[] = 'smtps://u:' . str_repeat('p', 1000) . '@mail.example.com';
        }

        $leaves[] = 'smtps://u:P@mail.example.com';
        $dsn = 'failover(' . implode(' ', $leaves) . ')';
        $shortfall = $bytes - strlen($dsn);

        self::assertGreaterThanOrEqual(0, $shortfall);

        return str_replace('u:P@', 'u:' . str_repeat('P', $shortfall + 1) . '@', $dsn);
    }

    private static function nested(int $depth): string
    {
        return str_repeat('failover(', $depth) . 'null://null' . str_repeat(')', $depth);
    }

    private static function leaves(int $count): string
    {
        return 'roundrobin(' . implode(' ', array_fill(0, $count, 'null://null')) . ')';
    }

    private static function pairs(int $count): string
    {
        $pairs = [];

        for ($i = 0; $i < $count; ++$i) {
            $pairs[] = "k{$i}=v";
        }

        return implode('&', $pairs);
    }
}
