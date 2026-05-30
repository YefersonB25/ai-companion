#!/usr/bin/env bash
# rollback.sh — Revierte el backend a un commit anterior
# Uso: bash scripts/rollback.sh [commit-hash|HEAD~1]
# Sin argumento revierte al commit anterior.
set -euo pipefail

APP_DIR="/var/www/ai-companion"
cd "$APP_DIR"

TARGET="${1:-HEAD~1}"

echo ""
echo "▶ [rollback:backend] Commit actual: $(git log -1 --pretty='%C(yellow)%h%Creset %s')"
echo "  Revirtiendo a: $TARGET"
echo ""

git reset --hard "$TARGET"

echo "▶ Instalando dependencias PHP..."
composer install --no-dev --optimize-autoloader --quiet

echo "▶ Ejecutando migraciones..."
php artisan migrate --force

echo "▶ Reconstruyendo cachés..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "▶ Reiniciando workers y servicios..."
supervisorctl restart ai-companion-worker:*
supervisorctl restart ai-companion-reverb
systemctl reload php8.3-fpm

echo ""
echo "✔ Rollback completado → $(git log -1 --pretty='%C(yellow)%h%Creset %s')"
echo ""
