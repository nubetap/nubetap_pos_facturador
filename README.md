<p align="center">
  <img src="./public/assets/images/sunat.png" alt="SUNAT Logo" width="250">
</p>

# API de Facturación Electrónica SUNAT - Perú

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
  <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/Greenter-5.1-4CAF50?style=for-the-badge" alt="Greenter 5.1">
  <img src="https://img.shields.io/badge/SUNAT-Compatible-0066CC?style=for-the-badge" alt="SUNAT Compatible">
</p>

Sistema completo de facturación electrónica para SUNAT Perú desarrollado con **Laravel 12** y la librería **Greenter 5.1**. Este proyecto implementa todas las funcionalidades necesarias para la generación, envío y gestión de comprobantes de pago electrónicos según las normativas de SUNAT.

## 🚀 Características Principales

### Documentos Electrónicos Soportados
- ✅ **Facturas** (Tipo 01)
- ✅ **Boletas de Venta** (Tipo 03) 
- ✅ **Notas de Crédito** (Tipo 07)
- ✅ **Notas de Débito** (Tipo 08)
- ✅ **Guías de Remisión** (Tipo 09)
- ✅ **Resúmenes Diarios** (RC)
- ✅ **Comunicaciones de Baja** (RA)
- ✅ **Retenciones y Percepciones**

### Funcionalidades del Sistema
- 🏢 **Multi-empresa**: Gestión de múltiples empresas y sucursales
- 🔐 **Autenticación OAuth2** para APIs de SUNAT
- 📄 **Generación automática de PDF** con diseño profesional
- 📊 **Consulta de CPE** (Comprobantes de Pago Electrónicos)
- 💰 **Cálculo automático de impuestos** (IGV, IVAP, ISC, ICBPER)
- 📱 **API REST completa** con documentación
- 🔄 **Sincronización con SUNAT** en tiempo real
- 📈 **Reportes y estadísticas** de facturación

### Tecnologías Utilizadas
- **Framework**: Laravel 12 con PHP 8.2+
- **SUNAT Integration**: Greenter 5.1
- **Base de Datos**: MySQL/PostgreSQL compatible
- **PDF Generation**: DomPDF con plantillas personalizadas
- **QR Codes**: Endroid QR Code
- **Authentication**: Laravel Sanctum
- **Testing**: PestPHP

## 🛠️ Instalación

### Requisitos Previos
- PHP 8.2 o superior
- Composer
- MySQL 8.0+ o PostgreSQL
- Certificado digital SUNAT (.pfx)

### Pasos de Instalación

1. **Clonar el repositorio**
```bash
git clone [repository-url]
cd api-facturacion-sunat-v0
```

2. **Instalar dependencias**
```bash
composer install
```

3. **Configurar variables de entorno**
```bash
cp .env.example .env
php artisan key:generate
```

