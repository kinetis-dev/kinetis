<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

final class JwkSetException extends RuntimeException
{
    public static function invalidPublicKey(string $kid): self
    {
        return new self("The public key given for kid \"{$kid}\" is not a valid PEM-format key.");
    }

    public static function notAnRsaKey(string $kid): self
    {
        return new self("The public key given for kid \"{$kid}\" is not an RSA key — JwkSet only supports RSA (RS256/RS384/RS512).");
    }
}
