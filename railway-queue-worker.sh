#!/bin/bash

echo "=== Railway Queue Worker Started ==="

# Esperar a que la base de datos esté lista
echo "Waiting for database to be ready..."
sleep 10

# Iniciar el worker de colas
echo "Starting queue worker..."
echo "Processing queues: sunat-send, default"

php artisan queue:work redis \
    --queue=sunat-send,default \
    --tries=3 \
    --timeout=300 \
    --sleep=3 \
    --max-time=3600 \
    --rest=3 \
    --verbose