4. **Configurar base de datos en .env**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=facturacion_sunat
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password
```

5. **Ejecutar migraciones**
```bash
php artisan migrate
```

6. **Configurar certificados SUNAT**
- Colocar certificado .pfx en `storage/certificates/`
- Configurar rutas en el archivo .env

## 🏗️ Arquitectura del Sistema

### Estructura de Modelos
- **Company**: Empresas emisoras
- **Branch**: Sucursales por empresa
- **Client**: Clientes y proveedores
- **Invoice/Boleta/CreditNote/DebitNote**: Documentos electrónicos
- **DailySummary**: Resúmenes diarios de boletas
- **CompanyConfiguration**: Configuraciones por empresa

### Servicios Principales
- **DocumentService**: Lógica de negocio para documentos
- **SunatService**: Integración con APIs de SUNAT  
- **PdfService**: Generación de documentos PDF
- **FileService**: Gestión de archivos XML/PDF
- **TaxCalculationService**: Cálculo de impuestos
- **SeriesService**: Gestión de series documentarias

## 📚 Documentación de la API

### 📖 Documentación Oficial
**Documentación completa y actualizada disponible en:**
👉 **[https://apigo.apuuraydev.com/](https://apigo.apuuraydev.com/)**

Esta documentación oficial incluye:
- Guías detalladas de uso
- Ejemplos de implementación
- Referencia completa de endpoints
- Códigos de respuesta y errores
- Casos de uso prácticos

### Endpoints Principales

#### Facturas
```http
GET    /api/invoices              # Listar facturas
POST   /api/invoices              # Crear factura
GET    /api/invoices/{id}         # Obtener factura
POST   /api/invoices/{id}/send    # Enviar a SUNAT
```

#### Boletas
```http
GET    /api/boletas               # Listar boletas
POST   /api/boletas               # Crear boleta  
POST   /api/boletas/summary       # Crear resumen diario
```

#### Consultas
```http
GET    /api/cpe/consult/{ruc}/{type}/{serie}/{number}  # Consultar CPE
```

### Ejemplo de Creación de Factura
```json
{
  "company_id": 1,
  "branch_id": 1,
  "client_id": 1,
  "serie": "F001",
  "correlativo": 1,
  "fecha_emision": "2024-01-15",
  "moneda": "PEN",
  "tipo_operacion": "0101",
  "items": [
    {
      "codigo": "PROD001",
      "descripcion": "Producto ejemplo",
      "cantidad": 2,
      "precio_unitario": 100.00,
      "tipo_afectacion_igv": "10"
    }
  ]
}
```

## 📋 Comandos Artisan Disponibles

```bash
# Generar certificados de prueba
php artisan sunat:generate-certificates

# Sincronizar estados con SUNAT  
php artisan sunat:sync-status

# Generar resúmenes diarios pendientes
php artisan sunat:daily-summaries

# Limpiar archivos temporales
php artisan sunat:clean-files
```

## 🧪 Testing

```bash
# Ejecutar todas las pruebas
php artisan test

# Ejecutar pruebas específicas
php artisan test --filter=InvoiceTest
```

## 📖 Documentación Técnica

Para análisis técnico detallado, consultar el archivo `VERIFICAR_MA.md` que contiene:
- Arquitectura completa del sistema
- Análisis de código y patrones utilizados
- Diagramas de flujo de procesos
- Evaluación de calidad empresarial
- Recomendaciones de optimización

## ⚖️ Licencia y Uso

**Este proyecto es de uso libre bajo las siguientes condiciones:**

- ✅ Puedes usar, modificar y distribuir el código libremente
- ✅ Puedes usarlo para proyectos comerciales y personales
- ⚠️ **Todo el uso es bajo tu propia responsabilidad**
- ⚠️ No se ofrece garantía ni soporte oficial
- ⚠️ Debes cumplir con las normativas de SUNAT de tu país

### Importante
- Asegúrate de tener los certificados digitales válidos de SUNAT
- Configura correctamente los endpoints según tu ambiente (beta/producción)
- Realiza pruebas exhaustivas antes de usar en producción
- Mantén actualizadas las librerías de seguridad

## 🤝 Soporte y Donaciones

Si este proyecto te ha sido útil y deseas apoyar su desarrollo:

### 💰 Yape (Perú)
<p align="center">
  <img src="./public/assets/images/yape.png" alt="Yape" width="100">
</p>

**Número:** `920468502`

### 💬 WhatsApp
**Contacto:** [https://wa.link/z50dwk](https://wa.link/z50dwk)

### 📧 Contribuciones
- Fork el proyecto
- Crea una rama para tu feature
- Envía un pull request

---

## 📞 Contacto

Para consultas técnicas o colaboraciones:
- **WhatsApp**: [https://wa.link/z50dwk](https://wa.link/z50dwk)
- **Yape**: 920468502

---

**⚡ Desarrollado con Laravel 12 y Greenter 5.1 para la comunidad peruana**

*"Facilitando la facturación electrónica en Perú - Un documento a la vez"*