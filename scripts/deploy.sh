#!/usr/bin/env bash
# deploy.sh — Despliega la última versión del backend desde GitHub
# Uso: bash scripts/deploy.sh
set -euo pipefail

APP_DIR="/var/www/ai-companion"
cd "$APP_DIR"

PREV=$(git rev-parse HEAD)
echo ""
echo "▶ [deploy:backend] Commit actual: $(git log -1 --pretty='%C(yellow)%h%Creset %s')"
echo "  Bajando cambios desde GitHub..."

git fetch origin main
INCOMING=$(git log HEAD..origin/main --oneline | wc -l | tr -d ' ')
if [ "$INCOMING" = "0" ]; then
  echo "  Sin cambios nuevos. Ya estás en la versión más reciente."
  exit 0
fi

echo "  $INCOMING commit(s) nuevos:"
git log HEAD..origin/main --oneline | sed 's/^/    /'

git pull origin main

echo ""
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

NEW=$(git rev-parse HEAD)
echo ""
echo "✔ Deploy completado → $(git log -1 --pretty='%C(yellow)%h%Creset %s')"
echo "  Para revertir: bash scripts/rollback.sh $PREV"
echo ""
