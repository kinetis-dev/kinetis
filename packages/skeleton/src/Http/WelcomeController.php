<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Responses\HtmlResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class WelcomeController
{
    #[Get('/')]
    #[Hidden]
    public function index(): ResponseInterface
    {
        return HtmlResponse::create(<<<'HTML'
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Kinetis</title>
            <link rel="icon" type="image/svg+xml" href="/favicon.svg">
            <style>
                :root {
                    --amber: #D97706;
                    --amber-light: #F59E0B;
                    --red: #DC2626;
                    --bg: #ffffff;
                    --bg-card: #f8f9fb;
                    --text: #1a1c1e;
                    --text-muted: #52585f;
                    --border: #e0e2e6;
                }

                @media (prefers-color-scheme: dark) {
                    :root {
                        --bg: #131416;
                        --bg-card: #1a1c1e;
                        --text: #e9e9e9;
                        --text-muted: #a0a6ae;
                        --border: #2a2d31;
                    }
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: var(--bg);
                    color: var(--text);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    padding: 2rem 1rem;
                }

                .card {
                    max-width: 640px;
                    width: 100%;
                    text-align: center;
                }

                .logo {
                    width: 160px;
                    aspect-ratio: 400 / 420;
                    margin: 0 auto 1.5rem;
                    background-image: url('/logo.svg');
                    background-size: contain;
                    background-repeat: no-repeat;
                    background-position: center;
                }

                h1 {
                    margin: 0 0 0.5rem;
                    font-size: 1.75rem;
                    font-weight: 700;
                    color: var(--red);
                }

                p.tagline {
                    margin: 0 0 2rem;
                    color: var(--text-muted);
                    font-size: 1.05rem;
                }

                code {
                    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                    background: var(--border);
                    border-radius: 4px;
                    padding: 0.15em 0.4em;
                    font-size: 0.9em;
                }

                .features {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 1rem;
                    text-align: left;
                    margin-bottom: 1.5rem;
                }

                @media (max-width: 600px) {
                    .features {
                        grid-template-columns: 1fr;
                    }
                }

                .feature {
                    background: var(--bg-card);
                    border: 1px solid var(--border);
                    border-radius: 10px;
                    padding: 1rem 1.25rem;
                }

                .feature h3 {
                    margin: 0 0 0.35rem;
                    font-size: 0.95rem;
                    color: var(--amber);
                }

                .feature p {
                    margin: 0;
                    color: var(--text-muted);
                    font-size: 0.88rem;
                    line-height: 1.5;
                }

                .next-step {
                    color: var(--text-muted);
                    font-size: 0.95rem;
                    margin-bottom: 1.5rem;
                }

                .links a {
                    color: var(--amber);
                    text-decoration: none;
                    font-weight: 600;
                }

                .links a:hover {
                    color: var(--amber-light);
                    text-decoration: underline;
                }

                .links {
                    font-size: 0.95rem;
                }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="logo" role="img" aria-label="Kinetis"></div>
                <h1>You're running Kinetis</h1>
                <p class="tagline">A PHP framework for API-first applications on persistent-worker runtimes.</p>

                <div class="features">
                    <div class="feature">
                        <h3>Zero configuration</h3>
                        <p>Add a new controller and it's live on the next request — nothing to register by hand.</p>
                    </div>
                    <div class="feature">
                        <h3>API docs, for free</h3>
                        <p>A clickable API reference is generated automatically from your own code, always up to date.</p>
                    </div>
                    <div class="feature">
                        <h3>Built for AI agents</h3>
                        <p>AI tools like Claude can call your app's features directly, using the code you already wrote.</p>
                    </div>
                    <div class="feature">
                        <h3>Real concurrency</h3>
                        <p>Query a database, call an API, and check a cache all at once, instead of one after another.</p>
                    </div>
                    <div class="feature">
                        <h3>Catches mistakes early</h3>
                        <p>Describe your data as plain PHP classes — bad input is rejected before your code ever runs.</p>
                    </div>
                    <div class="feature">
                        <h3>Runs anywhere</h3>
                        <p>The same code runs under FrankenPHP, classic PHP-FPM (like this page), or AWS Lambda.</p>
                    </div>
                </div>

                <p class="next-step">
                    Open <code>src/Http/WelcomeController.php</code> to change this page.
                </p>

                <p class="links">
                    <a href="https://kinetis.dev/docs/">Read the documentation &rarr;</a>
                </p>
            </div>
        </body>
        </html>
        HTML);
    }
}
