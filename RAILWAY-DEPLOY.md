# Despliegue en Railway - API Facturación Electrónica SUNAT

## Servicios Requeridos

Este proyecto necesita 3 servicios en Railway:

1. **PostgreSQL** - Base de datos (`Postgres-rH9p`)
2. **Redis** - Caché, sesiones y colas (tu instancia existente)
3. **Aplicación Laravel** - API con worker de colas

## Paso a Paso

### 1. Generar APP_KEY

Ejecuta en tu máquina local:

```bash
php artisan key:generate --show
```

Copia la clave generada (ejemplo: `base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)

---

### 2. Configurar Variables de Entorno en Railway

Ve a tu servicio de aplicación → **Variables** y agrega:

```bash
# === CRÍTICAS ===
APP_KEY=base64:xxxxx
APP_ENV=production
APP_DEBUG=false

# === PostgreSQL (referencia a Postgres-rH9p) ===
DB_CONNECTION=pgsql
DB_HOST=${{Postgres-rH9p.PGHOST}}
DB_PORT=${{Postgres-rH9p.PGPORT}}
DB_DATABASE=${{Postgres-rH9p.PGDATABASE}}
DB_USERNAME=${{Postgres-rH9p.PGUSER}}
DB_PASSWORD=${{Postgres-rH9p.PGPASSWORD}}

# === Redis (cambia "Redis" por el nombre de tu servicio Redis) ===
REDIS_CLIENT=phpredis
REDIS_HOST=${{Redis.REDIS_HOST}}
REDIS_PORT=${{Redis.REDIS_PORT}}
REDIS_PASSWORD=${{Redis.REDIS_PASSWORD}}

CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# === SUNAT ===
SUNAT_ENVIRONMENT=beta

# === Sanctum ===
SANCTUM_EXPIRATION=1440
SANCTUM_TOKEN_PREFIX=sunat_prod_
SANCTUM_VERIFY_IP=false
SANCTUM_LOG_USAGE=true
SANCTUM_MAX_INACTIVITY=120
SANCTUM_MAX_TOKENS=10

# === Otras ===
LOG_CHANNEL=stack
LOG_LEVEL=error
FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
BCRYPT_ROUNDS=12
```

**IMPORTANTE:** Cambia `${{Redis.XXX}}` por el nombre exacto de tu servicio Redis en Railway.

---

### 3. Push de Archivos de Configuración

```bash
git add Procfile railway-start.sh railway-queue-worker.sh nixpacks.toml RAILWAY-DEPLOY.md
git commit -m "Add Railway deployment configuration"
git push origin main
```

Railway detectará el push y desplegará automáticamente:
- Instalará dependencias PHP y Node.js
- Compilará assets con Vite
- Ejecutará migraciones
- Iniciará la aplicación

---

### 4. Configurar Dominio (Después del Deploy)

1. Copia tu dominio de Railway (ej: `tu-app.up.railway.app`)
2. Agrega/actualiza estas variables:

```bash
APP_URL=https://tu-app.up.railway.app
SANCTUM_STATEFUL_DOMAINS=tu-app.up.railway.app
```

3. Railway redesplegará automáticamente

---

### 5. Habilitar Worker de Colas (Opcional)

El `Procfile` define 2 procesos: `web` y `worker`. Railway por defecto solo ejecuta `web`.

**Opción A: Servicio Separado (Recomendado)**
1. En Railway, crea un nuevo servicio desde el mismo repositorio
2. Renómbralo a "queue-worker"
3. Ve a Settings → Deploy → Start Command
4. Pon: `bash railway-queue-worker.sh`
5. Copia todas las variables de entorno del servicio principal

**Opción B: En el Mismo Proceso (Bajo Tráfico)**
Edita `railway-start.sh` y agrega antes de la última línea:
```bash
php artisan queue:work redis --queue=sunat-send,default --tries=3 --timeout=300 --daemon &
```

---

## Verificación Post-Despliegue

### 1. Revisar Logs
```bash
railway logs
```

Confirma que:
- ✅ Migraciones se ejecutaron
- ✅ Servidor PHP está corriendo
- ✅ No hay errores de conexión a Redis/PostgreSQL

### 2. Probar Conexión a Redis

```bash
railway run php artisan tinker

# Dentro de tinker:
Redis::ping()  # Debe retornar "PONG"
Cache::put('test', 'value', 60)
Cache::get('test')  # Debe retornar "value"
```

### 3. Verificar API

```bash
curl https://tu-dominio.railway.app/api/health
```

---

## Subir Certificados SUNAT

**Opción A: Vía API**
Usa el endpoint de configuración de empresa para subir certificados

**Opción B: Railway CLI**
```bash
npm i -g @railway/cli
railway login
railway link
railway run bash

# Dentro del shell:
cd storage/app/public/certificado
# Sube tus certificados aquí
```

---

## Solución de Problemas

### Error: "No application encryption key"
- Verifica que `APP_KEY` esté configurada en variables de entorno

### Error de conexión a PostgreSQL
- Confirma que las referencias `${{Postgres-rH9p.XXX}}` sean correctas
- Verifica que PostgreSQL esté en el mismo proyecto

### Error de conexión a Redis
- Confirma el nombre exacto de tu servicio Redis
- Verifica que las referencias `${{NombreRedis.XXX}}` sean correctas

### Worker de colas no funciona
- Verifica que `QUEUE_CONNECTION=redis` esté configurado
- Revisa logs del servicio worker
- Confirma que el servicio worker tenga las mismas variables de entorno

### Archivos no persisten
- Railway usa almacenamiento efímero
- Los archivos se pierden en redeploy
- Considera usar Railway Volumes o S3 para certificados

---

## Archivos de Configuración Creados

- **Procfile** - Define procesos web y worker
- **railway-start.sh** - Ejecuta migraciones y optimizaciones
- **railway-queue-worker.sh** - Worker de colas dedicado
- **nixpacks.toml** - Configuración de build (PHP 8.2, Node.js 20)

---

## Comandos Útiles

```bash
# Ver logs en tiempo real
railway logs

# Ejecutar comandos artisan
railway run php artisan [comando]

# Acceder al shell
railway run bash

# Ver lista de variables
railway variables
```

---

## Costos Estimados

Railway tiene un tier gratuito con $5 USD/mes de crédito.

Para esta aplicación:
- Aplicación web: ~$5-10/mes
- Queue worker (si es separado): ~$3-5/mes
- PostgreSQL: ~$5/mes
- Redis: ~$3-5/mes
- **Total**: ~$16-25/mes

**Tip:** Para reducir costos, ejecuta el worker en el mismo proceso web en lugar de servicio separado.
