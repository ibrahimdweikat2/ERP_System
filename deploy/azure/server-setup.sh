#!/usr/bin/env bash
# One-time preparation of a fresh Ubuntu 24.04 Azure VM for «دفتر». Run as the admin user:
#   sudo bash server-setup.sh
# Adds 2 GB swap (the free VMs have 1 GB RAM), installs Docker Engine from Docker's own
# signed repository, and creates /opt/daftar for deploy.ps1 to fill.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run with sudo: sudo bash server-setup.sh" >&2
  exit 1
fi
owner="${SUDO_USER:-root}"

echo "== Swap (2 GB) =="
if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
# Prefer RAM; use swap only under pressure.
echo 'vm.swappiness=10' > /etc/sysctl.d/99-daftar.conf
sysctl -q --system

echo "== Docker Engine =="
if ! command -v docker >/dev/null 2>&1; then
  apt-get update
  apt-get install -y ca-certificates curl
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker
# Lets deploy.ps1 run docker over SSH without sudo.
usermod -aG docker "$owner"

echo "== Application folder =="
mkdir -p /opt/daftar/import
chown -R "$owner":"$owner" /opt/daftar
chmod 700 /opt/daftar

echo
echo "Done. Log out and back in once (for the docker group), then run deploy.ps1 from the PC."
docker --version
docker compose version
