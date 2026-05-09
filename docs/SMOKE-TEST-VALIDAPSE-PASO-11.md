# Smoke test ValidaPSE — Paso 11

**Objetivo:** validar end-to-end que una empresa NRUS marcada como `cpe_provider=validapse` en Django staging emite una boleta DEMO contra ValidaPSE sin tocar datos reales.

**Pre-condición:** Pasos 1–10 desplegados en staging (Django + Laravel facturador).

**No bloqueante:** se ejecuta en ambiente DEMO (`Business.production_mode=False` → endpoints `-demo` de ValidaPSE → no llega a SUNAT real).

---

## Pre-requisitos antes de empezar

- [ ] `VALIDAPSE_MASTER_API_TOKEN` configurado en Django `.env` (staging).
- [ ] `VALIDAPSE_BASE_URL=https://app.validapse.com` en Django `.env`.
- [ ] Cuenta de prueba ValidaPSE con plan/paquete activo (verificar en panel web).
- [ ] Migrations aplicadas:
  - `python manage.py migrate business` (Django, migration 0018).
  - `php artisan migrate` (Laravel, migration `2026_05_07_120000_add_cpe_provider_validapse_to_companies_table`).
- [ ] `composer dump-autoload` ejecutado en Laravel (registra `App\Services\Cpe\*` y `App\Exceptions\ValidapseException`).
- [ ] Lint OK:
  ```bash
  php -l app/Services/DocumentService.php
  php -l app/Services/GreenterService.php
  php -l app/Services/Cpe/UnsignedXmlBuilder.php
  php -l app/Services/Cpe/ValidapseClient.php
  php -l app/Exceptions/ValidapseException.php
  php -l app/Models/Company.php
  ```

---

## Datos del test (rellenar antes de ejecutar)

| Campo | Valor |
|---|---|
| Business ID en Django |  |
| RUC del Business |  |
| Razón social |  |
| Series de boleta de prueba | `B001` |
| Correlativo a usar | (siguiente disponible en Django) |

---

## Checkpoint 1 — Empresa lista en Django

**Cómo:** Django admin → Business → abrir la empresa NRUS de prueba.

**Verificar:**
- [ ] `cpe_provider` está en `validapse`.
- [ ] `production_mode` está en **False** (staging).
- [ ] Tiene RUC, razón social, dirección, ubigeo, distrito, provincia, departamento, `sunat_user`, `sunat_password` (Django sigue exigiéndolos aunque sea ValidaPSE — no los cambiamos en spike).
- [ ] **NO** necesita certificado digital ni `certificate_password`.

**Si falla:** completar campos faltantes. La acción de admin omite la empresa con razón clara si falta algo.

---

## Checkpoint 2 — Registrar empresa en ValidaPSE DEMO

**Cómo:** Django admin → Business → seleccionar la empresa → menú Action → **"🟦 Registrar en ValidaPSE (STAGING / DEMO)"** → Go.

**Verificar mensaje del admin:**
- [ ] `1 empresa(s) registrada(s) en ValidaPSE STAGING`.

**Verificar en BD (recargar el detalle del admin):**
- [ ] `validapse_empresa_id_staging` tiene un id (entero > 0).
- [ ] `validapse_token_acceso_staging` tiene un string largo.
- [ ] `validapse_firmas_usadas` muestra `0`.
- [ ] `validapse_synced_at` tiene timestamp reciente.

**Verificar en panel ValidaPSE web:**
- [ ] La empresa aparece en su listado en ambiente DEMO.

**Si falla:**
- Mensaje "Omitida — faltan ...": completar campos en el Business y reintentar.
- Mensaje "Error — HTTP 401 ...": verificar `VALIDAPSE_MASTER_API_TOKEN` en `.env`.
- Mensaje "Error — HTTP 422 ... ruc ya registrado": la empresa ya existe en ValidaPSE DEMO. Borrarla del panel ValidaPSE o usar otro RUC.
- Mensaje "Error — HTTP 4xx con detalles del campo": validación ValidaPSE rechazó algún campo (ej. `razon_social` muy larga). Ajustar.

---

## Checkpoint 3 — Sincronizar a Laravel staging

**Cómo:** mismo admin → seleccionar empresa → **"🟡 Sincronizar a STAGING (empresa + sucursales + correlativos)"** → Go.

Esto encola tareas Celery. Espera ~10s a que se procesen.

**Verificar en Django:**
- [ ] `php_company_id_staging` quedó poblado (id de la `Company` en Laravel staging).
- [ ] `billing_sync_status` = `COMPLETED`.

