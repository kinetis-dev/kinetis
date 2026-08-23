#!/bin/sh
set -e

composer install --no-interaction --no-progress

exec "$@"
