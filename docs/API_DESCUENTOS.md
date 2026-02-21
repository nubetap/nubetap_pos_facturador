# API de Descuentos - Facturador Nubetap

Documentación para el equipo de Django sobre cómo enviar descuentos al facturador.
El facturador **ya soporta descuentos** tanto a nivel de ítem como a nivel global (documento).
Django solo necesita incluir los campos correspondientes en el payload que ya envía.

---

## Resumen Rápido

| Tipo | Campo en JSON | Dónde va | Obligatorio |
|------|--------------|----------|-------------|
| Descuento por ítem | `detalles[].descuentos[]` | Dentro de cada detalle | No |
| Descuento global | `descuentos[]` | Raíz del JSON | No |

**Todos los campos de descuento son opcionales.** Si no se envían, el facturador funciona exactamente igual que ahora.

---

## 1. Descuento por Ítem (línea de detalle)

Se agrega un array `descuentos` dentro de cada objeto del array `detalles`.

### Estructura

```json
{
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Producto ejemplo",
      "unidad": "NIU",
      "cantidad": 2,
      "mto_valor_unitario": 100.00,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10",
      "descuentos": [
        {
          "cod_tipo": "00",
          "monto_base": 200.00,
          "factor": 0.10,
          "monto": 20.00
        }
      ]
    }
  ]
}
```

### Campos del descuento por ítem

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `cod_tipo` | string | No (default: `"00"`) | Código SUNAT Catálogo 53. Ver tabla abajo |
| `monto_base` | float | Sí | Monto base sobre el que se calcula el descuento (cantidad × valor_unitario) |
| `factor` | float | Sí | Factor/porcentaje del descuento en decimal (10% = `0.10`) |
| `monto` | float | Sí | Monto final del descuento en soles = `monto_base × factor` |

### Códigos de tipo para descuento por ítem (Catálogo 53 SUNAT)

| cod_tipo | Nombre | Efecto |
|----------|--------|--------|
| `"00"` | Descuento que afecta la base imponible | Reduce la base para cálculo de IGV |
| `"01"` | Descuento que NO afecta la base imponible | Reduce el total pero NO cambia la base del IGV |

### Cómo calcula el facturador

```
valor_venta_original = cantidad × mto_valor_unitario
valor_venta_final    = valor_venta_original - monto_descuento

Si cod_tipo = "00": base_igv = valor_venta_final
Si cod_tipo = "01": base_igv = valor_venta_original  (el IGV se calcula sobre el monto SIN descontar)
```

### Ejemplo completo: Producto con 10% de descuento

```json
{
  "codigo": "ARROZ001",
  "descripcion": "Arroz Extra 5kg",
  "unidad": "NIU",
  "cantidad": 3,
  "mto_valor_unitario": 16.95,
  "porcentaje_igv": 18,
  "tip_afe_igv": "10",
  "descuentos": [
    {
      "cod_tipo": "00",
      "monto_base": 50.85,
      "factor": 0.10,
      "monto": 5.09
    }
  ]
}
```

**Cálculo:**
- Valor venta original: 3 × 16.95 = 50.85
- Descuento: 50.85 × 0.10 = 5.09 (redondeado a 2 decimales)
- Valor venta final: 50.85 - 5.09 = 45.76
- IGV: 45.76 × 0.18 = 8.24
- Precio con IGV: 45.76 + 8.24 = 54.00

---

## 2. Descuento Global (a nivel de documento)

Se agrega un array `descuentos` en la **raíz** del JSON (al mismo nivel que `detalles`, `client`, etc).

### Estructura

```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "F001",
  "fecha_emision": "2026-02-18",
  "moneda": "PEN",
  "forma_pago_tipo": "Contado",
  "client": { ... },
  "detalles": [ ... ],
  "descuentos": [
    {
      "cod_tipo": "02",
      "monto_base": 500.00,
      "factor": 0.05,
      "monto": 25.00
    }
  ]
}
```

### Campos del descuento global

| Campo | Tipo | Requerido | Valores válidos | Descripción |
|-------|------|-----------|-----------------|-------------|
| `cod_tipo` | string | Sí | `"00"`,`"01"`,`"02"`,`"03"`,`"04"` | Código SUNAT Catálogo 53 |
| `monto_base` | float | Sí | > 0 | Monto base del descuento (suma de operaciones gravadas, normalmente) |
| `factor` | float | Sí | 0 a 1 | Factor decimal del descuento (5% = `0.05`) |
| `monto` | float | Sí | > 0 | Monto calculado del descuento = `monto_base × factor` |

