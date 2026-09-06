<?php

declare(strict_types=1);

namespace Kinetis\Mailer;

use Kinetis\Mailer\Exception\MailerConfigurationException;

/**
 * The bounded grammar a named connection must satisfy before it is
 * allowed to build a config key.
 *
 * `Config::scopedKey()` splices the name into the middle of a key and
 * uppercases it, so an unchecked name chooses which environment variable
 * is read. A name carrying `_DSN`, a separator, a control byte or a
 * newline selects a key nobody wrote down; an unbounded one builds a key
 * of unbounded length. One lowercase `[a-z0-9]` segment, or several
 * joined by `_`, up to 32 bytes, is the whole vocabulary — and it is
 * canonical, so exactly one spelling reaches exactly one key.
 *
 * `default` is the name that reads the plain, unscoped key, which is what
 * every other `*Factory::fromConfig()` in this project means by it.
 */
final class ConnectionName
{
    public const int MAX_LENGTH = 32;

    private const string GRAMMAR = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/D';

    /**
     * @throws MailerConfigurationException
     */
    public static function validated(string $connection): string
    {
        if (strlen($connection) > self::MAX_LENGTH || preg_match(self::GRAMMAR, $connection) !== 1) {
            throw MailerConfigurationException::invalidConnectionName();
        }

        return $connection;
    }
}
