<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Driver;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Config\Config;
use Kinetis\RevoltHttpClient\Http;

/**
 * Speaks the Pusher Channels REST/auth protocol — the wire format Soketi,
 * Laravel Reverb, and Pusher's own hosted service all implement
 * identically, so this one driver covers all three; only the host/port/
 * TLS configuration differs between them. Built on `kinetis/revolt-http-client`'s
 * {@see Http}, so `broadcast()` suspends the calling Fiber rather than
 * blocking the worker while the WebSocket server accepts the event.
 *
 * The signing algorithm here is verified against `pusher/pusher-php-server`'s
 * own `build_auth_query_params()`/`authorizeChannel()` implementation,
 * not reconstructed from documentation: every parameter (`auth_key`,
 * `auth_timestamp`, `auth_version`, and, for a trigger request,
 * `body_md5`) is lexically sorted before being joined into
 * `"{method}\n{path}\n{key}={value}&..."` — unescaped, no URL-encoding —
 * and HMAC-SHA256'd (hex output) with the app secret. Channel
 * authorization signs `"{socketId}:{channelName}"` (private) or
 * `"{socketId}:{channelName}:{channelDataJson}"` (presence) the same way.
 */
final readonly class PusherBroadcaster implements BroadcasterInterface
{
    public function __construct(
        private Http $http,
        private string $appId,
        private string $key,
        #[\SensitiveParameter]
        private string $secret,
        private string $host = 'api.pusherapp.com',
        private int $port = 443,
        private bool $useTls = true,
    ) {}

    public static function fromConfig(Config $config, Http $http, string $connection = 'default'): self
    {
        return new self(
            $http,
            $config->required(Config::scopedKey('BROADCAST_APP_ID', $connection)),
            $config->required(Config::scopedKey('BROADCAST_KEY', $connection)),
            $config->required(Config::scopedKey('BROADCAST_SECRET', $connection)),
            $config->string(Config::scopedKey('BROADCAST_HOST', $connection), 'api.pusherapp.com'),
            $config->int(Config::scopedKey('BROADCAST_PORT', $connection), 443),
            $config->bool(Config::scopedKey('BROADCAST_TLS', $connection), true),
        );
    }

    #[\Override]
    public function broadcast(string $channel, string $event, array $payload): void
    {
        // The wire format encodes `data` as a JSON string nested inside
        // the outer JSON body, not a raw nested object — confirmed
        // against the real SDK's make_event(), which always
        // json_encode()s the payload separately before it ever reaches
        // the outer json_encode() call.
        $body = json_encode(
            ['name' => $event, 'channels' => [$channel], 'data' => json_encode($payload, JSON_THROW_ON_ERROR)],
            JSON_THROW_ON_ERROR,
        );

        $path = "/apps/{$this->appId}/events";

        $this->http
            ->send('POST', $this->baseUrl() . $this->signedQuery('POST', $path, ['body_md5' => md5($body)]), [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json'],
            ])
            ->throw();
    }

    /**
     * The `auth` field a private-channel subscription response carries.
     */
    public function authorizeChannel(string $socketId, string $channelName): string
    {
        return $this->key . ':' . $this->sign("{$socketId}:{$channelName}");
    }

    /**
     * The `auth` field a presence-channel subscription response carries
     * — signed over the socket id, channel name, and the already-encoded
     * `channel_data` JSON string the caller will also send back verbatim.
     */
    public function authorizePresenceChannel(string $socketId, string $channelName, string $channelDataJson): string
    {
        return $this->key . ':' . $this->sign("{$socketId}:{$channelName}:{$channelDataJson}");
    }

    private function sign(string $stringToSign): string
    {
        return hash_hmac('sha256', $stringToSign, $this->secret);
    }

    private function baseUrl(): string
    {
        $scheme = $this->useTls ? 'https' : 'http';

        return "{$scheme}://{$this->host}:{$this->port}";
    }

    /**
     * @param array<string, string> $extraParams query params beyond the
     *     three every request signs — `body_md5` for a trigger request,
     *     none for anything else this driver builds
     */
    private function signedQuery(string $method, string $path, array $extraParams): string
    {
        $params = [
            'auth_key' => $this->key,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
            ...$extraParams,
        ];
        ksort($params);

        $stringToSign = $method . "\n" . $path . "\n" . $this->joinParams($params);
        $params['auth_signature'] = $this->sign($stringToSign);
        ksort($params);

        return $path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $params
     */
    private function joinParams(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            $pairs[] = "{$key}={$value}";
        }

        return implode('&', $pairs);
    }
}
