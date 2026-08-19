#!/usr/bin/env bash
#
# One-time setup for a standalone Kinetis docs MCP server: installs
# kinetis/mcp (which brings kinetis/framework with it) from Packagist
# into its own small directory (never this monorepo) and registers it
# with Claude Code. KinetisDocsResource falls back to fetching docs
# straight from GitHub when it finds no local docs/ directory, so this
# scratch install works with zero checkout of kinetis-dev/kinetis at
# all.
set -euo pipefail

INSTALL_DIR="${KINETIS_MCP_DIR:-$HOME/.kinetis-mcp}"
SERVER_NAME="kinetis"

echo "Checking prerequisites..."

if ! docker info >/dev/null 2>&1; then
    echo "  docker: not running (or not installed) - both the install step and the registered server run through it." >&2
    exit 1
fi
echo "  docker: running - OK"

if ! command -v claude >/dev/null 2>&1; then
    echo "  claude CLI: not found on PATH - can't register the MCP server." >&2
    exit 1
fi
echo "  claude CLI: found - OK"

echo
echo "Installing kinetis/mcp into ${INSTALL_DIR}..."
mkdir -p "$INSTALL_DIR"
# A rerun rewrites composer.json below, and composer install refuses a
# lock file that no longer matches it. Dropping the lock makes Composer
# resolve fresh and reconcile vendor/ on its own; deleting only this
# one file keeps the script safe against a mistyped KINETIS_MCP_DIR.
rm -f "$INSTALL_DIR/composer.lock"
cat > "$INSTALL_DIR/composer.json" <<'EOF'
{
    "require": {
        "kinetis/mcp": "^1.0"
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
# Runs through the composer:2 image rather than a host-installed PHP/
# Composer — nothing here needs either on the host at all. Output is
# captured, not streamed, and shown in full only if the install
# actually fails.
if ! COMPOSER_LOG=$(docker run --rm -v "${INSTALL_DIR}:/app" -w /app composer:2 install --no-interaction --prefer-dist 2>&1); then
    echo "composer install failed:" >&2
    echo "$COMPOSER_LOG" >&2
    exit 1
fi

echo
echo "Registering \"${SERVER_NAME}\" with Claude Code (user scope, every project)..."
claude mcp remove "$SERVER_NAME" -s user >/dev/null 2>&1 || true

# The registered command runs on every Claude Code session start, in
# every project, whether or not that session ever actually calls into
# it — so it stays on composer:2 (which already bundles PHP new enough
# to run Kinetis directly) rather than a second, different image, and
# the version-freshness check only runs at most once every 24h (a
# .last-update-check timestamp inside INSTALL_DIR, not the host), not
# on every single spawn: a `composer update` check costs a couple of
# seconds even when nothing changed, not worth paying on every session
# start for something that's rarely actually stale. Update output is
# redirected to stderr — anything on stdout past this point has to be
# valid MCP JSON-RPC, and Composer knows nothing about that protocol.
# A failed update check (e.g. no network) is silently skipped, not
# fatal, and the timestamp is only advanced on success, so the next
# spawn retries rather than waiting out the rest of the 24h window.
# shellcheck disable=SC2016 # single-quoted on purpose: this is a script
# for the container's own sh, not this outer script — $now/$last must
# NOT expand here, only when sh -c actually runs it inside the container.
claude mcp add "$SERVER_NAME" -s user -- docker run --rm -i \
    -v "${INSTALL_DIR}:/app" \
    -w /app \
    -e APP_ENV=development \
    composer:2 sh -c 'now=$(date +%s); last=$(cat .last-update-check 2>/dev/null || echo 0); if [ $((now - last)) -gt 86400 ]; then composer update kinetis/mcp --with-all-dependencies --no-interaction --prefer-dist 1>&2 && echo "$now" > .last-update-check; fi; exec php vendor/bin/kinetis mcp:serve'

echo
echo "Verifying the server actually responds..."
RESPONSE=$(printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"setup-docs-mcp","version":"1.0"}}}' \
    | docker run --rm -i -v "${INSTALL_DIR}:/app" -w /app -e APP_ENV=development \
        composer:2 php vendor/bin/kinetis mcp:serve)

if [[ "$RESPONSE" != *'"serverInfo"'* ]]; then
    echo "Registered, but the verification handshake didn't return the expected result:" >&2
    echo "$RESPONSE" >&2
    exit 1
fi

echo "OK - the server responded correctly."
echo
echo "Done. Start a new Claude Code session in this project to use \"${SERVER_NAME}\"."
