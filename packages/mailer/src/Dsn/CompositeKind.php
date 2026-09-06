<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Dsn;

/**
 * The two composite keywords the grammar accepts.
 *
 * @internal to kinetis/mailer
 */
enum CompositeKind: string
{
    case Failover = 'failover';
    case RoundRobin = 'roundrobin';
}
