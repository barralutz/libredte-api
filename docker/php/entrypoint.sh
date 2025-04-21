#!/bin/bash

cd /var/www/api

if [ ! -d "vendor" ]; then
  echo "📦 Instalando dependencias con Composer..."
  composer install --no-dev --optimize-autoloader --no-interaction
else
  echo "✅ Dependencias ya instaladas"
fi

exec "$@"
