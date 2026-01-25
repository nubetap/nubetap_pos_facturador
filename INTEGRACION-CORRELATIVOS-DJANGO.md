# Integracion de Correlativos con Backend Django

## SOLUCION IMPLEMENTADA (25/01/2026)

### Cambio Principal: PHP Acepta Correlativo desde Django

**Ahora los endpoints de facturas y boletas aceptan el parametro `correlativo` opcional.**

| Endpoint           | Metodo | Parametro Nuevo               |
| ------------------ | ------ | ----------------------------- |
| `/api/v1/boletas`  | POST   | `correlativo` (int, opcional) |
| `/api/v1/invoices` | POST   | `correlativo` (int, opcional) |

### Comportamiento

1. **Si Django envia `correlativo`**: PHP lo usa directamente y sincroniza su tabla
2. **Si Django NO envia `correlativo`**: PHP auto-genera (compatibilidad hacia atras)

### Payload Actualizado

```json
{
  "company_id": 1,
  "branch_id": 12,
  "serie": "B003",
  "correlativo": 32,
  "fecha_emision": "2026-01-25",
  "metodo_envio": "individual",
  "client": { ... },
  "detalles": [ ... ]
}
```

### Response

```json
{
    "success": true,
    "data": {
        "id": 89,
        "serie": "B003",
        "correlativo": "000032",
        "numero_completo": "B003-000032"
    },
    "message": "Boleta creada correctamente"
}
```

---

## Archivos Modificados en PHP

### 1. StoreBoletaRequest.php

```php
'correlativo' => 'nullable|integer|min:1|max:99999999', // NUEVO
```

### 2. StoreInvoiceRequest.php

```php
'correlativo' => 'nullable|integer|min:1|max:99999999', // NUEVO
```

### 3. DocumentService.php

**createBoleta()** y **createInvoice()** ahora:

1. Verifican si viene `correlativo` en el request
2. Si viene: lo usan directamente + sincronizan tabla PHP
3. Si no viene: auto-generan como antes

**Nuevo metodo: syncCorrelativeFromExternal()**

- Sincroniza la tabla `correlatives` de PHP con el valor de Django
- Solo actualiza si el correlativo de Django es mayor al actual
- Previene desincronizacion por retries

---

## Sincronizacion de Correlativos

### Escenario: Retries con el Mismo Correlativo

```
Venta 1: Django asigna B003-32 -> Celery -> PHP crea B003-32
Venta 2: Django asigna B003-33 -> Celery -> FALLA (timeout)
         -> Retry 1: Django envia B003-33 -> PHP crea B003-33
Venta 3: Django asigna B003-34 -> Celery -> PHP crea B003-34
```

**Resultado:** Django y PHP estan sincronizados

### Escenario: Retry de Documento Existente

```
Venta 1: Django asigna B003-32 -> Celery -> PHP crea B003-32
         -> Retry (duplicado): Django envia B003-32 -> PHP ERROR "Ya existe"
         -> Django detecta duplicado -> Marca como enviado
```

**Django debe manejar el error de duplicado:**

```python
if "Ya existe" in str(response.json().get("message", "")):
    logger.warning(f"Documento {venta.numero_completo} ya existe en PHP")
    return
```

---

## Tabla Resumen de Endpoints

| Proposito         | Endpoint                                | Metodo | Acepta correlativo?       |
| ----------------- | --------------------------------------- | ------ | ------------------------- |
| Crear serie       | `/branches/{branch}/correlatives/batch` | POST   | `correlativo_inicial`     |
| Actualizar serie  | `/branches/{branch}/correlatives/{id}`  | PUT    | `correlativo_actual`      |
| **Crear factura** | `/invoices`                             | POST   | **`correlativo` (NUEVO)** |
| **Crear boleta**  | `/boletas`                              | POST   | **`correlativo` (NUEVO)** |
| Listar series     | `/branches/{branch}/correlatives`       | GET    | N/A                       |

---

## Flujo End-to-End Completo

### 1. Usuario crea serie en Django

```python
serie = DocumentSerie.objects.create(
    branch_id=12,
    tipo_documento='03',
    serie='B003',
    current_correlative=0
)
# Sincronizar a PHP
sync_serie_to_php(serie)
```

### 2. Usuario crea venta

```python
# Django atomicamente incrementa correlativo
correlativo = serie.get_next_correlative()  # 32

venta = Venta.objects.create(
    serie='B003',
    correlativo=correlativo,
    numero_completo='B003-000032',
    ...
)

# Disparar task async
enviar_boleta_a_php.delay(venta.id)
```

### 3. Task envia a PHP

```python
@shared_task(bind=True, max_retries=3)
def enviar_boleta_a_php(self, venta_id):
    venta = Venta.objects.select_for_update().get(id=venta_id)

    if venta.php_boleta_id:
        return  # Ya fue enviada

    try:
        response = requests.post(
            f"{FACTURADOR_URL}/api/v1/boletas",
            json={
                "company_id": venta.company_id,
                "branch_id": venta.branch_id,
                "serie": venta.serie,
                "correlativo": venta.correlativo,  # Correlativo de Django
                "fecha_emision": str(venta.fecha_emision),
                "metodo_envio": "individual",
                "client": {...},
                "detalles": [...],
            },
            timeout=30
        )

        data = response.json()

        if data["success"]:
            venta.php_boleta_id = data["data"]["id"]
            venta.save()
        elif "Ya existe" in str(data.get("message", "")):
            # Documento ya creado en retry anterior
            logger.warning(f"Documento ya existe: {venta.numero_completo}")
            return
        else:
            raise Exception(data.get("message", "Error desconocido"))

    except Exception as e:
        raise self.retry(exc=e, countdown=60)
```

### 4. PHP envia a SUNAT

```python
# Despues de crear, enviar a SUNAT
response = requests.post(
    f"{FACTURADOR_URL}/api/v1/boletas/{venta.php_boleta_id}/send-sunat"
)
# SUNAT recibe B003-000032
```

---

## Verificacion de Implementacion

```bash
# Probar que el correlativo se acepta
curl -X POST "$FACTURADOR_URL/api/v1/boletas" \
  -H "Content-Type: application/json" \
  -d '{
    "company_id": 1,
    "branch_id": 1,
    "serie": "B003",
    "correlativo": 999,
    "fecha_emision": "2026-01-25",
    "metodo_envio": "individual",
    "client": {...},
    "detalles": [...]
  }'
```

**Respuesta esperada:**

```json
{
    "success": true,
    "data": {
        "numero_completo": "B003-000999"
    }
}
```

---

## Notas Tecnicas

### syncCorrelativeFromExternal()

Este metodo se ejecuta cada vez que Django envia un correlativo:

1. **Si la serie NO existe en PHP**: La crea con el correlativo de Django
2. **Si la serie existe y el correlativo de Django es MAYOR**: Actualiza PHP
3. **Si el correlativo de Django es MENOR o IGUAL**: No hace nada (previene desincronizacion por retries)

### Logs Generados

Cuando se usa correlativo externo:

```
[INFO] Usando correlativo externo (Django): serie=B003, correlativo=000032, branch_id=12
[INFO] Correlativo sincronizado desde Django: correlativo_anterior=30, correlativo_nuevo=32
```

Cuando se auto-genera:

```
[INFO] Correlativo auto-generado: serie=B003, correlativo=000033, branch_id=12
```
