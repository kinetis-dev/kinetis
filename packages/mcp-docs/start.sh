#!/bin/sh
#
# The command setup.sh registers with the MCP client: look for a newer
# release, then hand stdin and stdout to the server. It runs on every
# session start, in every project, whether or not that session ever
# calls the server, so the check happens at most once a day, from a
# timestamp in the install directory - a composer update costs a couple
# of seconds even when nothing has changed.
#
# One install directory serves every client and every project, so
# several copies of this script - and the setup script that installs
# into it - can run against it at once. Reading the timestamp and
# running the update hold an exclusive lock on the same file setup.sh
# holds through its own install, and a spawn that finds it taken waits:
# whichever process arrives second reads a finished vendor tree rather
# than one halfway through being replaced, and sees the timestamp the
# first one wrote rather than updating again behind it.
#
# The lock is an open file descriptor, so the kernel drops it when the
# last process holding it ends - an update killed part-way leaves
# nothing to clean up. It is closed before the server is exec'd, so a
# session lasting hours does not hold off the next day's update.
#
# Anything this script puts on stdout would be read as a JSON-RPC frame,
# so the update reports to stderr.
set -eu

UPDATE_INTERVAL=86400
STAMP_FILE=.last-update-check
LOCK_FILE=.update.lock

exec 9>"$LOCK_FILE"
flock 9

now=$(date +%s)
last=$(cat "$STAMP_FILE" 2>/dev/null || echo 0)

if [ $((now - last)) -gt "$UPDATE_INTERVAL" ]; then
    # A failed update - no network, say - leaves the timestamp alone and
    # starts the installed server anyway, so the next spawn retries
    # rather than waiting out the rest of the window.
    if composer update kinetis/mcp-docs --with-all-dependencies --no-interaction --prefer-dist 1>&2; then
        echo "$now" > "$STAMP_FILE"
    fi
fi

exec 9>&-

exec php vendor/bin/kinetis-mcp-docs
