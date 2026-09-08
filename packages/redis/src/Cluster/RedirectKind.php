<?php

declare(strict_types=1);

namespace Kinetis\Redis\Cluster;

/**
 * MOVED means the slot has a new owner and the client's map is stale.
 * ASK means one key is mid-migration while the slot's stable owner has
 * not changed, so the target accepts the command only after ASKING and
 * must not be recorded as the slot's owner.
 */
enum RedirectKind: string
{
    case Moved = 'MOVED';
    case Ask = 'ASK';
}
