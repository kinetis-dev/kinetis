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
use Kinetis\Http\Attributes\Middleware;
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
 *
 * That middleware joins the `broadcasting` group with
 * `#[AsMiddlewareGroup('broadcasting')]`, keeping authentication on this
 * one route. {@see BroadcastOriginMiddleware} is the group's permanent
 * member, so the reference resolves wherever this package is installed —
 * including for an application whose authorizers are all anonymous,
 * which needs no member of its own.
 */
#[Middleware('@broadcasting')]
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

        // RequestBodyMiddleware is the one place a body becomes fields:
        // it bounds the bytes and, for the form media types alone,
        // publishes them as getParsedBody(). Anything it did not parse —
        // no body at all, form-looking bytes under an unrelated content
        // type — reaches here with nothing to read and meets the
        // required-fields 422 below.
        $parsed = $request->getParsedBody();
        $data = is_array($parsed) ? $parsed : [];

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
}
