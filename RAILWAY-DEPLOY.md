# Despliegue en Railway - API Facturación SUNAT (Modo Síncrono)

## Arquitectura Simplificada

Para ambiente de pruebas con APIs síncronas, solo necesitas:

1. **PostgreSQL** - Base de datos (`Postgres-rH9p`) ✅ Ya tienes
2. **Aplicación Laravel** - API (`nubetap_pos_facturador`) ✅ Ya tienes

**NO necesitas:**
- ❌ Redis (solo útil para modo asíncrono)
- ❌ Worker de colas (no usas `/send-sunat-async`)

---

## Paso a Paso - Despliegue en Railway

### 1. Generar APP_KEY

En tu máquina local, ejecuta:

```bash
php artisan key:generate --show
```

Copia la clave generada (ejemplo: `base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)

---

### 2. Configurar Variables de Entorno

En Railway, ve a tu servicio **nubetap_pos_facturador** → **Variables** y agrega:

```bash
# === APLICACIÓN ===
APP_NAME="API Facturación SUNAT - Staging"
APP_ENV=staging
APP_KEY=base64:xxxxx
APP_DEBUG=true
APP_TIMEZONE=America/Lima
APP_LOCALE=es
APP_FALLBACK_LOCALE=es

# === BASE DE DATOS ===
DB_CONNECTION=pgsql
DB_HOST=${{Postgres-rH9p.PGHOST}}
DB_PORT=${{Postgres-rH9p.PGPORT}}
DB_DATABASE=${{Postgres-rH9p.PGDATABASE}}
DB_USERNAME=${{Postgres-rH9p.PGUSER}}
DB_PASSWORD=${{Postgres-rH9p.PGPASSWORD}}

# === DRIVERS (SIN REDIS) ===
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

# === SUNAT (AMBIENTE BETA) ===
SUNAT_ENVIRONMENT=beta

# === SANCTUM ===
SANCTUM_EXPIRATION=1440
SANCTUM_TOKEN_PREFIX=sunat_staging_

# === LOGS ===
LOG_CHANNEL=stack
LOG_LEVEL=debug
BCRYPT_ROUNDS=12
BROADCAST_CONNECTION=log
```

**IMPORTANTE:**
- `QUEUE_CONNECTION=sync` hace que los jobs se ejecuten inmediatamente (sin cola)
- `CACHE_STORE=database` usa la tabla `cache` en PostgreSQL
- `SESSION_DRIVER=database` usa la tabla `sessions` en PostgreSQL

---

### 3. Actualizar Archivos de Despliegue

#### Procfile
Simplifica a solo proceso web:

```
web: bash railway-start.sh
```

---

### 4. Push de Cambios

```bash
git add Procfile railway-start.sh nixpacks.toml RAILWAY-DEPLOY.md
git commit -m "Configure Railway deployment for sync mode (no Redis)"
git push origin main
```

Railway detectará el push y desplegará automáticamente.

---

### 5. Configurar Dominio (Después del Deploy)

1. En Railway, ve a **nubetap_pos_facturador** → Settings → Networking
2. Copia tu dominio (ejemplo: `billing.staging.nubetap.com`)
3. Agrega estas variables:

```bash
APP_URL=https://billing.staging.nubetap.com
SANCTUM_STATEFUL_DOMAINS=billing.staging.nubetap.com
```

4. Railway redesplegará automáticamente

---

## Verificación Post-Despliegue

### 1. Revisar Logs

```bash
railway logs --service nubetap_pos_facturador
```

Confirma:
- ✅ Migraciones ejecutadas
- ✅ Servidor corriendo en puerto asignado
- ✅ Sin errores de conexión a PostgreSQL

### 2. Health Check

```bash
curl https://tu-dominio/api/health
```

Debe retornar estado de:
- Database ✅
- Cache ✅
- Storage ✅
- PHP Extensions ✅

### 3. Probar Endpoint de Prueba

```bash
curl https://tu-dominio/api/ping
```

Debe retornar: `{"status":"ok","message":"pong"}`

### 4. Probar Login

```bash
curl -X POST https://tu-dominio/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "tu-password"
  }'
```

---

## Subir Certificados SUNAT

### Opción A: Vía API

Una vez desplegado, usa el endpoint de configuración de empresa:

```bash
POST /api/v1/companies/{company_id}/config/certificate
```

### Opción B: Railway CLI

```bash
# Instalar CLI
npm i -g @railway/cli

