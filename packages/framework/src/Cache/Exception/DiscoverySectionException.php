<?php

declare(strict_types=1);

namespace Kinetis\Cache\Exception;

use LogicException;

/**
 * A discovery section read another through
 * {@see \Kinetis\Cache\DiscoveryContext::compiled()} that cannot be
 * compiled: no installed package declares it, or compiling it requires
 * itself. Both are defects in how the installed sections depend on each
 * other, never a stale artifact, so a build fails on them and a boot
 * never retries them as a fresh compile.
 */
final class DiscoverySectionException extends LogicException
{
    /**
     * @param list<class-string> $chain the sections being compiled, outermost first, ending with the repeated one
     */
    public static function cycle(array $chain): self
    {
        return new self(
            'Discovery sections depend on each other in a cycle: ' . implode(' -> ', $chain)
            . '. Remove one of these DiscoveryContext::compiled() reads so the dependency runs one way.',
        );
    }

    /**
     * @param ?class-string $requester the section whose compile() asked, or null outside any section
     * @param ?array{package: string, reason: string} $skippedDeclaration
     */
    public static function notInstalled(string $section, ?string $requester, ?array $skippedDeclaration): self
    {
        $message = "Discovery section {$section}, requested by "
            . ($requester ?? 'a caller outside any section compile')
            . ', is not declared by any installed package in extra.kinetis.discovery.';

        if ($skippedDeclaration !== null) {
            $message .= " Package \"{$skippedDeclaration['package']}\" declares it, but the declaration was skipped: "
                . "{$skippedDeclaration['reason']}.";
        } else {
            $message .= ' Require the package that declares it, or stop reading it.';
        }

        return new self($message);
    }
}
