#!/usr/bin/env bash
# One-time preparation of an Amazon Linux 2023 EC2 server for «دفتر» (no Docker).
# Run from the cloned repository (it must live under /var/www, not the home folder):
#   sudo bash deploy/aws/install.sh <domain or server public IP>
# Installs: Nginx, PHP 8.3-FPM, MySQL 8.4 (official), Node 22, Composer, Supervisor, certbot;
# writes their configuration and sets file permissions. Safe to run again.
set -euo pipefail

DOMAIN="${1:?Usage: sudo bash deploy/aws/install.sh <domain or server public IP>}"
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEPLOY_USER="${SUDO_USER:-ec2-user}"
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo." >&2; exit 1; }
case "$APP_DIR" in
  /home/*) echo "Clone the project under /var/www (e.g. /var/www/daftar): Nginx cannot read home folders." >&2; exit 1 ;;
esac
echo "Project: $APP_DIR | domain: $DOMAIN | deploy user: $DEPLOY_USER"

echo "== 1/8 Swap (small instances) =="
mem_kb=$(awk '/MemTotal/ {print $2}' /proc/meminfo)
if [ "$mem_kb" -lt 3000000 ] && ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap defaults 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/99-daftar.conf
  sysctl -q --system
fi

echo "== 2/8 Packages =="
dnf -y install nginx git tar unzip python3-pip certbot python3-certbot-nginx nodejs22 nodejs22-npm \
  php8.3 php8.3-fpm php8.3-cli php8.3-common php8.3-mysqlnd php8.3-pdo php8.3-mbstring php8.3-xml \
  php8.3-bcmath php8.3-intl php8.3-gd php8.3-zip php8.3-opcache php8.3-process

echo "== 3/8 MySQL 8.4 (official repository; the nightly backup needs Oracle's mysqldump) =="
if ! rpm -q mysql-community-server >/dev/null 2>&1; then
  dnf -y install https://dev.mysql.com/get/mysql84-community-release-el9-2.noarch.rpm
  # The repository is built for EL9; Amazon Linux 2023 reports release 2023.
  sed -i 's/\$releasever/9/g' /etc/yum.repos.d/mysql-community*.repo
  dnf -y install mysql-community-server
fi
if ! grep -q 'daftar settings' /etc/my.cnf; then
  cat >> /etc/my.cnf <<'EOF'

# daftar settings: triggers protect posted documents; binary logging otherwise refuses them.
[mysqld]
log_bin_trust_function_creators=1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
bind-address=127.0.0.1
EOF
  if [ "$mem_kb" -lt 3000000 ]; then
    printf 'innodb_buffer_pool_size=128M\nperformance_schema=OFF\nmax_connections=40\n' >> /etc/my.cnf
  fi
fi
systemctl enable --now mysqld

echo "== 4/8 PHP-FPM =="
install -m 644 "$APP_DIR/deploy/aws/php-daftar.ini" /etc/php.d/99-daftar.ini
systemctl enable --now php-fpm
systemctl restart php-fpm

echo "== 5/8 Composer =="
if ! command -v composer >/dev/null 2>&1; then
  tmp=$(mktemp -d)
  php -r "copy('https://getcomposer.org/installer', '$tmp/composer-setup.php');"
  expected=$(curl -fsSL https://composer.github.io/installer.sig)
  actual=$(php -r "echo hash_file('sha384', '$tmp/composer-setup.php');")
  [ "$expected" = "$actual" ] || { echo "Composer installer checksum mismatch." >&2; exit 1; }
  php "$tmp/composer-setup.php" --quiet --install-dir=/usr/local/bin --filename=composer
  rm -rf "$tmp"
fi

echo "== 6/8 Nginx =="
sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__APP_DIR__#$APP_DIR#g" "$APP_DIR/deploy/aws/nginx-daftar.conf" > /etc/nginx/conf.d/daftar.conf
nginx -t
systemctl enable --now nginx
systemctl reload nginx

echo "== 7/8 Supervisor =="
if [ ! -x /opt/supervisor/bin/supervisord ]; then
  python3 -m venv /opt/supervisor
  /opt/supervisor/bin/pip install --quiet supervisor
fi
ln -sf /opt/supervisor/bin/supervisorctl /usr/local/bin/supervisorctl
mkdir -p /etc/supervisor/conf.d /var/log/supervisor
cat > /etc/supervisord.conf <<'EOF'
[unix_http_server]
file=/run/supervisor.sock
chmod=0700

[supervisord]
logfile=/var/log/supervisor/supervisord.log
pidfile=/run/supervisord.pid

[rpcinterface:supervisor]
supervisor.rpcinterface_factory = supervisor.rpcinterface:make_main_rpcinterface

[supervisorctl]
serverurl=unix:///run/supervisor.sock

[include]
files = /etc/supervisor/conf.d/*.conf
EOF
sed "s#__APP_DIR__#$APP_DIR#g" "$APP_DIR/deploy/aws/supervisor-daftar.conf" > /etc/supervisor/conf.d/daftar.conf
cat > /etc/systemd/system/supervisord.service <<'EOF'
[Unit]
Description=Supervisor (daftar queue workers and scheduler)
After=network.target mysqld.service php-fpm.service

[Service]
ExecStart=/opt/supervisor/bin/supervisord -n -c /etc/supervisord.conf
ExecReload=/opt/supervisor/bin/supervisorctl -c /etc/supervisord.conf reload
Restart=on-failure

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
# Started by deploy.sh once the application is built and configured.
systemctl enable supervisord

echo "== 8/8 Permissions =="
# Code belongs to the deploy user; only storage and cache are writable by the web app (apache).
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$APP_DIR"
chown -R apache:apache "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
# Group-writable with the group inherited, so ec2-user (member of apache) can also write there.
chmod -R g+rwX "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
find "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache" -type d -exec chmod g+s {} +
chmod 755 "$(dirname "$APP_DIR")" "$APP_DIR"
usermod -aG apache "$DEPLOY_USER"
if command -v getenforce >/dev/null 2>&1 && [ "$(getenforce)" = "Enforcing" ]; then
  chcon -R -t httpd_sys_content_t "$APP_DIR"
  chcon -R -t httpd_sys_rw_content_t "$APP_DIR/backend/storage" "$APP_DIR/backend/bootstrap/cache"
  setsebool -P httpd_can_network_connect_db 1
fi

cat <<EOF

Server prepared. Next (see deploy/aws/README.md):
  1. Secure MySQL and create the database and user.
  2. Create backend/.env.
  3. bash deploy/aws/deploy.sh --first-install     (as $DEPLOY_USER, not root)
  4. sudo certbot --nginx -d $DOMAIN               (when a real domain points here)
EOF