### Códigos de tipo para descuento global (Catálogo 53 SUNAT)

| cod_tipo | Nombre | Efecto en totales |
|----------|--------|-------------------|
| `"02"` | Descuento global que afecta la base | Reduce `mto_oper_gravadas` y `valor_venta`. Recalcula IGV |
| `"03"` | Descuento global que NO afecta la base | Reduce `mto_imp_venta` (total final) pero NO toca la base del IGV |
| `"04"` | Descuento por anticipo | Reduce `mto_oper_gravadas` y recalcula IGV (uso especial con anticipos) |

### Cuándo usar cada código

- **`"02"` - Descuento comercial estándar:** "5% de descuento en toda la compra". Reduce la base imponible.
- **`"03"` - Descuento que no cambia impuestos:** Bonificación, cortesía, descuento financiero. El IGV se cobra sobre el monto original.

---

## 3. Validación en el Facturador

### Para Factura (POST /api/invoices)

**Descuentos por ítem:**
```
detalles.*.descuentos           → nullable|array
detalles.*.descuentos.*.monto   → required_with:descuentos|numeric|min:0
```

**Descuentos globales:**
```
descuentos                      → nullable|array
descuentos.*.cod_tipo           → required_with:descuentos|string|in:00,01,02,03,04
descuentos.*.factor             → required_with:descuentos|numeric|min:0
descuentos.*.monto              → required_with:descuentos|numeric|min:0
descuentos.*.monto_base         → required_with:descuentos|numeric|min:0
```

### Para Boleta (POST /api/boletas)

**Descuentos por ítem:** Misma estructura que factura.

**Descuentos globales:** Misma estructura que factura.
```
descuentos                      → nullable|array
descuentos.*.cod_tipo           → required_with:descuentos|string|in:00,01,02,03,04
descuentos.*.factor             → required_with:descuentos|numeric|min:0
descuentos.*.monto              → required_with:descuentos|numeric|min:0
descuentos.*.monto_base         → required_with:descuentos|numeric|min:0
```

---

## 4. Ejemplo Completo: Factura con Ambos Tipos de Descuento

```json
{
  "company_id": 1,
  "branch_id": 1,
  "serie": "F001",
  "correlativo": 150,
  "fecha_emision": "2026-02-18",
  "moneda": "PEN",
  "tipo_operacion": "0101",
  "forma_pago_tipo": "Contado",
  "client": {
    "tipo_documento": "6",
    "numero_documento": "20100130204",
    "razon_social": "EMPRESA EJEMPLO S.A.C.",
    "direccion": "AV. EJEMPLO 123"
  },
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Laptop HP 15",
      "unidad": "NIU",
      "cantidad": 1,
      "mto_valor_unitario": 2500.00,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10",
      "descuentos": [
        {
          "cod_tipo": "00",
          "monto_base": 2500.00,
          "factor": 0.10,
          "monto": 250.00
        }
      ]
    },
    {
      "codigo": "PROD002",
      "descripcion": "Mouse Logitech",
      "unidad": "NIU",
      "cantidad": 2,
      "mto_valor_unitario": 50.00,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10"
    }
  ],
  "descuentos": [
    {
      "cod_tipo": "02",
      "monto_base": 2350.00,
      "factor": 0.05,
      "monto": 117.50
    }
  ],
  "total_esperado": 2515.15
}
```

**Desglose del cálculo:**

```
ITEM 1 - Laptop:
  Valor bruto:     1 × 2500.00          = 2,500.00
  Descuento ítem:  2500 × 10%           =  -250.00
  Valor venta:                           = 2,250.00
  IGV:             2250 × 18%            =   405.00

ITEM 2 - Mouse:
  Valor bruto:     2 × 50.00            =   100.00
  Descuento ítem:  (ninguno)             =     0.00
  Valor venta:                           =   100.00
  IGV:             100 × 18%             =    18.00

SUBTOTALES (antes de descuento global):
  Oper. Gravadas:  2250 + 100            = 2,350.00
  IGV total:       405 + 18              =   423.00

DESCUENTO GLOBAL (cod_tipo "02", afecta base):
  Descuento:       2350 × 5%             =  -117.50
  Oper. Gravadas:  2350 - 117.50         = 2,232.50
  Valor venta:     2350 - 117.50         = 2,232.50
  (IGV se recalcula si hay anticipo, sino se mantiene de detalles)

TOTALES FINALES:
  Valor venta:     2,232.50
  IGV:               423.00
  Sub total:       2,655.50
  Imp. venta:      2,655.50
```

