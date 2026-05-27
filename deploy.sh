#!/usr/bin/env bash
set -e

DEPLOY_DIR="/var/www/ai-companion"

echo "==> Pulling latest code..."
git -C "$DEPLOY_DIR" pull origin main

echo "==> Installing PHP dependencies..."
composer install --working-dir="$DEPLOY_DIR" --no-dev --optimize-autoloader --no-interaction

echo "==> Running migrations..."
php "$DEPLOY_DIR/artisan" migrate --force

echo "==> Clearing & rebuilding caches..."
php "$DEPLOY_DIR/artisan" config:cache
php "$DEPLOY_DIR/artisan" route:cache
php "$DEPLOY_DIR/artisan" view:cache

echo "==> Restarting queue workers..."
php "$DEPLOY_DIR/artisan" queue:restart
supervisorctl restart ai-companion-worker:*

echo "==> Done. Backend deployed."
