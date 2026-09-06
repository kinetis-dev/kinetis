<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for MailerFactory — a genuine SMTP
 * send through a real Mailpit container, confirmed by reading the
 * message back through Mailpit's own HTTP API rather than trusting a
 * non-throwing send() call alone.
 *
 * Mailpit speaks unauthenticated plaintext SMTP on loopback, which is
 * exactly what production policy refuses, so this configuration opts into
 * the local-insecure profile the same way a developer's own `.env` has
 * to: `APP_ENV=development` plus `MAILER_ALLOW_INSECURE_LOCAL=true`. The
 * checks below prove the opt-in is required rather than incidental.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
use Kinetis\Mailer\Exception\MailerConfigurationException;
use Kinetis\Mailer\MailerFactory;
use Symfony\Component\Mime\Email;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

$mailpitHost = getenv('MAILPIT_HOST') ?: '127.0.0.1';
$dsn = "smtp://{$mailpitHost}:1025";

try {
    MailerFactory::fromConfig(new Config(['MAILER_DSN' => $dsn]));
    check('plaintext loopback SMTP is refused without the profile', false);
} catch (MailerConfigurationException $e) {
    check('plaintext loopback SMTP is refused without the profile', true);
    check('the refusal quotes no DSN', !str_contains($e->getMessage(), $mailpitHost));
}

try {
    MailerFactory::fromConfig(new Config([
        'APP_ENV' => 'production',
        'MAILER_DSN' => $dsn,
        'MAILER_ALLOW_INSECURE_LOCAL' => 'true',
    ]));
    check('the profile is refused outside development', false);
} catch (MailerConfigurationException) {
    check('the profile is refused outside development', true);
}

$config = new Config([
    'APP_ENV' => 'development',
    'MAILER_DSN' => $dsn,
    'MAILER_ALLOW_INSECURE_LOCAL' => 'true',
]);

$mailer = MailerFactory::fromConfig($config);

$email = new Email()
    ->from('noreply@kinetis.dev')
    ->to('developer@example.com')
    ->subject('Kinetis mailer integration check')
    ->text('This is a real end-to-end send through MailerFactory.');

$mailer->send($email);

sleep(1);

$response = file_get_contents("http://{$mailpitHost}:8025/api/v1/messages");
$data = json_decode((string) $response, true, flags: JSON_THROW_ON_ERROR);

check('exactly one message received', $data['messages_count'] === 1);

$message = $data['messages'][0];
check('subject matches', $message['Subject'] === 'Kinetis mailer integration check');
check('from matches', $message['From']['Address'] === 'noreply@kinetis.dev');
check('to matches', $message['To'][0]['Address'] === 'developer@example.com');

echo "ALL CHECKS PASSED\n";
