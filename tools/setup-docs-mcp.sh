#!/usr/bin/env bash
#
# One-time setup for a standalone Kinetis docs MCP server: installs
# kinetis/framework from Packagist into its own small directory (never
# this monorepo) and registers it with Claude Code. KinetisDocsResource
# falls back to fetching docs straight from GitHub when it finds no
# local docs/ directory, so this scratch install works with zero
# checkout of kinetis-dev/kinetis at all.
set -euo pipefail

INSTALL_DIR="${KINETIS_MCP_DIR:-$HOME/.kinetis-mcp}"
SERVER_NAME="kinetis"

echo "Checking prerequisites..."

if ! command -v php >/dev/null 2>&1; then
    echo "  php: not found on PATH. Install PHP 8.4+ first." >&2
    exit 1
fi

if ! php -r 'exit(version_compare(PHP_VERSION, "8.4.0", ">=") ? 0 : 1);'; then
    echo "  php: $(php -r 'echo PHP_VERSION;') found, but Kinetis needs 8.4 or newer." >&2
    exit 1
fi
echo "  php: $(php -r 'echo PHP_VERSION;') - OK"

if ! command -v composer >/dev/null 2>&1; then
    echo "  composer: not found on PATH. Install it from https://getcomposer.org" >&2
    exit 1
fi
echo "  composer: found - OK"

if ! docker info >/dev/null 2>&1; then
    echo "  docker: not running (or not installed) - the registered server needs it to run php:8.4-cli-alpine." >&2
    exit 1
fi
echo "  docker: running - OK"

if ! command -v claude >/dev/null 2>&1; then
    echo "  claude CLI: not found on PATH - can't register the MCP server." >&2
    exit 1
fi
echo "  claude CLI: found - OK"

echo
echo "Installing kinetis/framework into ${INSTALL_DIR}..."
mkdir -p "$INSTALL_DIR"
cat > "$INSTALL_DIR/composer.json" <<'EOF'
{
    "require": {
        "kinetis/framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
EOF

# A real (if currently unused) autoload.psr-4 entry, not just a bare
# require — Kinetis\Cache\NamespaceScanner warns on stderr whenever a
# project's composer.json has none at all, since discovery would
# silently find nothing; this scratch install has no App\ classes of
# its own, but declaring the entry stops that warning from firing on
# every server start.
#
# Composer's own output is captured, not streamed — it's a Composer
# PHAR built years before PHP 8.4/8.5 existed, so it emits a wall of
# "Deprecation Notice" lines under a current PHP that have nothing to
# do with this install succeeding or failing. Shown in full only if the
# install actually fails.
if ! COMPOSER_LOG=$(composer install --no-interaction --prefer-dist --working-dir="$INSTALL_DIR" 2>&1); then
    echo "composer install failed:" >&2
    echo "$COMPOSER_LOG" >&2
    exit 1
fi

echo
echo "Registering \"${SERVER_NAME}\" with Claude Code (user scope, every project)..."
claude mcp remove "$SERVER_NAME" -s user >/dev/null 2>&1 || true
claude mcp add "$SERVER_NAME" -s user -- docker run --rm -i \
    -v "${INSTALL_DIR}:/app" \
    -w /app \
    -e APP_ENV=development \
    php:8.4-cli-alpine php vendor/bin/kinetis mcp:serve

echo
echo "Verifying the server actually responds..."
RESPONSE=$(printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"setup-docs-mcp","version":"1.0"}}}' \
    | docker run --rm -i -v "${INSTALL_DIR}:/app" -w /app -e APP_ENV=development \
        php:8.4-cli-alpine php vendor/bin/kinetis mcp:serve)

if [[ "$RESPONSE" != *'"serverInfo"'* ]]; then
    echo "Registered, but the verification handshake didn't return the expected result:" >&2
    echo "$RESPONSE" >&2
    exit 1
fi

echo "OK - the server responded correctly."
echo
echo "Done. Start a new Claude Code session in this project to use \"${SERVER_NAME}\"."
