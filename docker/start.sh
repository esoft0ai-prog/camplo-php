#!/bin/sh
# Container entrypoint: listen on $PORT, apply database migrations, cache config, start the minute scheduler, serve.
set -e
cd /var/www/html

# The app's own host name (everything else is a hosted page). Apache reads it as ${CAMPLO_APP_HOST}.
APP_HOST=$(printf '%s' "${APP_URL:-https://${RENDER_EXTERNAL_HOSTNAME:-localhost}}" | sed -E 's#^[a-z]+://##; s#[:/].*$##')
echo "export CAMPLO_APP_HOST=${APP_HOST}" >> /etc/apache2/envvars
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"

sed -ri "s/^Listen .*/Listen ${PORT:-10000}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT:-10000}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan camplo:seed-demo || true

# Background jobs (SLA alerts, digests, sweeps) every minute, in-process — no separate worker needed.
( while true; do php artisan schedule:run >/dev/null 2>&1 || true; sleep 60; done ) &

exec apache2-foreground
