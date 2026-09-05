<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Http;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Exception\BroadcastingException;
use Kinetis\Broadcasting\Exception\InvalidPusherProtocolValueException;
use Kinetis\Broadcasting\PusherProtocol;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The channel-authorization endpoint a Pusher-protocol client library
 * (pusher-js, Laravel Echo, ...) calls automatically before it may
 * subscribe to a `private-*`/`presence-*` channel — discovered as an
 * ordinary route by installing `kinetis/broadcasting`
 * (`extra.kinetis.scan` names this class's own `Http` segment), never
 * hand-registered.
 *
 * Depends on the concrete {@see PusherBroadcaster}, not the generic
 * {@see BroadcasterInterface} — signing an authorization response is
 * inherently protocol-specific (HMAC-SHA256 over a Pusher-shaped string,
 * see that class), not something a driver-agnostic contract could
 * express. Resolved through the request's own {@see RequestScope}
 * (constructor-injected directly, the same self-injection
 * `BearerAuthMiddleware`/`EventDispatcher` already rely on), so a
 * `CurrentUserInterface` an upstream auth middleware registered on this
 * request is visible here.
 */
final readonly class BroadcastAuthController
{
    private const string NOT_AUTHORIZED = 'Not authorized.';

    public function __construct(
        private RequestScope $scope,
        private BroadcastChannelRegistry $channels,
        private BroadcasterInterface $broadcaster,
    ) {}

    /**
     * @return ResponseInterface|array<string, mixed>
     */
    #[Post('/broadcasting/auth')]
    public function auth(ServerRequestInterface $request): ResponseInterface|array
    {
        if (!$this->broadcaster instanceof PusherBroadcaster) {
            throw BroadcastingException::authNotSupported($this->broadcaster::class);
        }

        $data = $this->formData($request);
        $socketId = $data['socket_id'] ?? null;
        $channelName = $data['channel_name'] ?? null;

        if (!is_string($socketId) || !is_string($channelName) || $socketId === '' || $channelName === '') {
            return ErrorResponse::create(422, 'socket_id and channel_name are required.');
        }

        // Checked before anything else touches $socketId/$channelName —
        // including which authorizer (if any) is registered for the
        // channel — so a malformed request never causes an application
        // authorizer to run at all, not merely never causes its result
        // to be trusted.
        if (!PusherProtocol::isValidSocketId($socketId) || !PusherProtocol::isValidChannelName($channelName)) {
            return ErrorResponse::create(422, 'socket_id or channel_name does not match the Pusher protocol grammar.');
        }

        $isPresence = str_starts_with($channelName, 'presence-');
        $isPrivate = !$isPresence && str_starts_with($channelName, 'private-');

        if (!$isPresence && !$isPrivate) {
            return ErrorResponse::create(422, 'Only private-* and presence-* channels are authorized here.');
        }

        $bareName = substr($channelName, $isPresence ? strlen('presence-') : strlen('private-'));
        $match = $this->channels->match($bareName);

        if ($match === null) {
            return ErrorResponse::create(403, "No authorizer is registered for channel \"{$channelName}\".");
        }

        $arguments = array_values($match->params);

        if ($match->usesCurrentUser) {
            if (!$this->scope->isRegistered(CurrentUserInterface::class)) {
                return ErrorResponse::create(401, 'Authentication is required to subscribe to this channel.');
            }

            array_unshift($arguments, $this->scope->get(CurrentUserInterface::class));
        }

        /** @var object $authorizer */
        $authorizer = $this->scope->get($match->class);
        $result = $authorizer->{$match->method}(...$arguments);

        if ($isPresence) {
            if (!is_array($result)) {
                return ErrorResponse::create(403, self::NOT_AUTHORIZED);
            }

            // A malformed presence result (a missing/non-string user_id,
            // list-shaped data, an oversized payload) is the application
            // authorizer's own bug, not a client-input problem — treated
            // the same as any other "Not authorized" outcome rather than
            // an uncaught exception, and never signed regardless.
            try {
                return $this->broadcaster->authorizePresenceChannel($socketId, $channelName, $result);
            } catch (InvalidPusherProtocolValueException) {
                return ErrorResponse::create(403, self::NOT_AUTHORIZED);
            }
        }

        if ($result !== true) {
            return ErrorResponse::create(403, self::NOT_AUTHORIZED);
        }

        return ['auth' => $this->broadcaster->authorizeChannel($socketId, $channelName)];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        // The body reaching here is already bounded and complete:
        // MaxBodySizeMiddleware settles the byte ceiling and stages the
        // whole body before any handler runs, so an oversized request
        // is a 413 that never arrives at this controller.
        parse_str($request->getBody()->getContents(), $fallback);

        $result = [];

        foreach ($fallback as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
