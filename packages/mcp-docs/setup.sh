#!/usr/bin/env bash
#
# Installs kinetis/mcp-docs from Packagist into its own directory and
# registers it as an MCP server with one client. Nothing here touches
# the Kinetis monorepo, and nothing needs PHP or Composer on the host:
# both the install and the registered server run through the composer:2
# image.
#
#   ./setup.sh          register with Claude Code
#   ./setup.sh codex    register with Codex
#
# It is self-contained, so the same two forms work straight from GitHub:
#
#   curl -fsSL .../packages/mcp-docs/setup.sh | bash
#   curl -fsSL .../packages/mcp-docs/setup.sh | bash -s codex
set -euo pipefail

SERVER_NAME="kinetis-docs"
INSTALL_DIR="${KINETIS_MCP_DOCS_DIR:-$HOME/.kinetis-mcp-docs}"
# Written into every directory this script installs into, and what
# proves an existing non-empty directory is one of ours before anything
# in it is rewritten. A mistyped KINETIS_MCP_DOCS_DIR pointing at real
# work is refused rather than having a composer.json dropped into it.
MARKER_FILE="$INSTALL_DIR/.kinetis-mcp-docs"
MARKER_TEXT="kinetis/mcp-docs install directory - safe for this script to rewrite"

case "$#:${1:-}" in
    0:)      CLIENT="claude" ;;
    1:codex) CLIENT="codex" ;;
    *)
        echo "Usage: $0 [codex]" >&2
        echo "  no argument   register the docs server with Claude Code" >&2
        echo "  codex         register it with Codex instead" >&2
        exit 1
        ;;
esac

echo "Checking prerequisites..."

if ! docker info >/dev/null 2>&1; then
    echo "  docker: not running (or not installed) - both the install step and the registered server run through it." >&2
    exit 1
fi
echo "  docker: running - OK"

if ! command -v "$CLIENT" >/dev/null 2>&1; then
    echo "  ${CLIENT} CLI: not found on PATH - can't register the MCP server." >&2
    exit 1
fi
echo "  ${CLIENT} CLI: found - OK"

# Every container below runs as the invoking user, so nothing written
# into the install directory ends up owned by root. That leaves the
# container with no home directory of its own, hence a COMPOSER_HOME and
# a HOME under /tmp, which is writable whatever the uid.
DOCKER_RUN=(docker run --rm
    --user "$(id -u):$(id -g)"
    -v "${INSTALL_DIR}:/app"
    -w /app
    -e COMPOSER_HOME=/tmp/composer
    -e HOME=/tmp)

if [ -e "$INSTALL_DIR" ] && [ ! -d "$INSTALL_DIR" ]; then
    echo "${INSTALL_DIR} exists and is not a directory. Set KINETIS_MCP_DOCS_DIR to somewhere else." >&2
    exit 1
fi

# Ownership is the marker's content, not its name: a regular file
# holding exactly MARKER_TEXT. A directory that happens to hold
# something under that name is somebody else's, and a symlink there
# would carry both this check and the write that follows it out of the
# directory.
marker_is_ours() {
    [ ! -L "$MARKER_FILE" ] && [ -f "$MARKER_FILE" ] \
        && printf '%s\n' "$MARKER_TEXT" | cmp -s - "$MARKER_FILE"
}

if [ -d "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ] && ! marker_is_ours; then
    echo "${INSTALL_DIR} is not empty and carries no marker written by this script." >&2
    echo "Empty it, or set KINETIS_MCP_DOCS_DIR to a directory this script may own." >&2
    exit 1
fi

echo
echo "Installing kinetis/mcp-docs into ${INSTALL_DIR}..."
mkdir -p "$INSTALL_DIR"
echo "$MARKER_TEXT" > "$MARKER_FILE"

# composer.json is rewritten on every run, and composer install refuses
# a lock file that no longer matches it. Dropping this one file lets
# Composer resolve fresh and reconcile vendor/ itself.
rm -f "$INSTALL_DIR/composer.lock"
cat > "$INSTALL_DIR/composer.json" <<'EOF'
{
    "require": {
        "kinetis/mcp-docs": "^1.0"
    }
}
EOF

if ! COMPOSER_LOG=$("${DOCKER_RUN[@]}" composer:2 install --no-interaction --prefer-dist 2>&1); then
    echo "composer install failed:" >&2
    echo "$COMPOSER_LOG" >&2
    exit 1
fi

echo
echo "Verifying the server responds..."

# Four messages down one stdin, in the order a client sends them. The
# notification in the middle is part of the check: a correct server
# answers it with nothing, so three responses come back, not four.
VERIFICATION=$(printf '%s\n' \
    '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"kinetis-mcp-docs-setup","version":"1.0"}}}' \
    '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
    '{"jsonrpc":"2.0","id":2,"method":"resources/list"}' \
    '{"jsonrpc":"2.0","id":3,"method":"resources/read","params":{"uri":"kinetis://docs/index"}}' \
    | "${DOCKER_RUN[@]}" -i composer:2 php vendor/bin/kinetis-mcp-docs)

verification_failed() {
    echo "The server did not answer the verification handshake as expected: $1" >&2
    echo "$VERIFICATION" >&2
    exit 1
}

[ "$(printf '%s\n' "$VERIFICATION" | grep -c .)" = "3" ] || verification_failed "expected three responses"
printf '%s' "$VERIFICATION" | grep -q '"serverInfo"' || verification_failed "initialize returned no serverInfo"
printf '%s' "$VERIFICATION" | grep -q '"resources"' || verification_failed "resources/list returned no resources"
printf '%s' "$VERIFICATION" | grep -q '"contents"' || verification_failed "resources/read returned no contents"

echo "OK - the server responded correctly."

echo
echo "Registering \"${SERVER_NAME}\" with ${CLIENT}..."

# start.sh, out of the package just installed, is what the client
# spawns: it runs the once-a-day update check - locked, since every
# session shares this one directory - and then hands stdin and stdout
# to the server. Running it from vendor/ rather than from a copy means
# that same update carries changes to it too.
SERVER_COMMAND=("${DOCKER_RUN[@]}" -i composer:2 sh vendor/kinetis/mcp-docs/start.sh)

if [ "$CLIENT" = "claude" ]; then
    claude mcp remove "$SERVER_NAME" -s user >/dev/null 2>&1 || true
    claude mcp add "$SERVER_NAME" -s user -- "${SERVER_COMMAND[@]}"
else
    codex mcp remove "$SERVER_NAME" >/dev/null 2>&1 || true
    codex mcp add "$SERVER_NAME" -- "${SERVER_COMMAND[@]}"
fi

echo
echo "Done. Start a new ${CLIENT} session to use \"${SERVER_NAME}\"."