**Verificar en Laravel staging (tinker):**
```bash
php artisan tinker
>>> $c = App\Models\Company::where('ruc', '<TU_RUC>')->where('modo_produccion', false)->first();
>>> [
...   'id' => $c->id,
...   'ruc' => $c->ruc,
...   'modo_produccion' => $c->modo_produccion,
...   'cpe_provider' => $c->cpe_provider,
...   'has_token' => !empty($c->validapse_token_acceso),
...   'validapse_empresa_id' => $c->validapse_empresa_id,
... ];
```

**Esperado:**
- [ ] `modo_produccion` = `false`.
- [ ] `cpe_provider` = `'validapse'`.
- [ ] `has_token` = `true`.
- [ ] `validapse_empresa_id` coincide con el id que se vio en Checkpoint 2.

**Si falla:**
- `cpe_provider` sigue en `'greenter'`: el sync no recibió el campo. Ver Paso 5 (`_map_business_to_api_format`) y logs Celery.
- `has_token` = false: el sync corrió pero no envió el token. Ver Paso 5.
- 422 en logs Laravel: `StoreCompanyRequest`/`UpdateCompanyRequest` rechazó algún campo. Ver Paso 7.

---

## Checkpoint 4 — Verificar endpoint correcto antes de disparar

**Cómo:** tinker en Laravel.

```bash
php artisan tinker
>>> $c = App\Models\Company::where('ruc', '<TU_RUC>')->where('modo_produccion', false)->first();
>>> $expected = $c->modo_produccion
...   ? 'https://app.validapse.com/api/cpe/generarenviar'
...   : 'https://app.validapse.com/api/cpe/generarenviar-demo';
>>> echo "Endpoint que se usará: $expected";
```

**Verificar:**
- [ ] El output dice `…/generarenviar-demo` (no el sin sufijo).

**Si falla:** algo está mal en `modo_produccion`. NO continuar.

---

## Checkpoint 5 — Smoke test del cliente HTTP aislado

Antes de emitir una boleta real, validar que el cliente puede hablar con ValidaPSE.

**Cómo:** tinker en Laravel.

```bash
php artisan tinker
>>> $c = App\Models\Company::where('ruc', '<TU_RUC>')->where('modo_produccion', false)->first();
>>> $client = app(App\Services\Cpe\ValidapseClient::class);
>>> // Probar consultar un nombre que NO existe → debe responder limpio (4xx con mensaje claro)
>>> try {
...   $client->getCdr($c->validapse_token_acceso, false, 'fake-doc-12345');
... } catch (App\Exceptions\ValidapseException $e) {
...   echo "OK ValidapseException: status={$e->httpStatus}, msg={$e->userMessage}";
... }
```

**Esperado:** mensaje `OK ValidapseException` con `httpStatus` 404 o 4xx y mensaje legible. Esto valida que:
- El token funciona (sino sería 401).
- El endpoint responde.
- El parser de errores del cliente funciona.

**Si falla:**
- `ConnectionException`: red bloqueada (firewall corporativo, VPN). Verificar acceso a `app.validapse.com`.
- `httpStatus=401`: token inválido. Re-ejecutar Checkpoint 2 y 3.
- `httpStatus=null`: timeout. Subir `VALIDAPSE_TIMEOUT` o verificar latencia.

---

## Checkpoint 6 — Crear una boleta de prueba en Django

**Cómo:** Django admin → o vía API POS según tu flujo normal.

**Datos mínimos:**
- Tipo documento: `03` (Boleta).
- Serie: `B001`.
- Correlativo: el siguiente disponible en la sucursal.
- Cliente: DNI dummy (ej. `99999999`, `Cliente Genérico`).
- 1 ítem: descripción simple, cantidad 1, precio 10.00 PEN.
- Total: 10.00 PEN (con IGV).

**Verificar:**
- [ ] La boleta queda creada con `estado_sunat = null` o `'PENDIENTE'`.
- [ ] Tiene XML pendiente (sin `xml_path` aún).

---

## Checkpoint 7 — Disparar `sendToSunat` (el momento de la verdad)

**Cómo:** en el flujo del POS — el endpoint `POST /v1/boletas/{id}/send-to-sunat` (o async equivalente) debe ejecutarse normalmente. **No tienes que hacer nada especial**, el branch en `DocumentService` se activa automáticamente al detectar `cpe_provider=validapse`.

**Si quieres dispararlo manual desde tinker:**
```bash
php artisan tinker
>>> $boleta = App\Models\Boleta::where('serie', 'B001')->where('correlativo', '<N>')->first();
>>> $service = app(App\Services\DocumentService::class);
>>> $result = $service->sendToSunat($boleta, 'boleta');
>>> $result;
```

**Esperado en `$result`:**
- [ ] `success` = `true`.
- [ ] `error` = `null`.
- [ ] `document` es la boleta refrescada.

