<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

/**
 * A real, valid RSA key pair generated solely for this test suite — not
 * a real credential.
 */
final class RsaKeyPair
{
    public const string PRIVATE_KEY = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCuNuNA0Sqik6nj
    HUg7gfs/aR1lKKlQDg2dc7v0lL2wnQRV2cXz8mFj3w3JfZfxn54BNjEotyGoSCGZ
    ciAqpgVU8mVaWoOQRYFy1byeqP7qfACgUt90EBLnmRa/DuC8U7Q2DVc84WpOBaWD
    z/pSsdV/FzuCIek0aO9GkfkHGDhv66pQdOXG1SJ1cdbT0oOOddxhUPZ2RVNoh1Mf
    MPPlq0HijSkn0RgZvYu/cALySuamiFKOoSUUXgpi4Uo9biJ9svusPGU3QKBO2PB7
    w3BicqOh0J1jABT2dGQb/WKs0AZrxDSqaSbKew05SiXErl5xhnaY2zlBVTbnW2HJ
    FlbvTnefAgMBAAECggEAFUxljRDlVE9Bh6AWqsZx0nybLjkB4Lp5xKRCYsePx0NI
    T+dEsasSEqp8M4Qfatf3nQX54unnUKenipcZbU1dvSxjOArKfH1a3ZCu3iYiqsUd
    Mycz5VW/vJZf4bIZHJJj0kXgQp5Q/O0+ISFwj40+i3/qHmHfR7Ev1jdWCXLXF4Dg
    MVbUJe0CWTBaaxpepoD6EBEVi6qGXoq/cVw8sQVlEzJ7qsn9T5bXRRLmHSUgNzGE
    OYZPoXQ51VC1zD1lpDAW4Tpo1nHCfkpLwdrGNeH9aPe36KkIotCMHmON6JZl50ZM
    0nNZMJMA9l7RSgl98bfEPzSFAzQQkywiitOs0G4SDQKBgQDolFD2GwRwpJZoatVJ
    M83NbKrGCl+t+ADnT+f+pFROc8Zw2QLjy56Hn4BJmYfDc7Az8Gz9BvUfNVkflTb9
    h+57rGcKYrGTgw2wSNmmA53MkaoJ2XgjM2p/+Wgws1pjMyZ1n5e0u3t1yxJn2nXl
    TIElSE1T7OpIr6ONOsZ6IkezJQKBgQC/wfnk1jnGJYb69IGl1QFH1UYFwMYSYY1W
    zxD0yfzcYHiHTpZkhcwFgbfjZLRVHMuwE1CtxBCMQRWz0h0LOyn7F7fvpLFFnV61
    2C+r5lYUWkIEEDVCuevah8iJdUx37qBPV8BuqIIRuoOa+bKIlMI9+snGxZI2Evj7
    plEGM4OmcwKBgDPApQ9OTbue9BUCCLnEPDxEvO9aaZX1hIX0IuRnvpbCblq3/0uf
    CISXOl2mOy6DtKaqDiZzgOHT5iP/+P+LWsbMQxVthqQTWl1qqHunfFqD4zlT2cbD
    byRQ5B1KG4fNXvZ3b7N4sG0ypcLUOr2uO2KHZyWQp0VLR/JqLLYKoLe9AoGAfJY3
    GirllovDS0GZCnl+P4Gd4RcCmeavwfr+9UxW8YfsR10T8XPMvrctFpzTXYk7/cZO
    4NdGORoAU7jsDeP+vpkGdLj4RFaetl8jefhJbSfHRISTVisdxfn6nPSNHk738RT+
    fecVuxcHcqVRDdQ477QFbRPojyF8i2PfuLu3iWkCgYEAoSfGIgDkd+1iQUyIDRDn
    fw9KLZHPTpuawub3O+umurChK/4Kog2q0HABScqIVH/+1xlXAYbR22+ZxRCPL8AL
    RsPEdlfYKBFLczy1HnnUJnLsFBKThBIWJ5/qGuz9r0gbkgpgv/ehZnXjTKXhRLrr
    ZQdJ+HNE3Avg7TLvn2iQAUk=
    -----END PRIVATE KEY-----
    PEM;

    public const string PUBLIC_KEY = <<<'PEM'
    -----BEGIN PUBLIC KEY-----
    MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEArjbjQNEqopOp4x1IO4H7
    P2kdZSipUA4NnXO79JS9sJ0EVdnF8/JhY98NyX2X8Z+eATYxKLchqEghmXIgKqYF
    VPJlWlqDkEWBctW8nqj+6nwAoFLfdBAS55kWvw7gvFO0Ng1XPOFqTgWlg8/6UrHV
    fxc7giHpNGjvRpH5Bxg4b+uqUHTlxtUidXHW09KDjnXcYVD2dkVTaIdTHzDz5atB
    4o0pJ9EYGb2Lv3AC8krmpohSjqElFF4KYuFKPW4ifbL7rDxlN0CgTtjwe8NwYnKj
    odCdYwAU9nRkG/1irNAGa8Q0qmkmynsNOUolxK5ecYZ2mNs5QVU251thyRZW7053
    nwIDAQAB
    -----END PUBLIC KEY-----
    PEM;
}
