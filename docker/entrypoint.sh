#!/bin/bash
set -e

echo "=========================================="
echo "🚀 Iniciando API Facturación SUNAT"
echo "=========================================="

# Esperar a que PostgreSQL esté listo
echo "⏳ Esperando a PostgreSQL..."
for i in {1..30}; do
    if pg_isready -h postgres -p 5432 > /dev/null 2>&1; then
        echo "✅ PostgreSQL está listo!"
        break
    fi
    echo "PostgreSQL no está listo - esperando... (intento $i/30)"
    sleep 2
done

# Esperar a que Redis esté listo
echo "⏳ Esperando a Redis..."
for i in {1..30}; do
    if timeout 2 redis-cli -h redis -p 6379 ping > /dev/null 2>&1; then
        echo "✅ Redis está listo!"
        break
    fi
    echo "Redis no está listo - esperando... (intento $i/30)"
    sleep 2
done

# Instalar dependencias de Composer si no existen
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "📦 Instalando dependencias de Composer..."
    composer install --no-dev --optimize-autoloader --no-interaction
else
    echo "✅ Dependencias de Composer ya instaladas"
fi

# Crear directorios necesarios si no existen
echo "📁 Creando directorios de storage..."
mkdir -p storage/logs
mkdir -p storage/framework/cache
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/app/public/certificado
mkdir -p storage/app/sunat
mkdir -p bootstrap/cache

# Ajustar permisos
echo "🔐 Ajustando permisos..."
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Generar APP_KEY si no existe
if [ ! -f .env ] || ! grep -q "^APP_KEY=" .env || [ -z "$(grep '^APP_KEY=' .env | cut -d '=' -f2)" ]; then
    echo "🔑 Generando APP_KEY..."
    php artisan key:generate --ansi
fi

# Ejecutar migraciones
echo "🗄️  Ejecutando migraciones de base de datos..."
php artisan migrate --force

# Limpiar y optimizar
echo "🧹 Optimizando aplicación..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache

# Crear enlace simbólico para storage
echo "🔗 Creando enlace simbólico de storage..."
php artisan storage:link || true

echo "=========================================="
echo "✨ Aplicación lista!"
echo "🌐 Accede en: http://localhost:8001"
echo "=========================================="

# Ejecutar el comando pasado como argumento
exec "$@"
