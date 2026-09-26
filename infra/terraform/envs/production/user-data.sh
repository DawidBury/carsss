#!/bin/bash
set -euo pipefail

dnf install -y docker

install -d -m 0755 /usr/local/lib/docker/cli-plugins
curl -fsSL --retry 5 --retry-delay 5 \
  https://github.com/docker/compose/releases/latest/download/docker-compose-linux-aarch64 \
  -o /usr/local/lib/docker/cli-plugins/docker-compose
chmod 0755 /usr/local/lib/docker/cli-plugins/docker-compose

BUILDX_VERSION=$(curl -fsSL https://api.github.com/repos/docker/buildx/releases/latest | grep '"tag_name"' | head -1 | cut -d'"' -f4)
curl -fsSL --retry 5 --retry-delay 5 \
  "https://github.com/docker/buildx/releases/download/${BUILDX_VERSION}/buildx-${BUILDX_VERSION}.linux-arm64" \
  -o /usr/local/lib/docker/cli-plugins/docker-buildx
chmod 0755 /usr/local/lib/docker/cli-plugins/docker-buildx

systemctl enable --now docker
usermod -aG docker ec2-user

if [ ! -f /swapfile ]; then
  fallocate -l 2G /swapfile
  chmod 0600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo 'vm.swappiness=10' > /etc/sysctl.d/99-swappiness.conf
sysctl --system

install -d -o ec2-user -g ec2-user -m 0755 /opt/cars