**Verificar boleta en BD:**
```bash
>>> $boleta->refresh();
>>> [
...   'estado_sunat' => $boleta->estado_sunat,
...   'xml_path' => $boleta->xml_path ? 'OK' : 'MISSING',
...   'codigo_hash' => $boleta->codigo_hash ? 'OK' : 'MISSING',
...   'respuesta' => json_decode($boleta->respuesta_sunat, true),
... ];
```

**Esperado:**
- [ ] `estado_sunat` = `'ACEPTADO'`.
- [ ] `xml_path` = `'OK'`.
- [ ] `codigo_hash` = `'OK'`.
- [ ] `respuesta.provider` = `'validapse'`.
- [ ] `respuesta.estado` = código de éxito de ValidaPSE (ej. 200).
- [ ] `respuesta.external_id` tiene un valor (id de ValidaPSE).

---

## Checkpoint 8 — Verificar que el XML está firmado y es válido

**Cómo:** descargar el XML guardado.

```bash
>>> $boleta->xml_url;  # URL del XML firmado en S3 (o local según config)
```

**Verificar:**
- [ ] El XML descargado contiene el bloque `<ds:Signature>`.
- [ ] `<ds:X509Certificate>` adentro NO es el certificado de la empresa NRUS (no tiene uno) — es el certificado **PSE de ValidaPSE**.
- [ ] El RUC en `<cbc:RegistrationName>` y `<cac:PartyIdentification>/<cbc:ID>` corresponde al de la empresa NRUS.

**Si falla:**
- XML sin `<ds:Signature>`: el response de ValidaPSE no traía XML firmado. Revisar logs.
- RUC no coincide: el XML se construyó mal. Revisar `prepareDocumentData`.

---

## Checkpoint 9 — Verificar que ValidaPSE registró la firma

**Cómo:** Django admin → Business → seleccionar empresa → acción **"🔄 Refrescar datos de ValidaPSE (token + firmas usadas)"**.

Recargar detalle:
- [ ] `validapse_firmas_usadas` ahora muestra `1` (o el número anterior + 1).

**O directo en panel ValidaPSE web:** ver el contador de firmas consumidas de la empresa.

---

## Checkpoint 10 — Verificar regresión: una empresa Greenter sigue funcionando

**Importante:** validar que no rompimos el flujo Greenter.

**Cómo:** tomar otra empresa de staging que tenga `cpe_provider='greenter'` y emitir una boleta normal.

**Esperado:**
- [ ] La boleta firma + envía a SUNAT con Greenter como siempre (path original intacto).
- [ ] `estado_sunat` queda `'ACEPTADO'` con CDR.

**Si falla:** algo del branch en `DocumentService::sendToSunat` afectó el path principal. Revisar el `if ($company->usesValidapse())`.

---

## Resultado del smoke test

Marcar UNO al final:

- [ ] **PASS** — Todos los checkpoints OK. Spike validado. Listo para Paso 12.
- [ ] **PASS PARCIAL** — Checkpoints 1–9 OK pero hubo algo menor que ajustar. Anotar abajo.
- [ ] **FAIL** — Bloqueante en checkpoint N. Anotar abajo y reportar para fix.

### Notas / hallazgos durante el test

```
(rellenar acá lo que aparezca: warnings, comportamientos no documentados,
diferencias entre lo que decía la doc de ValidaPSE y lo que devolvió, etc.)
```

### Métricas observadas

- Latencia de `signAndSend` (DEMO): _____ ms (esperado ~2-5s).
- Tamaño del XML firmado: _____ KB.
- Errores transitorios encontrados: _____.

---

## Pendientes que probablemente surjan (decidir en Paso 12)

1. **Polling de CDR.** Hoy `sendToSunatViaValidapse` deja la boleta en `ACEPTADO` sin CDR descargado. Si necesitas el CDR para reportes/contabilidad: agregar un job que llame a `ValidapseClient::getCdr()` 1-2 minutos después.
2. **Resumen diario de boletas (NRUS está obligado).** No está cubierto en este spike. Confirmar con ValidaPSE si `/api/cpe/generarenviar` acepta `SummaryDocuments` o si requieren otro endpoint.
3. **Notas de crédito sobre boleta.** No probado. Probable que funcione idéntico (mismo flujo `sendToSunatViaValidapse` con `documentType='credit_note'`) pero hay que validar.
4. **Anulaciones.** NRUS las hace vía resumen diario, no por baja. Mismo follow-up que punto 2.
5. **Logo / PDF.** Si el cliente NRUS necesita PDF de la boleta, el path actual lo genera con Greenter. Validar que funciona sin certificado.
