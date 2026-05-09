# Integración ValidaPSE para clientes NRUS

**Estado:** Spike / Fuente de verdad de implementación
**Última actualización:** 2026-05-07
**Owner:** Frank
**Repositorios afectados:** `nubetap_pos_backend` (Django) + `nubetap_pos_facturador` (Laravel)

---

## 1. Contexto y problema

Los clientes bajo régimen **NRUS** (Nuevo Régimen Único Simplificado) **no pueden adquirir certificado digital tributario** propio en SUNAT. Hoy el sistema firma 100% con Greenter usando un certificado por empresa cargado en `Business.digital_certificate`. Para NRUS necesitamos un proveedor PSE externo que firme con su propio certificado autorizado por SUNAT.

**Proveedor elegido:** [ValidaPSE](https://app.validapse.com)

**Razones:**
1. API de gestión de empresas completa (CRUD), no solo crear.
2. Token de acceso por empresa entregado al crear (no requiere login con user/pass).
3. Endpoint `/api/cpe/generarenviar` que firma + envía a SUNAT en una sola operación → minimiza superficie de cambios.
4. Endpoints DEMO separados (`-demo`) → testing seguro sin tocar SUNAT real.
5. Recupera CDR explícitamente vía `/api/cpe/consultar/{nombre}`.

---

## 2. Alcance del spike

**Incluido:**
- Switcher por empresa en Django admin: `cpe_provider` ∈ {`greenter`, `validapse`}.
- Por defecto **TODAS** las empresas existentes y nuevas siguen con `greenter`. Sin cambios para ellas.
- Sincronización Django → Laravel del proveedor y credenciales ValidaPSE.
- En Laravel: branch en el flujo de envío. Si `cpe_provider == validapse`:
  - Construir XML sin firmar usando `Greenter\See::getFactory()->getBuilder()->build($document)`.
  - Llamar a ValidaPSE `/api/cpe/generarenviar` (o `-demo` según `modo_produccion`).
  - Persistir XML firmado, CDR, hash igual que hoy.
- Flujo soportado: **Boletas únicamente** (régimen NRUS solo permite boletas y resumen diario).
- Resumen diario de boletas y notas de crédito sobre boleta: **fuera del spike**, se documentan como follow-up.

**No incluido (post-spike):**
- Refactor a `CpeProviderInterface` formal.
- Notas de crédito sobre boleta vía ValidaPSE.
- Resumen diario vía ValidaPSE (hoy se manejará manualmente o se difiere).
- Guías de remisión (no aplica a NRUS solo-boletas).
- Anulaciones (NRUS las hace vía resumen diario, fuera de scope).

**Pre-requisito operativo (responsabilidad del cliente):**
El cliente NRUS debe registrar a ValidaPSE como **OSE/PSE autorizado en su SOL** (Mis Comprobantes → autorizar PSE). Sin este paso SUNAT rechaza el XML aunque la firma sea técnicamente válida. Esto se documenta en el onboarding, no es código.

---

## 3. Hechos verificados del código actual

### 3.1 Django — `apps/business/models.py`

Modelo `Business` (líneas 79–467). Campos relevantes existentes:

| Campo | Tipo | Notas |
|---|---|---|
| `ruc` | CharField(11), unique | |
| `legal_name` | CharField(200) | Razón social |
| `sunat_user`, `sunat_password` | CharField | SOL, **plaintext** |
| `digital_certificate` | FileField | PFX/PEM |
| `certificate_password` | CharField | **plaintext** |
| `production_mode` | BooleanField, default=False | **Único flag prod/staging.** True=prod, False=demo/staging |
| `php_company_id` | IntegerField, nullable | ID en facturador (prod) |
| `php_company_id_staging` | IntegerField, nullable | ID en facturador (staging) |
| `billing_sync_status` | Choices | NOT_STARTED / IN_PROGRESS / COMPLETED / FAILED / PARTIAL |
| `billing_synced_at` | DateTimeField | |
| `can_issue_electronic_vouchers` | BooleanField | |

`active_php_company_id` (property, ~línea 414) decide qué ID usar según `production_mode`.

**No existe ningún campo de proveedor CPE.** Hoy es 100% Greenter implícito.

### 3.2 Django — Sync hacia Laravel

- HTTP client: `apps/core/billing/clients/sunat_api_client_singleton.py` (httpx).
- Service: `apps/business/services/billing_sync_service.py` clase `BillingSyncService(production_mode: bool)`.
- Mapper: `_map_business_to_api_format(business)` arma el payload.
- Tasks Celery (`apps/business/tasks/billing_sync_tasks.py`):
  - `sync_business_to_billing_api(business_id, production_mode)` — crea
  - `update_business_in_billing_api(business_id, production_mode)` — actualiza
  - `promote_to_production(business_id)` — flujo de 6 pasos
  - `revert_to_staging(business_id)`
- Endpoint Laravel destino: `POST/PUT/PATCH /api/v1/companies` (sanctum).

### 3.3 Django — Admin

- File: `apps/business/admin.py`
- Class: `BusinessAdmin` (línea 193)
- Acciones existentes: `promote_business_to_production`, `revert_business_to_staging`, `sync_to_production`, `sync_to_staging`.

### 3.4 Laravel — `app/Models/Company.php`

Campos relevantes existentes:

| Campo | Notas |
|---|---|
| `ruc`, `razon_social`, `nombre_comercial` | |
| `usuario_sol`, `clave_sol` | plaintext, `$hidden` solo de JSON |
| `certificado_pem`, `certificado_password` | plaintext |
| `modo_produccion` | boolean cast |
| `endpoint_beta`, `endpoint_produccion` | SOAP SUNAT |
| `gre_client_id_*`, `gre_client_secret_*`, `gre_ruc_proveedor`, `gre_usuario_sol`, `gre_clave_sol` | Credenciales GRE existentes (no se reusan para ValidaPSE) |
| `activo` | |

### 3.5 Laravel — Endpoint sync + flujo CPE

- Controller: `app/Http/Controllers/Api/CompanyController.php` con auth `sanctum`.
- Servicio firma actual: `app/Services/GreenterService.php`
  - `sendDocument()` línea 614 — firma + envía a SUNAT (acoplado).
  - `getXmlSigned()` línea 691 — solo firma.
  - **Punto clave:** Greenter expone XML sin firmar vía `$see->getFactory()->getBuilder()->build($document)` (verificado en greenter/lite ^5.1).
- Servicio orquestador: `app/Services/DocumentService.php`
  - `signXml()` línea 527
  - `sendToSunat()` línea 597
- Modelos resultado: `Invoice`, `Boleta`, `CreditNote`, etc. con campos `xml_path`, `cdr_path`, `estado_sunat`, `respuesta_sunat`, `codigo_hash`.

### 3.6 Encriptación

Ningún sistema tiene cifrado en reposo para credenciales hoy. Se mantiene la misma convención (plaintext en DB, `$hidden` en respuestas) para no introducir cambio cruzado en este spike. **Se deja como mejora separada.**

---

## 4. Diseño del cambio

### 4.1 Nuevos campos

#### Django `Business` (migration `business/migrations/XXXX_add_cpe_provider.py`)

```python
cpe_provider = models.CharField(
    max_length=20,
    choices=[
        ('greenter', 'Greenter (certificado propio)'),
        ('validapse', 'ValidaPSE (NRUS sin certificado)'),
    ],
    default='greenter',
    db_index=True,
    help_text='Proveedor de firma electrónica. Greenter usa el certificado de la empresa. ValidaPSE firma con su propio certificado PSE (para NRUS).'
)

# IDs de ValidaPSE — uno por ambiente, igual que php_company_id
validapse_empresa_id = models.IntegerField(null=True, blank=True)
validapse_empresa_id_staging = models.IntegerField(null=True, blank=True)

# Token de acceso por ambiente (lo entrega ValidaPSE al crear/leer empresa)
validapse_token_acceso = models.CharField(max_length=500, blank=True, default='')
validapse_token_acceso_staging = models.CharField(max_length=500, blank=True, default='')

validapse_synced_at = models.DateTimeField(null=True, blank=True)
```

**Property helper** (similar a `active_php_company_id`):
```python
@property
def active_validapse_token(self) -> str:
    return self.validapse_token_acceso if self.production_mode else self.validapse_token_acceso_staging

@property
def active_validapse_empresa_id(self) -> int | None:
    return self.validapse_empresa_id if self.production_mode else self.validapse_empresa_id_staging
```

**Mapeo `production_mode` → `servidor` ValidaPSE:**
- `production_mode=True`  → `servidor="PRODUCCIÓN"` y endpoints sin sufijo (`/api/cpe/generarenviar`)
- `production_mode=False` → `servidor="DEMO"` y endpoints con sufijo `-demo` (`/api/cpe/generarenviar-demo`)

#### Laravel `companies` (migration `XXXX_add_cpe_provider_to_companies.php`)

```php
$table->string('cpe_provider', 20)->default('greenter')->index();
$table->string('validapse_token_acceso', 500)->nullable();
$table->unsignedBigInteger('validapse_empresa_id')->nullable();
```

Solo un set (no staging/prod separado en Laravel) porque cada `Company` ya pertenece a un solo ambiente vía `modo_produccion` y vía la dupla `php_company_id` / `php_company_id_staging` del lado Django.

**Configuración global del API token master de ValidaPSE** (para administrar empresas):
- Variable de entorno: `VALIDAPSE_MASTER_API_TOKEN` (Django, vive solo del lado Django porque la creación/edición de empresas en ValidaPSE se dispara desde el admin).

### 4.2 Flujo de alta de una empresa NRUS

Como super-admin desde Django admin:

1. Crear/abrir el `Business`.
2. Llenar campos básicos (RUC, razón social, dirección, etc.) **igual que hoy**.
3. Cambiar `cpe_provider` a `validapse`.
4. **No subir certificado, no llenar SOL** (no aplica para NRUS via ValidaPSE).
   - Validación de admin: si `cpe_provider == validapse`, los campos `digital_certificate`, `certificate_password`, `sunat_user`, `sunat_password` quedan **opcionales** (no se exigen).
5. Acción de admin: **"Registrar en ValidaPSE (staging)"**.
   - Llama `POST https://app.validapse.com/api/empresas` con header `Authorization: Bearer ${VALIDAPSE_MASTER_API_TOKEN}` y body:
     ```json
     {
       "ruc": "<business.ruc>",
       "razon_social": "<business.legal_name>",
       "servidor": "DEMO",
       "fecha_inicio": "<today>",
       "ose": true
     }
     ```
   - Guarda `data.id` en `validapse_empresa_id_staging` y `data.credenciales_cpe.token_acceso` en `validapse_token_acceso_staging`.
6. Acción de admin: **"Sincronizar a facturador (staging)"** (existente, extendida) → empuja también `cpe_provider` y el token correspondiente.
7. Probar emisión de boleta en staging. El facturador rutea a ValidaPSE-demo.
8. Cuando esté validado: **"Registrar en ValidaPSE (producción)"** (mismo flujo con `servidor=PRODUCCIÓN`).
9. **"Promover a producción"** (acción existente, extendida para incluir validapse fields).

**Default seguro:** todo `Business` nuevo nace con `cpe_provider='greenter'`. ValidaPSE es opt-in explícito.

### 4.3 Flujo de emisión de boleta (NRUS, runtime)

```
Cliente POS → Django → Laravel POST /api/v1/invoices
  → DocumentService::createInvoice()  (sin cambios)
  → DocumentService::sendToSunat($invoice)
       │
       ├─ if $company->cpe_provider === 'greenter'   (DEFAULT)
       │     └─ flujo actual sin cambios
       │
       └─ if $company->cpe_provider === 'validapse'
             1. $unsigned = $see->getFactory()->getBuilder()->build($document)
             2. $b64 = base64_encode($unsigned)
             3. $name = "{ruc}-{tipo}-{serie}-{numero}"   (sin .xml)
             4. $resp = ValidapseClient::signAndSend($name, $b64, $modo_produccion)
                  POST /api/cpe/generarenviar         si modo_produccion=true
                  POST /api/cpe/generarenviar-demo    si modo_produccion=false
                  Authorization: Bearer {company.validapse_token_acceso}
                  body: { nombre_archivo, contenido_archivo }
             5. Persistir:
                  - xml_path / xml_url   ← base64_decode($resp.xml)
                  - codigo_hash          ← $resp.codigo_hash
                  - estado_sunat         ← derivado de isSuccess + estado
                  - respuesta_sunat      ← $resp completa (jsonb)
             6. CDR opcional inmediato (si no viene en respuesta):
                  GET /api/cpe/consultar/{nombre}     o /-demo
                  → guardar cdr_path / cdr_url
```

**Importante:** `signXml()` (firma sola) **no se usa en este spike** para empresas ValidaPSE. La acción atómica es `generarenviar`. Esto reduce estados intermedios y errores.

### 4.4 Sync Django → Laravel (extensión del payload)

`apps/business/services/billing_sync_service.py::_map_business_to_api_format()` agrega:

```python
payload.update({
    'cpe_provider': business.cpe_provider,
    'validapse_token_acceso': business.active_validapse_token or None,
    'validapse_empresa_id': business.active_validapse_empresa_id,
})
```

Lado Laravel: `CompanyController::store()` y `update()` deben aceptar y persistir estos 3 campos. `processRequestData()` los pasa por validación.

---

## 5. Componentes nuevos

### 5.1 Django

| Componente | Path | Responsabilidad |
|---|---|---|
| Migration | `apps/business/migrations/XXXX_add_cpe_provider.py` | Campos nuevos |
| Cliente HTTP | `apps/core/billing/clients/validapse_admin_client.py` | CRUD de empresas en ValidaPSE (master token) |
| Service | `apps/business/services/validapse_sync_service.py` | Crear/actualizar empresa en ValidaPSE, guardar token e id |
| Admin actions | `apps/business/admin.py` (extender `BusinessAdmin`) | "Registrar en ValidaPSE (staging/prod)" |
| Validación condicional | `BusinessAdmin.get_form()` o `clean()` | Si `cpe_provider=validapse`, certificado/SOL no obligatorios |
| Sync extendido | `apps/business/services/billing_sync_service.py` | Mapear nuevos campos al payload Laravel |

### 5.2 Laravel

| Componente | Path | Responsabilidad |
|---|---|---|
| Migration | `database/migrations/XXXX_add_cpe_provider_to_companies.php` | Campos nuevos |
| Cliente HTTP | `app/Services/Cpe/ValidapseClient.php` | `signAndSend()`, `getCdr()` (Guzzle) |
| Branch | `app/Services/DocumentService.php::sendToSunat()` | If/else por `cpe_provider` |
| Helper builder | `app/Services/GreenterService.php::buildUnsignedXml($document)` | Wrapper de `getFactory()->getBuilder()->build()` |
| Validación | `CompanyController::processRequestData()` | Aceptar nuevos campos |

---

## 6. Plan de implementación step-by-step

Cada paso es independiente y verificable. **Detenerse y validar antes del siguiente.**

### Paso 0 — Cuenta y configuración ValidaPSE
- [ ] Confirmar plan ValidaPSE adquirido y obtener `VALIDAPSE_MASTER_API_TOKEN`.
- [ ] Confirmar con soporte ValidaPSE: ¿soporta `SummaryDocuments` (resumen diario) y `CreditNote`? (Para roadmap, no bloquea spike.)
- [ ] Crear UNA empresa de prueba manualmente en su panel modo DEMO para validar credenciales.

### Paso 1 — Migration Django
- [ ] Agregar campos a `Business` (sección 4.1).
- [ ] `python manage.py makemigrations business && migrate`.
- [ ] Verificar: empresas existentes quedan con `cpe_provider='greenter'` automáticamente.

### Paso 2 — Cliente admin ValidaPSE (Django)
- [ ] `validapse_admin_client.py` con métodos: `crear_empresa()`, `actualizar_empresa()`, `obtener_empresa()`, `toggle_estado()`.
- [ ] Variable de entorno `VALIDAPSE_MASTER_API_TOKEN` en settings.
- [ ] Test unitario con mock de httpx.

### Paso 3 — Service de sincronización a ValidaPSE
- [ ] `validapse_sync_service.py::register_business(business, production_mode: bool)`.
- [ ] Guarda `validapse_empresa_id*` y `validapse_token_acceso*` en el `Business`.
- [ ] Manejo de errores: 4xx → marca `billing_sync_status='FAILED'` y guarda mensaje.

### Paso 4 — Admin actions Django
- [ ] Acción `register_in_validapse_staging`.
- [ ] Acción `register_in_validapse_production` (con confirmación).
- [ ] Validación condicional en form: si `cpe_provider=validapse`, certificado/SOL no requeridos; warning si igual están seteados.
- [ ] Mensaje de éxito visible en el admin con el `validapse_empresa_id` resultante.

### Paso 5 — Extender sync Django → Laravel
- [ ] `_map_business_to_api_format()` incluye los 3 campos nuevos.
- [ ] Test que verifique payload con `cpe_provider=greenter` (legacy intacto) y `cpe_provider=validapse`.

### Paso 6 — Migration Laravel
- [ ] `add_cpe_provider_to_companies.php`.
- [ ] `php artisan migrate` en staging.
- [ ] `Company.php` agrega campos a `$fillable` y `validapse_token_acceso` a `$hidden`.

### Paso 7 — Aceptar nuevos campos en sync Laravel
- [ ] `CompanyController::store()` y `update()` validan + persisten los 3 campos.
- [ ] Smoke test: sincronizar un business `validapse` desde Django y verificar columnas en `companies`.

### Paso 8 — Cliente HTTP ValidaPSE en Laravel
- [ ] `ValidapseClient.php` con `signAndSend(string $nombre, string $b64Xml, bool $produccion): array`.
- [ ] Resolver URL base según `$produccion` (sufijo `-demo`).
- [ ] Test con respuesta mock.

### Paso 9 — Helper unsigned XML en GreenterService
- [ ] `GreenterService::buildUnsignedXml($document): string` usando `getFactory()->getBuilder()->build()`.
- [ ] Test: emitir boleta dummy, validar XML resultante con XSD UBL local.

### Paso 10 — Branch en `DocumentService::sendToSunat()`
- [ ] If/else por `cpe_provider`.
- [ ] Persistencia del XML firmado decodificado y `codigo_hash`.
- [ ] `estado_sunat` derivado correctamente.
- [ ] **Path Greenter no se toca.**

### Paso 11 — End-to-end staging
- [ ] Empresa NRUS de prueba registrada en Validapse DEMO.
- [ ] Emitir 1 boleta desde el POS.
- [ ] Verificar: XML firmado guardado, hash, estado `ACEPTADO`, CDR consultable.

### Paso 12 — Documentar y promover
- [ ] Actualizar este .md con hallazgos reales del staging.
- [ ] Decidir si CDR se trae síncrono en `sendToSunat` o vía job posterior (recomendado: job, evita latencia en checkout).
- [ ] Promoción a producción solo cuando staging tenga ≥10 boletas exitosas.

---

## 7. Casos borde y decisiones explícitas

| Caso | Decisión |
|---|---|
| Empresa con `cpe_provider=validapse` y certificado cargado | El certificado se ignora silenciosamente. El admin muestra warning informativo. |
| Token ValidaPSE expirado o revocado | Captura 401, marca `estado_sunat='ERROR_PROVEEDOR'`, no reintenta automáticamente, alerta al admin. |
| Cuota de paquete agotada | Misma estrategia: error explícito, sin reintento, alerta. |
| Cambiar de greenter ↔ validapse en empresa existente con boletas emitidas | Permitido, pero advertencia visible. Las boletas históricas no cambian. |
| Empresa registrada en ValidaPSE pero sin sincronizar a Laravel | El facturador rechaza emisión con mensaje claro: "Empresa marcada como validapse pero sin token sincronizado". |
| `production_mode` cambia después de registrar en ValidaPSE | Requiere registrar la empresa en el otro ambiente (DEMO ↔ PRODUCCIÓN son cuentas separadas en ValidaPSE). |
| Falla de red al llamar ValidaPSE en runtime | El job `SendDocumentToSunat` ya tiene backoff `[30, 60, 120]`. Se reusa. |

---

## 8. Variables de entorno nuevas

**Django (`.env`):**
```
VALIDAPSE_MASTER_API_TOKEN=<token>
VALIDAPSE_BASE_URL=https://app.validapse.com
```

**Laravel (`.env`):**
```
# No requiere master token. El token por empresa viene en company.validapse_token_acceso.
VALIDAPSE_BASE_URL=https://app.validapse.com
VALIDAPSE_TIMEOUT_SECONDS=30
```

---

## 9. Endpoints ValidaPSE de referencia

**Gestión (master token):**
- `POST /api/empresas` — crear
- `GET /api/empresas/{id}` — leer (recupera token_acceso)
- `PUT /api/empresas/{id}` — actualizar
- `PATCH /api/empresas/{id}/toggle` — activar/desactivar
- `GET /api/empresas?page=&per_page=&search=&estado=&servidor=` — listar

**CPE (token por empresa) — PRODUCCIÓN:**
- `POST /api/cpe/generar` — solo firma (no usado en spike)
- `POST /api/cpe/generarenviar` — **firma + envía** (usado)
- `POST /api/cpe/enviar` — envía firmado externo (no usado en spike)
- `GET /api/cpe/consultar/{nombre}` — recupera CDR

**CPE — DEMO:** mismos paths con sufijo `-demo`.

Formato `nombre_archivo`: `RUC-TIPO-SERIE-NUMERO` sin `.xml`. Para boletas tipo = `03`.

---

## 10. Riesgos abiertos

1. **Resumen diario.** NRUS está obligado a enviar resumen diario de boletas (~7 días). Si ValidaPSE no soporta `SummaryDocuments` o no lo soporta vía `generarenviar`, hay que decidir flujo alterno antes de salir a producción. **Bloquea producción, no el spike.**
2. **Notas de crédito sobre boleta.** Para devoluciones/correcciones. Confirmar con ValidaPSE si `generarenviar` acepta `CreditNote`. Si no, follow-up.
3. **Registro PSE en SOL del cliente.** Trámite manual del cliente. Sin él, todas las boletas serán rechazadas por SUNAT con error de certificado no autorizado. **Documentar paso a paso en onboarding.**
4. **Costo por firma.** Modelo de paquete de ValidaPSE — monitorear consumo y exponer en admin (`firmas_usadas` viene en la respuesta de `GET /api/empresas/{id}`).

---

## 11. Criterios de éxito del spike

1. ✅ Empresa NRUS de prueba creada desde Django admin sin certificado.
2. ✅ Registrada en ValidaPSE DEMO con un click.
3. ✅ Sincronizada a Laravel staging.
4. ✅ Boleta electrónica emitida desde POS, firmada por ValidaPSE, aceptada por SUNAT (CDR `ACEPTADO`).
5. ✅ Empresas Greenter existentes siguen operando idénticas (regression test).
6. ✅ Cambiar `cpe_provider` desde el admin no requiere tocar código ni redeploys.

---

## 12. Decisiones pendientes (a confirmar antes de Paso 1)

- [ ] **¿CDR síncrono o asíncrono?** Recomendado: asíncrono (job al minuto). Define si `sendToSunat` retorna ya con CDR o con estado `EN_PROCESO`.
- [ ] **¿Mostrar `firmas_usadas` en admin?** Útil para evitar quedarse sin paquete.
- [ ] **¿Encriptación de tokens?** Por consistencia con el resto del sistema, NO en este spike. Issue separado.
