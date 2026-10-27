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

(while true; do php artisan telegram:poll || true; sleep 3; done) &
(while true; do php artisan schedule:work || true; sleep 3; done) &

PORT="${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
