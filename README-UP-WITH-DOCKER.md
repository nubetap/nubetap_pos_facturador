# Levantar el Proyecto con Docker

Guía rápida para ejecutar la API de Facturación Electrónica SUNAT con Docker y Docker Compose v2.

## Requisitos Previos

- Docker 20.10 o superior
- Docker Compose v2 (plugin)
- Git

Verifica las versiones instaladas:
```bash
docker --version
docker compose version
```

## Pasos de Instalación

### 1. Clonar el Repositorio (si no lo has hecho)

```bash
git clone <repository-url>
cd Api-facturacion-electr-nica-sunat-P-ru-Pro-main
```

### 2. Configurar Variables de Entorno

Copia el archivo de configuración Docker:

```bash
cp .env.docker .env
```

**Importante:** Genera una nueva `APP_KEY`:

```bash
# Opción 1: Usando Docker (sin construir)
docker run --rm -v $(pwd):/app -w /app composer/composer composer install --no-dev --ignore-platform-reqs
docker run --rm -v $(pwd):/app -w /app php:8.2-cli php artisan key:generate

# Opción 2: Se generará automáticamente al levantar los contenedores
```

### 3. Construir e Iniciar los Contenedores

```bash
docker compose build
docker compose up -d
```

Esto levantará los siguientes servicios:
- **app**: Aplicación Laravel (PHP 8.2 + Nginx) en puerto `8001`
- **postgres**: Base de datos PostgreSQL en puerto `5433`
- **redis**: Cache y colas en puerto `6378`
- **queue**: Worker para procesar colas de SUNAT

### 4. Verificar que los Servicios Estén Corriendo

```bash
docker compose ps
```

Deberías ver algo como:
```
NAME                  STATUS    PORTS
sunat_api_app         Up        0.0.0.0:8001->8001/tcp
sunat_api_postgres    Up        0.0.0.0:5433->5432/tcp
sunat_api_redis       Up        0.0.0.0:6378->6379/tcp
sunat_api_queue       Up
```

### 5. Ejecutar Migraciones (Primera Vez)

Las migraciones se ejecutan automáticamente al iniciar. Si necesitas ejecutarlas manualmente:

```bash
docker compose exec app php artisan migrate --seed
```

### 6. Crear Usuario Administrador

Inicializa el sistema creando el primer usuario:

```bash
docker compose exec app php artisan tinker
```

Dentro de tinker:
```php
$user = \App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@example.com',
    'password' => bcrypt('password123')
]);
$user->assignRole('super_admin');
exit
```

O usa el endpoint de inicialización:
```bash
curl -X POST http://localhost:8001/api/auth/initialize \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Admin",
    "email": "admin@example.com",
    "password": "password123",
    "password_confirmation": "password123"
  }'
```

### 7. Acceder a la Aplicación

- **API Base URL**: http://localhost:8001/api
- **Health Check**: http://localhost:8001/api/health
- **Ping**: http://localhost:8001/api/ping

## Comandos Útiles

### Ver logs de la aplicación
```bash
# Todos los servicios
docker compose logs -f

# Solo app
docker compose logs -f app

# Solo base de datos
docker compose logs -f postgres

# Solo queue worker
docker compose logs -f queue
```

### Ejecutar comandos Artisan
```bash
docker compose exec app php artisan <comando>

# Ejemplos:
docker compose exec app php artisan route:list
docker compose exec app php artisan config:clear
docker compose exec app php artisan queue:work
```

### Acceder al contenedor
```bash
docker compose exec app bash
```

### Reiniciar servicios
```bash
# Todos
docker compose restart

# Solo app
docker compose restart app

# Solo queue worker
docker compose restart queue
```

### Detener servicios
```bash
docker compose stop
```

### Detener y eliminar contenedores
```bash
docker compose down
```

### Detener y eliminar TODO (incluye volúmenes)
```bash
docker compose down -v
```

