<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Registry;

/**
 * One admitted scheme, with everything needed to judge a DSN that names
 * it before anything is constructed.
 *
 * `$factoryClass` and `$transportClasses` are plain strings so that
 * naming a bridge here never loads it: an entry is only touched when its
 * own scheme is selected, and the bridge package stays optional. They are
 * also both exact. Asking Symfony which of its factories claims a scheme
 * would accept whatever is installed under that name, and checking a
 * built object against a family as wide as `AbstractHttpTransport` would
 * accept an impostor that merely extends it — and would reject Symfony's
 * own `SesHttpAsyncAwsTransport`, which extends `AbstractTransport`
 * instead. Naming both ends is what makes "this is the official
 * transport" a check rather than a description, and both ends are
 * compared as exact runtime classes: a subclass of the named transport,
 * and an alias resolving to a class other than the named factory, are
 * refused as the impostors they would be.
 *
 * @internal to kinetis/mailer
 */
final readonly class TransportEntry
{
    /**
     * @param string       $scheme           the exact scheme this entry answers to
     * @param class-string $factoryClass     the official factory, named as a string
     * @param list<class-string> $transportClasses every class its `create()` may return
     * @param string       $package          the Composer package carrying the factory
     * @param array<string, string> $options exact consumed option names, each mapped
     *     to an {@see \Kinetis\Mailer\Policy\OptionRules} value rule
     * @param array<string, string> $optionPrerequisites option => the option it needs
     */
    public function __construct(
        public string $scheme,
        public TransportKind $kind,
        public string $factoryClass,
        public array $transportClasses,
        public string $package,
        public CredentialMode $credentials,
        public HostShape $host,
        public array $options = [],
        public array $optionPrerequisites = [],
    ) {}

    public function acceptsPort(): bool
    {
        return $this->host === HostShape::Host || $this->host === HostShape::DefaultOrHost;
    }
}
