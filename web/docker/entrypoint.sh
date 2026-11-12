#!/bin/sh
# Prepares the writable directories and the framework caches, then runs the
# given command. The directories may be empty volumes, so they are created
# here and nothing is expected to survive from the image.
set -e
cd /app

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
    storage/logs storage/app/private bootstrap/cache

php artisan package:discover --ansi

# Commands such as "php artisan migrate" skip the caches: they are for serving.
if [ "$1" = "frankenphp" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
