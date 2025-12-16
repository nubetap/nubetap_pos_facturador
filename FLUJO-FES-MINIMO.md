# Flujo Mínimo de Facturación Electrónica SUNAT

## Tabla de Contenidos
- [Requisitos Previos](#requisitos-previos)
- [1. Gestión de Negocio (Company)](#1-gestión-de-negocio-company)
- [2. Gestión de Sucursales (Branch)](#2-gestión-de-sucursales-branch)
- [3. Gestión de Clientes (Opcional)](#3-gestión-de-clientes-opcional)
- [4. Documentos Electrónicos Soportados](#4-documentos-electrónicos-soportados)
  - [4.1 Factura Electrónica (01)](#41-factura-electrónica-01)
  - [4.2 Boleta de Venta (03)](#42-boleta-de-venta-03)
  - [4.3 Nota de Crédito (07)](#43-nota-de-crédito-07)
  - [4.4 Nota de Débito (08)](#44-nota-de-débito-08)
  - [4.5 Resumen Diario de Boletas (RC)](#45-resumen-diario-de-boletas-rc)
- [5. Flujos Completos de Envío a SUNAT](#5-flujos-completos-de-envío-a-sunat)

---

## Requisitos Previos

### Credenciales SUNAT Necesarias:
1. **RUC** (11 dígitos) - Registro Único de Contribuyentes
2. **Usuario SOL** - Usuario de SUNAT Operaciones en Línea
3. **Clave SOL** - Contraseña del usuario SOL
4. **Certificado Digital** - Archivo `.pem` para firma digital
   - Emitido por entidad certificadora autorizada por SUNAT
   - Ubicación: `storage/app/public/certificado/certificado.pem`

### Ambiente de Pruebas:
```
Endpoint Beta: https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService
Usuario SOL Demo: MODDATOS
Clave SOL Demo: moddatos
RUC Demo: 20000000001
```

---

## 1. Gestión de Negocio (Company)

### 1.1 Crear Negocio

**Endpoint:** `POST /api/v1/companies`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

**Body (Campos Mínimos Obligatorios):**
```json
{
  "ruc": "20161515648",
  "razon_social": "MI EMPRESA SAC",
  "direccion": "Av. Principal 123",
  "ubigeo": "150101",
  "distrito": "Lima",
  "provincia": "Lima",
  "departamento": "Lima",
  "email": "contacto@miempresa.com",
  "usuario_sol": "MODDATOS",
  "clave_sol": "moddatos"
}
```

**Campos Opcionales:**
```json
{
  "nombre_comercial": "Mi Empresa",
  "telefono": "01-1234567",
  "web": "https://miempresa.com",
  "endpoint_beta": "https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService",
  "endpoint_produccion": "https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService",
  "modo_produccion": false,
  "activo": true
}
```

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Empresa creada correctamente",
  "data": {
    "id": 1,
    "ruc": "20161515648",
    "razon_social": "MI EMPRESA SAC",
    "usuario_sol": "MODDATOS",
    "modo_produccion": false,
    "activo": true,
    "created_at": "2025-10-28T10:00:00.000000Z"
  }
}
```

### 1.2 Listar Negocios

**Endpoint:** `GET /api/v1/companies`

**Respuesta:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "ruc": "20161515648",
      "razon_social": "MI EMPRESA SAC",
      "activo": true
    }
  ]
}
```

### 1.3 Ver Detalle de Negocio

**Endpoint:** `GET /api/v1/companies/{id}`

### 1.4 Actualizar Negocio

**Endpoint:** `PUT /api/v1/companies/{id}`

### 1.5 Activar/Desactivar Modo Producción

**Endpoint:** `POST /api/v1/companies/{id}/toggle-production`

---

## 2. Gestión de Sucursales (Branch)

### 2.1 Crear Sucursal

**Endpoint:** `POST /api/v1/branches`

**Body (Campos Mínimos Obligatorios):**
```json
{
  "company_id": 1,
  "codigo": "0001",
  "nombre": "Sucursal Principal",
  "direccion": "Av. Principal 123",
  "ubigeo": "150101",
  "distrito": "Lima",
  "provincia": "Lima",
  "departamento": "Lima"
}
```

**Campos Opcionales:**
```json
{
  "telefono": "01-1234567",
  "email": "sucursal@miempresa.com",
  "contacto_nombre": "Juan Pérez",
  "series_factura": ["F001", "F002"],
  "series_boleta": ["B001", "B002"],
  "series_nota_credito": ["FC01", "BC01"],
  "series_nota_debito": ["FD01", "BD01"],
  "series_guia_remision": ["T001"],
  "activo": true
}
```

**Respuesta Exitosa (201):**
```json
{
  "success": true,
  "message": "Sucursal creada correctamente",
  "data": {
    "id": 1,
    "company_id": 1,
    "codigo": "0001",
    "nombre": "Sucursal Principal",
    "activo": true,
    "created_at": "2025-10-28T10:00:00.000000Z"
  }
}
```

### 2.2 Listar Sucursales

**Endpoint:** `GET /api/v1/branches`

### 2.3 Listar Sucursales por Empresa

**Endpoint:** `GET /api/v1/companies/{company_id}/branches`

### 2.4 Ver Detalle de Sucursal

**Endpoint:** `GET /api/v1/branches/{id}`

### 2.5 Actualizar Sucursal

**Endpoint:** `PUT /api/v1/branches/{id}`

---

## 3. Gestión de Clientes (Opcional)

### 3.1 Crear Cliente

**Endpoint:** `POST /api/v1/clients`

**Body (Campos Mínimos):**
```json
{
  "tipo_documento": "6",
  "numero_documento": "20123456789",
  "razon_social": "CLIENTE EMPRESA SAC"
}
```

**Tipos de Documento:**
- `0` = Sin documento
- `1` = DNI (8 dígitos)
- `4` = Carné de extranjería
- `6` = RUC (11 dígitos)

**Nota:** Los clientes pueden crearse inline al momento de crear una factura/boleta.

---

## 4. Documentos Electrónicos Soportados

### 4.1 Factura Electrónica (01)

#### Crear Factura

**Endpoint:** `POST /api/v1/invoices`

**Body (Ejemplo Mínimo):**
```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "F001",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "forma_pago_tipo": "Contado",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "CLIENTE EMPRESA SAC",
    "direccion": "Av. Cliente 456"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP Core i5",
      "unidad": "NIU",
      "cantidad": 2,
      "mto_valor_unitario": 2542.37,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
```

**Campos del Cliente:**
- `tipo_documento`: `1` (DNI), `4` (CE), `6` (RUC), `0` (Sin doc)
- `numero_documento`: Número del documento
- `razon_social`: Nombre o razón social (obligatorio)
- `direccion`: Dirección fiscal (recomendado)

**Campos del Detalle:**
- `codigo`: Código interno del producto
- `descripcion`: Descripción del producto/servicio
- `unidad`: Código de unidad de medida (ver tabla SUNAT)
  - `NIU` = Unidad
  - `ZZ` = Servicio
- `cantidad`: Cantidad (mínimo 0.001)
- `mto_valor_unitario`: Valor unitario sin IGV
- `porcentaje_igv`: Porcentaje de IGV (18 por defecto)
- `tip_afe_igv`: Tipo de afectación del IGV
  - `10` = Gravado - Operación Onerosa
  - `20` = Exonerado - Operación Onerosa
  - `30` = Inafecto - Operación Onerosa
  - `40` = Exportación

**Respuesta:**
```json
{
  "success": true,
  "message": "Factura creada correctamente",
  "data": {
    "id": 1,
    "numero_completo": "F001-000001",
    "serie": "F001",
    "correlativo": "000001",
    "fecha_emision": "2025-10-28",
    "moneda": "PEN",
    "mto_oper_gravadas": 5084.74,
    "mto_igv": 915.25,
    "mto_imp_venta": 5999.99,
    "estado_sunat": "PENDIENTE"
  }
}
```

#### Enviar Factura a SUNAT (Sincrónico)

**Endpoint:** `POST /api/v1/invoices/{id}/send-sunat`

**Respuesta Exitosa:**
```json
{
  "success": true,
  "message": "Factura enviada exitosamente a SUNAT",
  "data": {
    "id": 1,
    "numero_completo": "F001-000001",
    "estado_sunat": "ACEPTADO",
    "respuesta_sunat": "{\"code\":\"0\",\"description\":\"La Factura ha sido aceptada\"}",
    "xml_path": "invoices/xml/28102025/F001-000001.xml",
    "cdr_path": "invoices/cdr/28102025/R-F001-000001.zip",
    "codigo_hash": "ABC123xyz=="
  }
}
```

#### Enviar Factura a SUNAT (Asincrónico - Recomendado)

**Endpoint:** `POST /api/v1/invoices/{id}/send-sunat-async`

**Respuesta:**
```json
{
  "success": true,
  "message": "Factura agregada a la cola de envío. Recibirá una notificación cuando se complete el proceso.",
  "data": {
    "id": 1,
    "numero_completo": "F001-000001",
    "estado_sunat": "EN_COLA"
  }
}
```

#### Descargar Archivos de Factura

**XML:** `GET /api/v1/invoices/{id}/download-xml`
**CDR (Constancia):** `GET /api/v1/invoices/{id}/download-cdr`
**PDF:** `GET /api/v1/invoices/{id}/download-pdf`

---

### 4.2 Boleta de Venta (03)

#### Crear Boleta

**Endpoint:** `POST /api/v1/boletas`

**Body (Ejemplo Mínimo):**
```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "B001",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "metodo_envio": "individual",
  "client": {
    "tipo_documento": "1",
    "numero_documento": "12345678",
    "razon_social": "Juan Pérez García"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP Core i5",
      "unidad": "NIU",
      "cantidad": 1,
      "mto_valor_unitario": 2542.37,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
```

**Campo Especial:**
- `metodo_envio`:
  - `individual` = Envío inmediato a SUNAT
  - `resumen_diario` = Incluir en resumen diario (envío al final del día)

**Diferencias con Factura:**
- Cliente puede ser DNI, RUC o sin documento
- Generalmente para consumidores finales
- Puede enviarse individual o por resumen diario

#### Enviar Boleta a SUNAT

**Sincrónico:** `POST /api/v1/boletas/{id}/send-sunat`
**Asincrónico:** `POST /api/v1/boletas/{id}/send-sunat-async`

#### Descargar Archivos

**XML:** `GET /api/v1/boletas/{id}/download-xml`
**CDR:** `GET /api/v1/boletas/{id}/download-cdr`
**PDF:** `GET /api/v1/boletas/{id}/download-pdf`

---

### 4.3 Nota de Crédito (07)

#### Crear Nota de Crédito

**Endpoint:** `POST /api/v1/credit-notes`

**Body (Ejemplo Mínimo):**
```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "FC01",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "tipo_doc_afectado": "01",
  "num_doc_afectado": "F001-000001",
  "cod_motivo": "01",
  "des_motivo": "Anulación de la operación",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "CLIENTE EMPRESA SAC"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP Core i5",
      "unidad": "NIU",
      "cantidad": 1,
      "mto_valor_unitario": 2542.37,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
```

**Campos Especiales:**
- `tipo_doc_afectado`: Tipo de documento que se modifica
  - `01` = Factura
  - `03` = Boleta
  - `07` = Nota de Crédito (para modificar otra NC)
  - `08` = Nota de Débito
- `num_doc_afectado`: Número completo del documento (ej: F001-000001)
- `cod_motivo`: Código de motivo según catálogo 09 SUNAT
  - `01` = Anulación de la operación
  - `02` = Anulación por error en el RUC
  - `03` = Corrección por error en la descripción
  - `04` = Descuento global
  - `05` = Descuento por ítem
  - `06` = Devolución total
  - `07` = Devolución por ítem
  - `08` = Bonificación
  - `09` = Disminución en el valor
  - `10` = Otros conceptos
- `des_motivo`: Descripción del motivo (máx 250 caracteres)

#### Enviar Nota de Crédito a SUNAT

**Sincrónico:** `POST /api/v1/credit-notes/{id}/send-sunat`
**Asincrónico:** `POST /api/v1/credit-notes/{id}/send-sunat-async`

#### Descargar Archivos

**XML:** `GET /api/v1/credit-notes/{id}/download-xml`
**CDR:** `GET /api/v1/credit-notes/{id}/download-cdr`
**PDF:** `GET /api/v1/credit-notes/{id}/download-pdf`

---

### 4.4 Nota de Débito (08)

#### Crear Nota de Débito

**Endpoint:** `POST /api/v1/debit-notes`

**Body (Ejemplo Mínimo):**
```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "FD01",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "tipo_doc_afectado": "01",
  "num_doc_afectado": "F001-000001",
  "cod_motivo": "01",
  "des_motivo": "Intereses por mora",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "CLIENTE EMPRESA SAC"
  },
  "detalles": [
    {
      "codigo": "INT001",
      "descripcion": "Intereses por mora en pago",
      "unidad": "ZZ",
      "cantidad": 1,
      "mto_valor_unitario": 100.00,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
```

**Códigos de Motivo (Catálogo 10 SUNAT):**
- `01` = Intereses por mora
- `02` = Aumento en el valor
- `03` = Penalidades/otros conceptos

#### Enviar Nota de Débito a SUNAT

**Sincrónico:** `POST /api/v1/debit-notes/{id}/send-sunat`
**Asincrónico:** `POST /api/v1/debit-notes/{id}/send-sunat-async`

#### Descargar Archivos

**XML:** `GET /api/v1/debit-notes/{id}/download-xml`
**CDR:** `GET /api/v1/debit-notes/{id}/download-cdr`
**PDF:** `GET /api/v1/debit-notes/{id}/download-pdf`

---

### 4.5 Resumen Diario de Boletas (RC)

El resumen diario agrupa todas las boletas emitidas en un día para enviarlas en un solo lote a SUNAT.

#### Crear Resumen Diario

**Endpoint:** `POST /api/v1/daily-summaries`

**Body (Ejemplo Mínimo):**
```json
{
  "company_id": 1,
  "branch_id": 1,
  "fecha_generacion": "2025-10-28",
  "fecha_resumen": "2025-10-27",
  "moneda": "PEN",
  "detalles": [
    {
      "tipo_documento": "03",
      "serie_numero": "B001-000001",
      "estado": "1",
      "cliente_tipo": "1",
      "cliente_numero": "12345678",
      "total": 118.00,
      "mto_oper_gravadas": 100.00,
      "mto_igv": 18.00
    },
    {
      "tipo_documento": "03",
      "serie_numero": "B001-000002",
      "estado": "1",
      "cliente_tipo": "1",
      "cliente_numero": "87654321",
      "total": 59.00,
      "mto_oper_gravadas": 50.00,
      "mto_igv": 9.00
    }
  ]
}
```

**Campos Especiales:**
- `fecha_generacion`: Fecha en que se crea el resumen (hoy)
- `fecha_resumen`: Fecha de las boletas resumidas (hasta 7 días atrás)
- `detalles[].estado`:
  - `1` = Adicionar (nueva boleta)
  - `2` = Modificar
  - `3` = Anular
- `detalles[].tipo_documento`:
  - `03` = Boleta
  - `07` = Nota de Crédito
  - `08` = Nota de Débito

#### Enviar Resumen a SUNAT

**Endpoint:** `POST /api/v1/daily-summaries/{id}/send-sunat`

**Respuesta:**
```json
{
  "success": true,
  "message": "Resumen enviado correctamente a SUNAT",
  "ticket": "1761669433022",
  "data": {
    "id": 1,
    "numero_completo": "RC-20251028-001",
    "estado_sunat": "PROCESANDO",
    "ticket": "1761669433022"
  }
}
```

**Importante:** A diferencia de facturas/boletas, el resumen NO se acepta inmediatamente. SUNAT devuelve un **ticket** que debe consultarse después.

#### Consultar Estado del Resumen

**Endpoint:** `POST /api/v1/daily-summaries/{id}/check-status`

**Respuesta (después de 1-5 minutos):**
```json
{
  "success": true,
  "message": "Estado consultado correctamente",
  "data": {
    "id": 1,
    "estado_sunat": "ACEPTADO",
    "respuesta_sunat": {
      "code": "0",
      "description": "La Comunicación de baja ha sido aceptada"
    }
  }
}
```

#### Descargar Archivos

**XML:** `GET /api/v1/daily-summaries/{id}/download-xml`
**CDR:** `GET /api/v1/daily-summaries/{id}/download-cdr`
**PDF:** `GET /api/v1/daily-summaries/{id}/download-pdf`

---

## 5. Flujos Completos de Envío a SUNAT

### 5.1 Flujo: Primera Factura (Desde Cero)

```
PASO 1: Crear Negocio
POST /api/v1/companies
Body: {
  "ruc": "20161515648",
  "razon_social": "MI EMPRESA SAC",
  "direccion": "Av. Principal 123",
  "ubigeo": "150101",
  "distrito": "Lima",
  "provincia": "Lima",
  "departamento": "Lima",
  "email": "contacto@miempresa.com",
  "usuario_sol": "MODDATOS",
  "clave_sol": "moddatos"
}
Respuesta: { "data": { "id": 1 } }

↓

PASO 2: Subir Certificado Digital
- Subir archivo .pem a: storage/app/public/certificado/certificado.pem
- O configurar en la BD el campo certificado_pem

↓

PASO 3: Crear Sucursal
POST /api/v1/branches
Body: {
  "company_id": 1,
  "codigo": "0001",
  "nombre": "Sucursal Principal",
  "direccion": "Av. Principal 123",
  "ubigeo": "150101",
  "distrito": "Lima",
  "provincia": "Lima",
  "departamento": "Lima"
}
Respuesta: { "data": { "id": 1 } }

↓

PASO 4: Crear Factura
POST /api/v1/invoices
Body: {
  "company_id": 1,
  "branch_id": 1,
  "serie": "F001",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "forma_pago_tipo": "Contado",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "CLIENTE EMPRESA SAC"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP Core i5",
      "unidad": "NIU",
      "cantidad": 2,
      "mto_valor_unitario": 2542.37,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
Respuesta: { "data": { "id": 1, "numero_completo": "F001-000001" } }

↓

PASO 5: Enviar a SUNAT (Asincrónico)
POST /api/v1/invoices/1/send-sunat-async
Respuesta: { "success": true, "data": { "estado_sunat": "EN_COLA" } }

↓

PASO 6: Verificar Estado (después de 5-30 segundos)
GET /api/v1/invoices/1
Respuesta: { "data": { "estado_sunat": "ACEPTADO" } }

↓

PASO 7: Descargar Archivos (Opcional)
GET /api/v1/invoices/1/download-xml
GET /api/v1/invoices/1/download-cdr
GET /api/v1/invoices/1/download-pdf

✅ FACTURA ENVIADA Y ACEPTADA POR SUNAT
```

---

### 5.2 Flujo: Boleta Individual

```
REQUISITO PREVIO: Negocio y Sucursal ya creados (IDs: company_id=1, branch_id=1)

↓

PASO 1: Crear Boleta
POST /api/v1/boletas
Body: {
  "company_id": 1,
  "branch_id": 1,
  "serie": "B001",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "metodo_envio": "individual",
  "client": {
    "tipo_documento": "1",
    "numero_documento": "12345678",
    "razon_social": "Juan Pérez García"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Producto 1",
      "unidad": "NIU",
      "cantidad": 1,
      "mto_valor_unitario": 100.00,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
Respuesta: { "data": { "id": 1, "numero_completo": "B001-000001" } }

↓

PASO 2: Enviar a SUNAT
POST /api/v1/boletas/1/send-sunat-async

↓

PASO 3: Verificar Estado
GET /api/v1/boletas/1
Respuesta: { "data": { "estado_sunat": "ACEPTADO" } }

✅ BOLETA ENVIADA Y ACEPTADA POR SUNAT
```

---

### 5.3 Flujo: Nota de Crédito (Anulación de Factura)

```
REQUISITO PREVIO: Factura F001-000001 ya emitida y aceptada por SUNAT

↓

PASO 1: Crear Nota de Crédito
POST /api/v1/credit-notes
Body: {
  "company_id": 1,
  "branch_id": 1,
  "serie": "FC01",
  "fecha_emision": "2025-10-28",
  "moneda": "PEN",
  "tipo_doc_afectado": "01",
  "num_doc_afectado": "F001-000001",
  "cod_motivo": "01",
  "des_motivo": "Anulación de la operación",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20123456789",
    "razon_social": "CLIENTE EMPRESA SAC"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP Core i5",
      "unidad": "NIU",
      "cantidad": 2,
      "mto_valor_unitario": 2542.37,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ]
}
Respuesta: { "data": { "id": 1, "numero_completo": "FC01-000001" } }

↓

PASO 2: Enviar a SUNAT
POST /api/v1/credit-notes/1/send-sunat-async

↓

PASO 3: Verificar Estado
GET /api/v1/credit-notes/1
Respuesta: { "data": { "estado_sunat": "ACEPTADO" } }

✅ NOTA DE CRÉDITO ENVIADA Y ACEPTADA
```

---

### 5.4 Flujo: Resumen Diario de Boletas

```
REQUISITO PREVIO: 3 boletas creadas con metodo_envio="resumen_diario"
- B001-000005 (S/ 3,254.74)
- B001-000006 (S/ 3,254.74)
- B001-000007 (S/ 3,254.74)

↓

PASO 1: Crear Resumen Diario
POST /api/v1/daily-summaries
Body: {
  "company_id": 1,
  "branch_id": 1,
  "fecha_generacion": "2025-10-28",
  "fecha_resumen": "2025-10-27",
  "moneda": "PEN",
  "detalles": [
    {
      "tipo_documento": "03",
      "serie_numero": "B001-000005",
      "estado": "1",
      "cliente_tipo": "1",
      "cliente_numero": "12345678",
      "total": "3254.74",
      "mto_oper_gravadas": "2554.86",
      "mto_oper_exoneradas": "240.00",
      "mto_igv": "459.88"
    },
    {
      "tipo_documento": "03",
      "serie_numero": "B001-000006",
      "estado": "1",
      "cliente_tipo": "1",
      "cliente_numero": "12345678",
      "total": "3254.74",
      "mto_oper_gravadas": "2554.86",
      "mto_oper_exoneradas": "240.00",
      "mto_igv": "459.88"
    },
    {
      "tipo_documento": "03",
      "serie_numero": "B001-000007",
      "estado": "1",
      "cliente_tipo": "1",
      "cliente_numero": "12345678",
      "total": "3254.74",
      "mto_oper_gravadas": "2554.86",
      "mto_oper_exoneradas": "240.00",
      "mto_igv": "459.88"
    }
  ]
}
Respuesta: { "data": { "id": 1, "numero_completo": "RC-20251028-001" } }

↓

PASO 2: Enviar Resumen a SUNAT
POST /api/v1/daily-summaries/1/send-sunat
Respuesta: {
  "success": true,
  "ticket": "1761669433022",
  "data": { "estado_sunat": "PROCESANDO" }
}

↓

PASO 3: Esperar 1-5 minutos ⏱️

↓

PASO 4: Consultar Estado del Ticket
POST /api/v1/daily-summaries/1/check-status
Respuesta: {
  "success": true,
  "data": {
    "estado_sunat": "ACEPTADO",
    "respuesta_sunat": {
      "code": "0",
      "description": "La Comunicación ha sido aceptada"
    }
  }
}

✅ RESUMEN DIARIO ACEPTADO POR SUNAT
```

---

## Códigos de Estado SUNAT

| Estado | Descripción | Acción |
|--------|-------------|--------|
| `PENDIENTE` | Documento creado, no enviado | Enviar a SUNAT |
| `EN_COLA` | En cola para envío asíncrono | Esperar procesamiento |
| `PROCESANDO` | Enviado, esperando respuesta | Consultar estado (solo resúmenes) |
| `ACEPTADO` | Aceptado por SUNAT ✅ | Documento válido |
| `RECHAZADO` | Rechazado por SUNAT ❌ | Revisar error y corregir |
| `ERROR` | Error en el envío ⚠️ | Reintentar o revisar logs |

---

## Códigos de Unidad de Medida Más Usados

| Código | Descripción |
|--------|-------------|
| `NIU` | Unidad (bienes) |
| `ZZ` | Servicio |
| `KGM` | Kilogramo |
| `LTR` | Litro |
| `MTR` | Metro |
| `MTK` | Metro cuadrado |
| `MTQ` | Metro cúbico |
| `GRM` | Gramo |
| `TNE` | Tonelada |
| `SET` | Juego |
| `DZN` | Docena |

Ver catálogo completo en: [Catálogo 03 SUNAT](https://cpe.sunat.gob.pe/sites/default/files/inline-files/Catalogos_0.xlsx)

---

## Códigos de Respuesta HTTP

| Código | Significado | Acción |
|--------|-------------|--------|
| `200` | OK | Operación exitosa |
| `201` | Created | Recurso creado exitosamente |
| `400` | Bad Request | Revisar datos enviados |
| `401` | Unauthorized | Token inválido o expirado |
| `404` | Not Found | Recurso no encontrado |
| `422` | Validation Error | Errores de validación en campos |
| `500` | Server Error | Error interno del servidor |

---

## Notas Importantes

### Sobre el Certificado Digital:
- Debe estar en formato PEM
- Ubicación: `storage/app/public/certificado/certificado.pem`
- Es único por empresa (RUC)
- Necesario para firmar digitalmente los XML

### Sobre el Ambiente de Pruebas (Beta):
- Usar `modo_produccion: false`
- Credenciales demo: `MODDATOS` / `moddatos`
- Endpoint: `https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService`
- Los documentos emitidos en Beta NO son válidos tributariamente

### Sobre el Ambiente de Producción:
- Usar `modo_produccion: true`
- Credenciales reales de SUNAT
- Endpoint: `https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService`
- Los documentos SÍ son válidos tributariamente

### Sobre el Envío Asíncrono vs Sincrónico:
- **Asíncrono (Recomendado)**: No bloquea, procesa en cola, permite reintentos
- **Sincrónico**: Bloquea hasta recibir respuesta, sin reintentos automáticos

### Sobre el Worker de Colas:
- Se ejecuta en el contenedor `sunat_api_queue`
- Procesa la cola `sunat-send`
- Reintentos automáticos: 3 intentos
- Backoff: 30s, 60s, 120s entre reintentos

---

## Soporte y Documentación Adicional

- **Catálogos SUNAT**: https://cpe.sunat.gob.pe/sites/default/files/inline-files/Catalogos_0.xlsx
- **Documentación Greenter**: https://greenter.dev/
- **Portal SUNAT**: https://www.sunat.gob.pe/

---

**Generado:** 2025-10-28
**Versión:** 1.0
**Sistema:** API Facturación Electrónica SUNAT - Laravel 12
