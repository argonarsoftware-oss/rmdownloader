#!/usr/bin/env bash
# Server-side deploy, run on the VPS by the GitHub Actions workflow (or by hand).
# Pulls the latest main and reloads Apache. config.php is git-ignored, so secrets
# are never touched.
set -euo pipefail

APP_DIR="/var/www/rmdownloader"
cd "$APP_DIR"

echo "Fetching latest..."
git fetch --all --quiet
git reset --hard origin/main

# Keep ownership/permissions sane for Apache.
sudo chown -R www-data:www-data "$APP_DIR/website"

echo "Reloading Apache..."
sudo systemctl reload apache2

echo "Deployed $(git rev-parse --short HEAD) at $(date -u +%FT%TZ)"
