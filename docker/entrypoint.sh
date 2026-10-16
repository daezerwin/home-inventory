#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY is not set. Generate one with:"
    echo "  docker run --rm php:8.4-cli php -r \"echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;\""
    echo "and set it as the APP_KEY environment variable."
    exit 1
fi

DB_FILE="${DB_DATABASE:-/var/lib/home-inventory/database.sqlite}"
mkdir -p "$(dirname "$DB_FILE")" \
    storage/app/public/products \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
touch "$DB_FILE"

php artisan migrate --force
php artisan storage:link --force >/dev/null

# Import the bundled inventory.csv once, into an empty catalog, so admin edits
# and later CSV imports are never overwritten on restart. Photos are queued
# and downloaded by the worker that supervisord starts next.
IMPORTED_MARKER="$(dirname "$DB_FILE")/catalog-imported"
if [ ! -f "$IMPORTED_MARKER" ]; then
    php artisan inventory:bootstrap
    touch "$IMPORTED_MARKER"
fi

php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php artisan view:cache >/dev/null

chown -R www-data:www-data "$(dirname "$DB_FILE")" storage bootstrap/cache

exec "$@"
