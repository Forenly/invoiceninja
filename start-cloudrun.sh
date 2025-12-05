#!/bin/sh
set -e

cd /var/www/app

# Clear Laravel caches on startup
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisord.conf
