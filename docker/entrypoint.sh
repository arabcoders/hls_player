#!/usr/bin/env sh
set -eo pipefail

VP_UID=${VP_UID:-1000}
VP_GID=${VP_GID:-1000}
VP_NO_CHOWN=${VP_NO_CHOWN:-0}

set -u

if [ "${VP_UID}" != "$(id -u www-data)" ]; then
  usermod -u ${VP_UID} www-data
fi

if [ "${VP_GID}" != "$(id -g www-data)" ]; then
  groupmod -g ${VP_GID} www-data
fi

if [ ! -f "/app/vendor/autoload.php" ]; then
  if [ ! -f "/usr/bin/composer" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
  fi
  runuser -u www-data -- composer --ansi --working-dir=/app/ -o --no-progress --no-cache install
fi

if [ 0 == "${VP_NO_CHOWN}" ]; then
  chown -R www-data:www-data /app /config
fi

cp /app/docker/php.ini "${PHP_INI_DIR}/conf.d/zz-app-custom-ini-settings.ini"
cp /app/docker/fpm.conf "${PHP_INI_DIR}/../php-fpm.d/zzz-app-pool-settings.conf"

echo "Starting Nginx server.."
nginx

echo "Starting Redis server.."
redis-server --daemonize yes

# first arg is `-f` or `--some-option`
if [ "${1#-}" != "$1" ]; then
  set -- php-fpm "$@"
fi

exec "$@"
