#!/bin/sh
set -e

echo "Starting Invoice Ninja for Cloud Run..."

# Start PHP-FPM in the background
echo "Starting PHP-FPM..."
php-fpm8 -D

# Wait a moment for PHP-FPM to start
sleep 2

# Start nginx in the foreground
echo "Starting Nginx on port 8080..."
exec nginx -g 'daemon off;'
