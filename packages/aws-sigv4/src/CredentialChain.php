<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\Core\Credentials\ContainerProvider;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Credentials\Credentials;
use AsyncAws\Core\Credentials\IniFileProvider;
use AsyncAws\Core\Credentials\InstanceProvider;
use AsyncAws\Core\Credentials\WebIdentityProvider;
use SensitiveParameter;

/**
 * The credentials a `SigV4SigningClient` signs with when the caller
 * passes no provider of its own: AsyncAws's five providers, in
 * AsyncAws's order — environment (including the STS assume-role
 * `AWS_ROLE_ARN` selects), web identity, the shared credentials and
 * config files, ECS or EKS pod identity, then IMDS.
 *
 * Every provider that reaches AWS holds the {@see SignedTransport} the
 * signed request travels on. A provider left without one builds a
 * blocking Symfony client and assumes its role on the worker thread, on
 * first resolution and again on each refresh.
 *
 * ## What is held
 *
 * The first unexpired credentials a call resolves are held and reused
 * until they expire; credentials with no expiry are held for the life
 * of the client. Nothing else is remembered. A provider answering null,
 * or answering with credentials that are already expired, is passed
 * over and the same call continues down the chain, and a call that
 * reaches the end without an answer caches nothing — a transient ECS,
 * IMDS or token-file failure costs one lookup rather than the worker's
 * remaining lifetime.
 *
 * @internal Constructed only by SigV4SigningClient.
 */
final class CredentialChain implements CredentialProvider
{
    /**
     * @var list<CredentialProvider>
     */
    private array $providers;

    private ?Credentials $held = null;

    public function __construct(#[SensitiveParameter] SignedTransport $transport)
    {
        $this->providers = [
            new ConfigurationProvider($transport),
            new WebIdentityProvider(null, null, $transport),
            new IniFileProvider(null, null, $transport),
            new ContainerProvider($transport),
            new InstanceProvider($transport),
        ];
    }

    #[\Override]
    public function getCredentials(Configuration $configuration): ?Credentials
    {
        if ($this->held !== null && !$this->held->isExpired()) {
            return $this->held;
        }

        $this->held = null;

        foreach ($this->providers as $provider) {
            $credentials = $provider->getCredentials($configuration);

            if ($credentials !== null && !$credentials->isExpired()) {
                return $this->held = $credentials;
            }
        }

        return null;
    }
}
