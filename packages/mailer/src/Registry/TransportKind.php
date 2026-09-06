<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Registry;

/**
 * What a registry entry is, for the rules that differ by category rather
 * than by scheme.
 *
 * `ProviderSmtp` is separate from `CoreSmtp` because the two are built by
 * different factories reading different options: `EsmtpTransportFactory`
 * reads the full ESMTP option surface, while a bridge's own SMTP branch
 * usually reads none of it. Both are SMTP, so neither escapes the SMTP
 * rules by carrying a prefix.
 *
 * @internal to kinetis/mailer
 */
enum TransportKind
{
    /** Accepts and discards. */
    case Discard;

    /** A blocking local process. */
    case Sendmail;

    /** The built-in ESMTP transport. */
    case CoreSmtp;

    /** A bridge's own SMTP transport. */
    case ProviderSmtp;

    /** A bridge's HTTPS API transport. */
    case Api;
}
