#!/bin/sh
# Container entrypoint: listen on $PORT, apply database migrations, cache config, start the minute scheduler, serve.
set -e
cd /var/www/html

sed -ri "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan migrate --force

# Background jobs (SLA alerts, digests, sweeps) every minute, in-process — no separate worker needed.
( while true; do php artisan schedule:run >/dev/null 2>&1 || true; sleep 60; done ) &

exec apache2-foreground
