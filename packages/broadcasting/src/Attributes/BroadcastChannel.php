<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Attributes;

use Attribute;

/**
 * Marks a public method as the authorization callback for a private or
 * presence channel — the `Kinetis\Broadcasting` analogue of
 * `Kinetis\Events\Listener`, discovered the same way. $pattern names the
 * channel without its `private-`/`presence-` prefix (the prefix selects
 * which of the two auth responses {@see \Kinetis\Broadcasting\Http\BroadcastAuthController}
 * builds, so the pattern itself never repeats it), with `{name}`
 * placeholders matching path-template segments elsewhere in Kinetis:
 *
 *     #[BroadcastChannel('orders.{orderId}')]
 *     public function authorizeOrder(CurrentUserInterface $user, string $orderId): bool
 *     {
 *         return $this->orders->belongsTo($orderId, $user->id());
 *     }
 *
 * The leading `CurrentUserInterface` parameter is optional but, when
 * present, must come first — every other parameter must be a `string`
 * named after one of the pattern's placeholders, in the pattern's own
 * order. Returning `bool` authorizes (or rejects) a private channel;
 * returning `array` authorizes a presence channel, and the array becomes
 * that subscriber's `channel_data` (conventionally at least a `user_id`
 * key) — {@see BroadcastChannelRegistry::register()} does not itself
 * enforce which shape a given pattern must return, since a channel is
 * free to serve both channel types under different prefixes.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class BroadcastChannel
{
    public function __construct(
        public string $pattern,
    ) {}
}
