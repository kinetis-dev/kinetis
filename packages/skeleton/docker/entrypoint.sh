#!/bin/sh
set -e

# app and bin/orbitron-mcp share one vendor volume; the lock makes the
# second install wait for the first instead of writing the same tree.
mkdir -p vendor
flock vendor/.install.lock composer install --no-interaction --no-progress

exec "$@"
