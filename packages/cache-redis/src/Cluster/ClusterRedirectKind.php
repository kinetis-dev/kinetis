<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

/**
 * The two redirect replies Redis Cluster sends for a key routed to the
 * wrong node: MOVED means the slot has permanently changed owner (the
 * client's topology is stale — refresh it), ASK means this one key is
 * mid-migration to a node that doesn't yet stably own the slot (the
 * client must ASKING that node before replaying the command, and must
 * not treat it as the slot's new stable owner).
 */
enum ClusterRedirectKind: string
{
    case Moved = 'MOVED';
    case Ask = 'ASK';
}
