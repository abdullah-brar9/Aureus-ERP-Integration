#!/bin/sh
set -eu

cd /var/www/html
umask 0002

mkdir -p \
    bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

chown www-data:www-data bootstrap/cache storage \
    storage/app storage/app/private storage/app/public \
    storage/framework storage/framework/cache storage/framework/cache/data \
    storage/framework/sessions storage/framework/testing storage/framework/views \
    storage/logs

chmod ug+rwX bootstrap/cache storage \
    storage/app storage/app/private storage/app/public \
    storage/framework storage/framework/cache storage/framework/cache/data \
    storage/framework/sessions storage/framework/testing storage/framework/views \
    storage/logs

if [ "${1:-}" = "php-fpm" ]; then
    exec "$@"
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; then
    exec gosu www-data "$@"
fi

exec "$@"
