#!/usr/bin/env sh
set -eou pipefail

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Starting Cache Server."
redis-server --daemonize yes

TIME_DATE=$(date +"%Y-%m-%dT%H:%M:%S%z")
echo "[${TIME_DATE}] Starting HTTP Server."
caddy start --config /etc/caddy/Caddyfile

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm81 "$@"
fi

exec "$@"
