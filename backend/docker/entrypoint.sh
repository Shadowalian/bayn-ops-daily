#!/bin/sh
set -e

# Render / Railway style DATABASE_URL → Laravel DB_URL
if [ -n "${DATABASE_URL:-}" ]; then
  export DB_URL="$DATABASE_URL"
  export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
fi

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is missing"
  exit 1
fi

php artisan config:clear || true
php artisan migrate --force
php artisan db:seed --force

# Single bot poller (avoid Telegram 409 conflicts from overlapping processes)
php artisan telegram:poll > /proc/1/fd/1 2>/proc/1/fd/2 &
php artisan schedule:work > /proc/1/fd/1 2>/proc/1/fd/2 &

PORT="${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
