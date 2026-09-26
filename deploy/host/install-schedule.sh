#!/usr/bin/env bash
set -euo pipefail

sudo install -m 0644 /opt/cars/deploy/host/systemd/cars-import.service /etc/systemd/system/
sudo install -m 0644 /opt/cars/deploy/host/systemd/cars-import.timer /etc/systemd/system/
sudo install -m 0644 /opt/cars/deploy/host/systemd/cars-backup.service /etc/systemd/system/
sudo install -m 0644 /opt/cars/deploy/host/systemd/cars-backup.timer /etc/systemd/system/

sudo systemctl daemon-reload
sudo systemctl enable --now cars-import.timer
sudo systemctl enable --now cars-backup.timer
sudo systemctl list-timers 'cars-*'
