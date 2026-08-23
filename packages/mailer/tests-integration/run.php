<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for MailerFactory — a genuine SMTP
 * send through a real Mailpit container, confirmed by reading the
 * message back through Mailpit's own HTTP API rather than trusting a
 * non-throwing send() call alone.
 */

require __DIR__ . '/../vendor/autoload.php';

use Kinetis\Config\Config;
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

$config = new Config(['MAILER_DSN' => "smtp://{$mailpitHost}:1025"]);
$mailer = MailerFactory::fromConfig($config);

$email = (new Email())
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
