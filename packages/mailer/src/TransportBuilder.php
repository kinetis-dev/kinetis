<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Mailer\Dsn\CompositeDsn;
use Kinetis\Mailer\Dsn\CompositeKind;
use Kinetis\Mailer\Dsn\DsnNode;
use Kinetis\Mailer\Dsn\LeafDsn;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\Policy\TransportPolicy;
use Kinetis\Mailer\Registry\TransportEntry;
use Symfony\Component\Mailer\Transport\Dsn as SymfonyDsn;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Turns a tree {@see TransportPolicy} has already approved into
 * transports, bottom up.
 *
 * Each leaf is built by the one factory its registry entry names,
 * instantiated here rather than discovered. `Transport::getDefaultFactories()`
 * would hand back whichever factory is installed under a familiar class
 * name and whichever future bridge Symfony adds, and `Transport::fromDsn()`
 * would take the complete composite string and walk it again with its own
 * recursive parser. Naming the factory means an unexpected one is a
 * refusal instead of a build, and `FailoverTransport`/`RoundRobinTransport`
 * take a plain array of already-built transports and a retry period,
 * which is all this needs from them. Both the factory and what it returns
 * are held to the exact runtime class the entry names.
 *
 * Every vendor call is inside the boundary: a `Dsn` that will not
 * construct, a `supports()` that throws, a factory that throws, and a
 * composite that refuses its children all become one fresh
 * {@see MailerConfigurationException}, since Symfony's own messages quote
 * the DSN they were handed.
 *
 * @internal to kinetis/mailer
 */
final readonly class TransportBuilder
{
    public function __construct(
        private TransportPolicy $policy,
        private HttpClientInterface $client,
        private string $key,
    ) {}

    /**
     * @throws MailerConfigurationException
     */
    public function build(#[\SensitiveParameter] DsnNode $node): TransportInterface
    {
        if ($node instanceof CompositeDsn) {
            return $this->composite($node);
        }

        if (!$node instanceof LeafDsn) {
            throw $this->reject(MailerConfigurationException::UNPROVABLE_FAMILY);
        }

        return $this->leaf($node);
    }

    private function composite(#[\SensitiveParameter] CompositeDsn $node): TransportInterface
    {
        $children = array_map($this->build(...), $node->members);

        try {
            return match ($node->kind) {
                CompositeKind::Failover => new FailoverTransport($children, $node->retryPeriod),
                CompositeKind::RoundRobin => new RoundRobinTransport($children, $node->retryPeriod),
            };
        } catch (Throwable) {
            throw $this->reject(MailerConfigurationException::ASSEMBLY_FAILED);
        }
    }

    private function leaf(#[\SensitiveParameter] LeafDsn $leaf): TransportInterface
    {
        $entry = $this->policy->entryFor($leaf);
        $factory = $this->factoryFor($entry);
        $dsn = $this->symfonyDsn($leaf);

        try {
            $supports = $factory->supports($dsn);
        } catch (Throwable) {
            throw $this->reject(MailerConfigurationException::ASSEMBLY_FAILED);
        }

        if (!$supports) {
            throw $this->reject(MailerConfigurationException::WRONG_FACTORY);
        }

        try {
            $transport = $factory->create($dsn);
        } catch (Throwable) {
            // Symfony's own message quotes the DSN it was handed.
            throw $this->reject(MailerConfigurationException::CONSTRUCTION_FAILED);
        }

        $this->policy->assertBuilt($entry, $transport);

        return $transport;
    }

    /**
     * The bridge is loaded only once its own scheme has been selected,
     * which is what keeps every optional package optional.
     *
     * The object that comes back has to be exactly the class the entry
     * names. `class_exists()` and `new` both answer to a `class_alias()`,
     * so an alias registered under the official name would construct
     * whatever class it points at; the runtime class of the instance is
     * the one thing an alias cannot forge.
     */
    private function factoryFor(TransportEntry $entry): TransportFactoryInterface
    {
        if (!class_exists($entry->factoryClass)) {
            throw MailerConfigurationException::missingBridge($this->key, $entry->package);
        }

        $class = $entry->factoryClass;

        try {
            $factory = new $class(null, $this->client, null);
        } catch (Throwable) {
            throw $this->reject(MailerConfigurationException::WRONG_FACTORY);
        }

        if ($factory::class !== $entry->factoryClass || !$factory instanceof TransportFactoryInterface) {
            throw $this->reject(MailerConfigurationException::WRONG_FACTORY);
        }

        return $factory;
    }

    private function symfonyDsn(#[\SensitiveParameter] LeafDsn $leaf): SymfonyDsn
    {
        try {
            return $leaf->toSymfonyDsn();
        } catch (Throwable) {
            throw $this->reject(MailerConfigurationException::ASSEMBLY_FAILED);
        }
    }

    private function reject(string $reason): MailerConfigurationException
    {
        return MailerConfigurationException::rejected($this->key, $reason);
    }
}
