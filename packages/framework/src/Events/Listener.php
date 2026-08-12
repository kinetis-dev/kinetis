<?php

declare(strict_types=1);

namespace Kinetis\Events;

use Attribute;
use InvalidArgumentException;

/**
 * Marks a public method as an event listener. The event class is
 * inferred from the method's own single parameter type, the same way
 * #[McpTool] methods declare their argument types directly rather than
 * repeating them redundantly in the attribute itself. A class may carry
 * multiple #[Listener] methods for different events, the same way a
 * controller may carry multiple routed methods.
 *
 * $priority orders multiple listeners registered for the *same* event —
 * higher runs earlier. An event implementing
 * Psr\EventDispatcher\StoppableEventInterface can stop later listeners
 * from running at all (see EventDispatcher::dispatch()), so this order is
 * a real behavioral difference, not just cosmetic. Bounded to 0-100,
 * defaulting to 50. Two listeners for the same event sharing a priority
 * are ordered alphabetically by class name, then by method name, so the
 * result never depends on filesystem/scan order.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Listener
{
    public function __construct(
        public int $priority = 50,
    ) {
        if ($priority < 0 || $priority > 100) {
            throw new InvalidArgumentException("Listener priority must be between 0 and 100, got {$priority}.");
        }
    }
}
