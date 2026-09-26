#!/bin/sh
# Prepares the Laravel container, then runs its command (php-fpm, a queue worker or the scheduler).
set -e
cd /var/www/html

as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is empty. Copy it from backend/.env into .env: encrypted data (bank accounts, sessions) needs the same key." >&2
  exit 1
fi

# The storage volume starts empty on first run.
mkdir -p storage/app/public storage/app/backups storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
tries=0
until php /usr/local/bin/erp-db-check.php ping; do
  tries=$((tries + 1))
  # After a minute, say why (wrong password, firewall, TLS) instead of waiting silently.
  if [ "$tries" -eq 30 ]; then ERP_DB_CHECK_VERBOSE=1 php /usr/local/bin/erp-db-check.php ping || true; tries=0; fi
  sleep 2
done

as_www php artisan config:cache
as_www php artisan route:cache

# Only the app container changes the schema, so workers never race it.
if [ "${ERP_ROLE:-app}" = "app" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  as_www php artisan migrate --force
  # Roles, permissions, accounts and policies on a brand-new database only. No users are created.
  if [ "$(php /usr/local/bin/erp-db-check.php roles)" = "0" ]; then
    as_www php artisan db:seed --force
  fi
fi

# php-fpm starts as root and drops its workers to www-data itself.
if [ "$1" = "php-fpm" ]; then
  exec "$@"
fi
exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
