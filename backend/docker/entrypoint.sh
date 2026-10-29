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

# Prefer Render's public URL for APP_URL / Telegram webhook
if [ -z "${APP_URL:-}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
  export APP_URL="$RENDER_EXTERNAL_URL"
fi

php artisan config:clear || true
php artisan migrate --force

# Optional one-shot seed (never reset passwords on every wake)
if [ "${SEED_ON_BOOT:-0}" = "1" ]; then
  php artisan db:seed --force
fi

# Drop old demo members (Hana/Abebe/Sara/Yonas) if present
php artisan ops:purge-demo-members --hard || true

php artisan config:cache
php artisan route:cache || true
php artisan event:cache || true

# Production: webhook only (long-poll starves the free web dyno)
if [ -n "${TELEGRAM_BOT_TOKEN:-}" ]; then
  if [ "${TELEGRAM_USE_POLLING:-0}" = "1" ]; then
    php artisan telegram:webhook --delete || true
    php artisan telegram:poll > /proc/1/fd/1 2>/proc/1/fd/2 &
  else
    php artisan telegram:webhook --set || true
  fi
fi

php artisan schedule:work > /proc/1/fd/1 2>/proc/1/fd/2 &

PORT="${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="$PORT"
