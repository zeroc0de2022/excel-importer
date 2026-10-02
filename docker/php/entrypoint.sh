#!/bin/sh
set -e

# Cache config, routes and events at container start: the environment is only known at runtime,
# and each container (app, queue, scheduler, reverb) has its own bootstrap/cache.
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan event:cache --quiet

exec "$@"
