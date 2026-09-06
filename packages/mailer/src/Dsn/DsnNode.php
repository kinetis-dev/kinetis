<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Dsn;

/**
 * One node of the Kinetis-owned DSN tree: either a {@see LeafDsn} naming a
 * single transport, or a {@see CompositeDsn} grouping members under
 * `failover(...)` or `roundrobin(...)`.
 *
 * The tree is what {@see \Kinetis\Mailer\Policy\TransportPolicy} inspects
 * and what {@see \Kinetis\Mailer\TransportBuilder} builds from. The
 * complete DSN string is never handed back to Symfony, so its recursive
 * parser and the PCRE inside it never run on consumer input.
 *
 * @internal to kinetis/mailer
 */
interface DsnNode
{
}
