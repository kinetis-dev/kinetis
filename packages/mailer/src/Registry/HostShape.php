<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Registry;

/**
 * What the authority of a scheme is allowed to say, again read off the
 * official factory.
 *
 * A bridge's SMTP branch typically hard-codes its own host and port and
 * never looks at the DSN's, so writing one there configures nothing —
 * `LiteralDefault` refuses it rather than accepting a value with no
 * effect. An API branch that does read the host (`'default' === $host ?
 * null : $host`) supports a real custom endpoint, which `DefaultOrHost`
 * admits.
 *
 * @internal to kinetis/mailer
 */
enum HostShape
{
    /** Exactly `null`, the discard transport's own spelling. */
    case LiteralNull;

    /** Exactly `default`; a port is refused too. */
    case LiteralDefault;

    /** A real hostname or address, with an optional port. */
    case Host;

    /** `default` for the provider's own endpoint, or a custom one. */
    case DefaultOrHost;
}
