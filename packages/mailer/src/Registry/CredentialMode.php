<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Registry;

/**
 * How many credential halves a scheme's official factory actually reads,
 * taken from that factory's own source rather than from a convention.
 *
 * The distinction matters in both directions. `AbstractTransportFactory::getUser()`
 * throws when the half it needs is absent, so a missing one is a failure
 * dressed as a construction error; a half nothing reads is a secret
 * written into configuration for no effect. And one scheme legitimately
 * takes neither: Symfony's SES API branch passes `getUser()`/`getPassword()`
 * straight into an AsyncAws `Configuration`, where null means "resolve
 * the ambient AWS credentials" — an instance role or a shared profile —
 * which is the recommended way to run it.
 *
 * @internal to kinetis/mailer
 */
enum CredentialMode
{
    /** Neither half is read; supplying one is refused. */
    case None;

    /** One token in the user position; a password is refused. */
    case UserOnly;

    /** Both halves are read and both are required. */
    case UserAndPassword;

    /**
     * Both halves or neither. `EsmtpTransportFactory` reads each on its
     * own, so a half-pair configures a username with no password; policy
     * decides whether the pair has to be there at all, and only the
     * local-insecure profile says it does not.
     */
    case OptionalPair;

    /** Both halves, or neither — absent means the ambient AWS credential chain. */
    case AmbientOrUserAndPassword;
}