---

## 5. Ejemplo Mínimo: Solo Descuento por Ítem

Si Django solo necesita descuentos a nivel de producto (caso más común en POS):

```json
{
  "detalles": [
    {
      "codigo": "PROD001",
      "descripcion": "Producto con descuento",
      "unidad": "NIU",
      "cantidad": 5,
      "mto_valor_unitario": 84.75,
      "porcentaje_igv": 18,
      "tip_afe_igv": "10",
      "descuentos": [
        {
          "cod_tipo": "00",
          "monto_base": 423.75,
          "factor": 0.15,
          "monto": 63.56
        }
      ]
    }
  ]
}
```

---

## 6. Ejemplo Mínimo: Solo Descuento Global

Si Django solo necesita descuento al total de la venta:

```json
{
  "descuentos": [
    {
      "cod_tipo": "02",
      "monto_base": 1000.00,
      "factor": 0.10,
      "monto": 100.00
    }
  ]
}
```

Esto va en la raíz del JSON, junto con los otros campos normales (`company_id`, `client`, `detalles`, etc).

---

## 7. Notas Técnicas para Django

### Qué calcula Django vs qué calcula el Facturador

| Responsabilidad | Quién |
|----------------|-------|
| Determinar si hay descuento y cuánto | **Django** |
| Calcular `monto_base`, `factor`, `monto` | **Django** |
| Calcular IGV, valor_venta, totales | **Facturador** (automático) |
| Generar XML SUNAT con descuentos | **Facturador** (automático) |
| Almacenar `descuento_global` en BD | **Facturador** (automático) |

### Fórmula que Django debe aplicar para construir el descuento

```python
# Descuento por ítem
monto_base = cantidad * valor_unitario_sin_igv
factor = porcentaje_descuento / 100  # ej: 10% → 0.10
monto = round(monto_base * factor, 2)

# Descuento global
monto_base = sum(valor_venta de todos los ítems gravados)  # después de descuentos por ítem
factor = porcentaje_descuento_global / 100
monto = round(monto_base * factor, 2)
```

### Importante: Valores SIN IGV

El `mto_valor_unitario` que Django envía es el precio **sin IGV**. Los descuentos se calculan sobre valores sin IGV. El facturador se encarga de calcular el IGV sobre el monto ya descontado.

```python
# Si el POS maneja precios CON IGV:
precio_con_igv = 118.00
valor_unitario_sin_igv = round(precio_con_igv / 1.18, 2)  # = 100.00
# Enviar mto_valor_unitario = 100.00
```

### Múltiples descuentos por ítem

Se pueden enviar múltiples descuentos por línea (ej: descuento comercial + descuento promocional):

```json
"descuentos": [
  {
    "cod_tipo": "00",
    "monto_base": 500.00,
    "factor": 0.10,
    "monto": 50.00
  },
  {
    "cod_tipo": "01",
    "monto_base": 500.00,
    "factor": 0.05,
    "monto": 25.00
  }
]
```

### Compatibilidad

- Si `descuentos` no se envía (o es `null`/`[]`), todo funciona como antes. **No hay breaking changes.**
- Los campos son `nullable`, Django puede implementar gradualmente.

---

## 8. Notas Adicionales sobre Boletas

Tanto `StoreBoletaRequest` como `UpdateBoletaRequest` soportan descuentos por ítem y descuentos globales con la misma estructura que facturas. Django puede enviar descuentos a ambos endpoints sin diferencia.

---

## 9. Nota sobre Notas de Crédito por Descuento

Si se necesita aplicar un descuento **después** de emitir un documento, se debe usar una Nota de Crédito con:
- Motivo código `04`: Descuento global
- Motivo código `05`: Descuento por ítem

Esto ya está soportado en el endpoint de notas de crédito del facturador.