# Login y conectar
railway login
railway link

# Acceder al shell
railway run bash

# Navegar a directorio de certificados
cd storage/app/public/certificado

# Aquí puedes subir tus archivos .pem
# (usa otro terminal para copiar archivos)
```

---

## Arquitectura de Integración con Django

```
Django Backend
    ↓ (HTTP Request)
API Laravel (Railway)
    ↓ (Procesamiento Síncrono)
SUNAT
    ↓ (Respuesta Inmediata)
API Laravel
    ↓ (Response)
Django Backend
```

**Endpoints que usarás:**
- `POST /api/v1/invoices/{id}/send-sunat` (síncrono)
- `POST /api/v1/boletas/{id}/send-sunat` (síncrono)
- `POST /api/v1/credit-notes/{id}/send-sunat` (síncrono)
- `POST /api/v1/debit-notes/{id}/send-sunat` (síncrono)

Todos retornan **200 OK** con respuesta inmediata de SUNAT.

---

## Solución de Problemas

### Error: "No application encryption key"
- Verifica que `APP_KEY` esté en variables de entorno
- Debe empezar con `base64:`

### Error de conexión a base de datos
- Verifica las referencias `${{Postgres-rH9p.XXX}}`
- Confirma que PostgreSQL esté en el mismo proyecto Railway
- Revisa logs: `railway logs`

### Migraciones no se ejecutan
- Verifica logs del deploy
- Ejecuta manualmente: `railway run php artisan migrate --force`

### Storage no tiene permisos
- Los permisos se configuran en `railway-start.sh`
- Si falla, ejecuta: `railway run chmod -R 775 storage`

### Certificados SUNAT no se encuentran
- Verifica ruta en variable: `SUNAT_CERTIFICATE_PATH`
- Debe ser relativa a `storage/app/public/`

---

## Rendimiento en Modo Síncrono

**Ventajas:**
- ✅ Más simple, sin dependencias extra
- ✅ Respuesta inmediata desde Django
- ✅ Menos servicios = menor costo

**Limitaciones:**
- ⚠️ Cada request espera respuesta de SUNAT (2-5 segundos)
- ⚠️ Si SUNAT está lento, Django también se ralentiza
- ⚠️ No hay reintentos automáticos si falla

**Recomendación:** Para producción, considera migrar a modo asíncrono con Redis + Workers.

---

## Migración Futura a Modo Asíncrono

Cuando estés listo para producción, solo necesitas:

1. Agregar servicio Redis en Railway
2. Cambiar variables:
   ```bash
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis
   REDIS_HOST=${{Redis.REDIS_HOST}}
   REDIS_PORT=${{Redis.REDIS_PORT}}
   REDIS_PASSWORD=${{Redis.REDIS_PASSWORD}}
   ```
3. Agregar worker: `worker: bash railway-queue-worker.sh` en Procfile
4. Usar endpoints asíncronos: `/send-sunat-async`

---

## Comandos Útiles Railway CLI

```bash
# Ver logs en tiempo real
railway logs --follow

# Ejecutar migraciones
railway run php artisan migrate

# Ejecutar comandos artisan
railway run php artisan [comando]

# Acceder al shell
railway run bash

# Ver variables de entorno
railway variables

# Redeploy manual
railway up
```

---

## Costos Estimados

**Setup actual (2 servicios):**
- PostgreSQL: ~$5/mes
- Aplicación web: ~$5-8/mes
- **Total: ~$10-13/mes**

Railway incluye $5 USD gratis/mes.

---

## Checklist de Despliegue

- [ ] Generar `APP_KEY`
- [ ] Configurar todas las variables de entorno
- [ ] Verificar referencia a `Postgres-rH9p`
- [ ] Simplificar Procfile (solo `web`)
- [ ] Push de archivos actualizados
- [ ] Esperar deploy exitoso
- [ ] Configurar dominio en variables
- [ ] Probar `/api/health`
- [ ] Probar login
- [ ] Subir certificados SUNAT
- [ ] Probar envío de documento de prueba
- [ ] Integrar con Django

---

## Soporte

Si encuentras problemas:
1. Revisa logs: `railway logs`
2. Verifica variables de entorno
3. Prueba endpoints con Postman
4. Consulta ejemplos en carpeta `ejemplos-postman/`
