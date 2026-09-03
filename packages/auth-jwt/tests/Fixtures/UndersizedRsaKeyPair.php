<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

/**
 * A real, valid 1024-bit RSA key pair generated solely for this test
 * suite — not a real credential. Deliberately below
 * JwtKeyValidator::RSA_MINIMUM_BITS (2048), for proving construction
 * rejects an undersized-but-otherwise-genuine RSA key.
 */
final class UndersizedRsaKeyPair
{
    public const string PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIICdwIBADANBgkqhkiG9w0BAQEFAASCAmEwggJdAgEAAoGBAKaoFgp6dNFfTqVJ
    DzOe9WeFiZmzphGQCt9a2dCrfegCae11pVH8K2QNdZAibpHVoxFw193+5kFndtHy
    5yjaVyolBlU2sB0sPs/DOavPGTXKNE9EHM6Mi0b1ToPBsX1p6GiXM3UQG4ySP7A7
    zXNGZU0NYUSv93pi9LOQCSOe8Co3AgMBAAECgYBpMZnP/WG1Mrp6m/YLeF+ge2rS
    aNH/LfOe7kKkc0ri4nsoVuUGLezZl6FIXGN8i+QFQzwOtTFzwTH/7Zm5cLApNitj
    WQUvqOGDW1z0vwifrQDSBU28eCoCZbTJGpqeF7PxjaTCi2VJ2TiBsm7uDepbRvMu
    ZAbGFuHNT2/IpiSIYQJBANLobigFyWwDkpdTsQmQw3sA8JRGZyQIRkA2Pv3qe6XR
    mkvvRt049UhFqKl5PNTR0Fml1WpzdGq0BEho/GcNuicCQQDKSaiGrP8SuR/FSOb2
    VuczQaizXdDqrxxoryVGdKPF3wPNajbAFUkkvpImto6y7Jm2P4BhqPfObnD6hbvi
    oWlxAkEAmA3sxTwO0KnuuN0kyQGufXLa//uWBrtUcpzpY0T3akAoXtCepYWYCUf3
    Zl+7BLBT5x4RNFMSvo8Ue2P9fQq/hwJAE8BTayrzEOHwfzPaEU1076VGkpTjdDa6
    4GHRkuqKnyRiW6k2RVUOuj69SHhkwIWnkIrsvxxfbdGMyHlMWhmGkQJBAKfGqNrP
    TOn4wK8JPHc9+uDpFaDJLgc/pfO0g91/k2ciuxkpCx9/We+g3sBBmVXQk/vZokHM
    exhi3VobCTTlT+U=
    -----END PRIVATE KEY-----
    PEM;

    public const string PUBLIC_KEY = <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCmqBYKenTRX06lSQ8znvVnhYmZ
    s6YRkArfWtnQq33oAmntdaVR/CtkDXWQIm6R1aMRcNfd/uZBZ3bR8uco2lcqJQZV
    NrAdLD7Pwzmrzxk1yjRPRBzOjItG9U6DwbF9aeholzN1EBuMkj+wO81zRmVNDWFE
    r/d6YvSzkAkjnvAqNwIDAQAB
    -----END PUBLIC KEY-----
    PEM;
}
