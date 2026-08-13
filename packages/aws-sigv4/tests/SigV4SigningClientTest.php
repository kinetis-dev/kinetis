<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use Kinetis\AwsSigV4\Exception\SigningException;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class SigV4SigningClientTest extends TestCase
{
    /**
     * AWS's own published "get-vanilla" SigV4 test vector: a fixed date
     * (2015-08-30T12:36:00Z), fixed static test credentials
     * (AKIDEXAMPLE), region "us-east-1", the generic placeholder service
     * name "service", and a plain GET request with no extra headers or
     * query string. Proves this class wires a real PSR-7 request into
     * AsyncAws's own Request/Credentials/RequestContext correctly and
     * produces the exact byte-correct Authorization header AWS itself
     * publishes as ground truth — not just "a signature that looks
     * plausible".
     */
    public function test_matches_the_aws_published_get_vanilla_test_vector(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials(
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ));
        $now = new \DateTimeImmutable('2015-08-30T12:36:00Z');

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider, $now);

        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, SignedHeaders=host;x-amz-date, Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $recordingClient->captured?->getHeaderLine('Authorization'),
        );
        self::assertSame('example.amazonaws.com', $recordingClient->captured?->getHeaderLine('Host'));
        self::assertSame('20150830T123600Z', $recordingClient->captured?->getHeaderLine('X-Amz-Date'));
    }

    public function test_delegates_to_the_wrapped_client_and_returns_its_response(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);

        $response = $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_session_token_is_signed_in_as_a_header(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials(
            'AKIDEXAMPLE',
            'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'a-session-token',
        ));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame('a-session-token', $recordingClient->captured?->getHeaderLine('X-Amz-Security-Token'));
    }

    public function test_an_existing_header_on_the_original_request_is_included_in_the_signature(): void
    {
        // A plain PSR-7 withHeader() call already guarantees X-Custom
        // survives onto the signed request regardless of anything this
        // class does — copying headers into the AwsRequest only matters
        // for whether SignerV4 actually includes it in what gets signed,
        // so that's what this test has to check, via the Authorization
        // header's own SignedHeaders portion, not just presence.
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            (new Request('GET', 'https://example.amazonaws.com/'))->withHeader('X-Custom', 'my-value'),
        );

        self::assertStringContainsString(
            'SignedHeaders=host;x-amz-date;x-custom,',
            $recordingClient->captured?->getHeaderLine('Authorization') ?? '',
        );
    }

    public function test_the_request_body_is_still_readable_by_the_wrapped_client_after_signing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);
        $client->sendRequest(
            new Request('POST', 'https://example.amazonaws.com/', [], '{"query":{"match_all":{}}}'),
        );

        // getContents() reads from the stream's *current* position, unlike
        // (string) casting via __toString() — which auto-rewinds first
        // regardless, and so would still pass even if this class's own
        // explicit rewind() were removed. A wrapped HTTP client reading the
        // body via a raw read/getContents() call (not __toString()) is
        // exactly the real scenario that rewind() exists to protect.
        self::assertSame('{"query":{"match_all":{}}}', $recordingClient->captured?->getBody()->getContents());
    }

    public function test_a_relative_request_uri_is_resolved_against_base_uri_before_signing(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'es',
            $credentialProvider,
            baseUri: 'https://search-my-domain.us-east-1.es.amazonaws.com',
        );
        $client->sendRequest(new Request('GET', '/_cluster/health'));

        self::assertSame(
            'search-my-domain.us-east-1.es.amazonaws.com',
            $recordingClient->captured?->getUri()->getHost(),
        );
        self::assertSame('https', $recordingClient->captured?->getUri()->getScheme());
        self::assertSame('/_cluster/health', $recordingClient->captured?->getUri()->getPath());
        self::assertSame(
            'search-my-domain.us-east-1.es.amazonaws.com',
            $recordingClient->captured?->getHeaderLine('Host'),
        );
    }

    public function test_base_uri_with_a_path_prefix_is_prepended_onto_the_request_path(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'execute-api',
            $credentialProvider,
            baseUri: 'https://api.example.com/prod',
        );
        $client->sendRequest(new Request('GET', '/users'));

        self::assertSame('/prod/users', $recordingClient->captured?->getUri()->getPath());
    }

    public function test_a_request_that_already_has_a_host_is_left_untouched_even_with_base_uri_set(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'https://should-not-be-used.example.com',
        );
        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));

        self::assertSame('example.amazonaws.com', $recordingClient->captured?->getUri()->getHost());
    }

    public function test_an_invalid_base_uri_throws_a_clear_error(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(new Credentials('AKIDEXAMPLE', 'secret'));

        $client = new SigV4SigningClient(
            $recordingClient,
            'us-east-1',
            'service',
            $credentialProvider,
            baseUri: 'not-a-valid-uri',
        );

        $this->expectException(SigningException::class);
        $this->expectExceptionMessage(
            'baseUri "not-a-valid-uri" is not a valid absolute URI (must include a scheme and host).',
        );
        $client->sendRequest(new Request('GET', '/'));
    }

    public function test_no_resolvable_credentials_throws_a_clear_error(): void
    {
        $recordingClient = new RecordingClient();
        $credentialProvider = new FixedCredentialProvider(null);

        $client = new SigV4SigningClient($recordingClient, 'us-east-1', 'service', $credentialProvider);

        $this->expectException(SigningException::class);
        // The full message, not just a leading substring — a leading-
        // substring check alone doesn't catch a mutation that only
        // reorders or drops words later in the concatenated string.
        $this->expectExceptionMessage(
            'Could not resolve AWS credentials to sign this request. Set '
            . 'AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY, a shared credentials '
            . 'file, or run somewhere with an IAM role attached, or pass a '
            . 'CredentialProvider directly.',
        );
        $client->sendRequest(new Request('GET', 'https://example.amazonaws.com/'));
    }
}

final class RecordingClient implements ClientInterface
{
    public ?RequestInterface $captured = null;

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return new Response(200);
    }
}

final class FixedCredentialProvider implements CredentialProvider
{
    public function __construct(private readonly ?Credentials $credentials) {}

    public function getCredentials(Configuration $configuration): ?Credentials
    {
        return $this->credentials;
    }
}
