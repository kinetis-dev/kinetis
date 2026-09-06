<?php

declare(strict_types=1);

namespace Kinetis\Mailer\Tests\Doubles;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractHttpTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * An `AbstractHttpTransport` that is not the class any registry entry
 * names: the family an API leaf belongs to, without the identity, which
 * is what an impostor for an API scheme looks like.
 */
final class FakeApiTransport extends AbstractHttpTransport
{
    #[\Override]
    public function __toString(): string
    {
        return 'fake+api://default';
    }

    #[\Override]
    protected function doSendHttp(SentMessage $message): ResponseInterface
    {
        throw new \LogicException('This transport is never sent through.');
    }
}
