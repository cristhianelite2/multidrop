#!/usr/bin/env bash
# Despliegue en shop.ceballosleon.com (droplet)
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/shop}"

cd "$APP_DIR"

echo "==> git pull"
git pull origin main

echo "==> composer"
composer install --no-dev --optimize-autoloader

echo "==> migrate"
php artisan migrate --force

echo "==> clear caches"
php artisan optimize:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear

echo "==> done. Verifica en admin: campaña → Prompts → bloque 'Generar prompt con MIIA'"