### Reconstruir contenedores después de cambios
```bash
docker compose build --no-cache
docker compose up -d
```

## Gestión de Base de Datos

### Conectarse a PostgreSQL

```bash
# Desde el host
psql -h localhost -p 5433 -U sunat_user -d sunat_api

# Desde dentro del contenedor
docker compose exec postgres psql -U sunat_user -d sunat_api
```

Contraseña por defecto: `sunat_password` (configurable en `.env`)

### Backup de base de datos

```bash
docker compose exec postgres pg_dump -U sunat_user sunat_api > backup.sql
```

### Restaurar backup

```bash
docker compose exec -T postgres psql -U sunat_user sunat_api < backup.sql
```

## Gestión de Redis

### Conectarse a Redis

```bash
docker compose exec redis redis-cli
```

### Ver estadísticas de cache
```bash
docker compose exec redis redis-cli INFO stats
```

### Limpiar cache
```bash
docker compose exec redis redis-cli FLUSHDB
```

## Configuración de Certificados SUNAT

Coloca tu certificado digital (.pem) en:
```bash
storage/app/public/certificado/certificado.pem
```

O cópialo al contenedor:
```bash
docker compose cp /ruta/local/certificado.pem app:/var/www/html/storage/app/public/certificado/
```

Actualiza la configuración en `.env`:
```env
SUNAT_CERTIFICATE_PATH=storage/app/public/certificado/certificado.pem
SUNAT_CERTIFICATE_PASSWORD=tu_contraseña
```

## Queue Worker

El worker de colas ya está configurado y corriendo en el servicio `queue`. Procesa trabajos de envío a SUNAT automáticamente.

Para ver el estado:
```bash
docker compose logs -f queue
```

Para reiniciar el worker después de cambios en el código:
```bash
docker compose restart queue
```

## Testing

Ejecutar tests:
```bash
docker compose exec app php artisan test
```

O con Pest:
```bash
docker compose exec app ./vendor/bin/pest
```

## Troubleshooting

### Error: "Permission denied" en storage
```bash
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### Error: "APP_KEY not set"
```bash
docker compose exec app php artisan key:generate
docker compose restart app
```

### Error: No se puede conectar a la base de datos
Verifica que PostgreSQL esté corriendo:
```bash
docker compose ps postgres
docker compose logs postgres
```

### Error: Redis connection refused
```bash
docker compose ps redis
docker compose restart redis
```

### Limpiar todo y empezar de nuevo
```bash
docker compose down -v
docker compose build --no-cache
docker compose up -d
```

## Puertos Utilizados

- **8001**: Aplicación web (Nginx + PHP-FPM)
- **5433**: PostgreSQL (mapeo desde 5432 interno)
- **6378**: Redis (mapeo desde 6379 interno)

## Variables de Entorno Importantes

Edita `.env` para personalizar:

| Variable | Descripción | Valor por Defecto |
|----------|-------------|-------------------|
| `APP_URL` | URL base de la aplicación | http://localhost:8001 |
| `DB_DATABASE` | Nombre de base de datos | sunat_api |
| `DB_USERNAME` | Usuario de PostgreSQL | sunat_user |
| `DB_PASSWORD` | Contraseña de PostgreSQL | sunat_password |
| `SUNAT_ENVIRONMENT` | Ambiente SUNAT | beta |
| `SUNAT_CERTIFICATE_PATH` | Ruta del certificado | storage/app/public/certificado/certificado.pem |

## Próximos Pasos

1. Configura tus credenciales SUNAT (GRE OAuth2)
2. Crea tu primera empresa emisora vía API
3. Genera comprobantes de prueba en ambiente beta
4. Revisa los ejemplos en `ejemplos-postman/`
5. Lee la documentación completa del proyecto

## Soporte

Para más información consulta:
- README.md principal del proyecto
- Documentación de la API en `/ejemplos-postman/`
- Logs en tiempo real: `docker compose logs -f`
