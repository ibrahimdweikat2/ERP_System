#!/usr/bin/env bash
# Builds and (re)starts «دفتر» on the EC2 server. Run as ec2-user (not root) from the project:
#   bash deploy/aws/deploy.sh --first-install   # first time, on an EMPTY database: also seeds roles/accounts
#   bash deploy/aws/deploy.sh                   # every update: pulls from GitHub, rebuilds, migrates
# Never deletes data: migrations only add, and the seed step runs only with --first-install.
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIRST=false
PULL=true
for arg in "$@"; do
  case "$arg" in
    --first-install) FIRST=true ;;
    --no-pull) PULL=false ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done
[ "$(id -u)" -ne 0 ] || { echo "Run as ec2-user, not root (sudo is used where needed)." >&2; exit 1; }
[ -f "$APP_DIR/backend/.env" ] || { echo "Create backend/.env first (see deploy/aws/README.md)." >&2; exit 1; }
cd "$APP_DIR"

# Artisan runs as apache, the PHP-FPM user, so logs and caches keep one owner.
art() { sudo -u apache php "$APP_DIR/backend/artisan" "$@"; }

if $PULL; then
  echo "== Pull from GitHub =="
  git pull --ff-only
fi

echo "== Backend dependencies =="
(cd backend && composer install --no-dev --optimize-autoloader --no-interaction --no-progress)

echo "== Frontend build =="
(cd frontend && npm ci --no-audit --no-fund && NODE_OPTIONS=--max-old-space-size=1536 npm run build)

echo "== Permissions =="
sudo chown -R apache:apache backend/storage backend/bootstrap/cache
sudo chgrp apache backend/.env
sudo chmod 640 backend/.env

echo "== Database =="
art migrate --force
if $FIRST; then
  # Roles, permissions, chart of accounts, policies. No users are created.
  art db:seed --force
fi

echo "== Caches =="
art config:cache
art route:cache

echo "== Restart services =="
sudo systemctl reload php-fpm
sudo systemctl start supervisord
sudo supervisorctl reread >/dev/null
sudo supervisorctl update
sudo supervisorctl restart 'daftar:*'
sudo supervisorctl status

echo "== Health =="
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $(awk '/server_name/ {print $2; exit}' /etc/nginx/conf.d/daftar.conf | tr -d ';')" http://127.0.0.1/api/v1/health || true)
echo "API health: $code"
[ "$code" = "200" ] || { echo "Check: sudo tail -50 $APP_DIR/backend/storage/logs/laravel.log and sudo tail -50 /var/log/nginx/error.log" >&2; exit 1; }
if $FIRST; then
  echo
  echo "Create the owner account (you type the password):"
  echo "  sudo -u apache php $APP_DIR/backend/artisan erp:create-owner your@email.com"
fi
