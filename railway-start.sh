#!/bin/bash

echo "=== Railway Deployment Started ==="

# Optimizar autoloader
echo "Optimizing autoloader..."
composer dump-autoload --optimize --no-dev

# Limpiar cache de configuración
echo "Clearing configuration cache..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear

# Ejecutar migraciones
echo "Running migrations..."
php artisan migrate --force --no-interaction

# Crear enlace simbólico de storage
echo "Creating storage link..."
php artisan storage:link || true

# Optimizar para producción
echo "Optimizing for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Asegurar permisos de storage y bootstrap/cache
echo "Setting permissions..."
chmod -R 775 storage bootstrap/cache
mkdir -p storage/app/public/certificado
mkdir -p storage/app/sunat
chmod -R 775 storage/app/public
chmod -R 775 storage/app/sunat

echo "=== Starting application ==="
echo "PORT: ${PORT:-8080}"

# Iniciar servidor web
php artisan serve --host=0.0.0.0 --port=${PORT:-8080} --no-reload
