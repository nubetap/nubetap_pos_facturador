<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\BoletaController;
use App\Http\Controllers\Api\DailySummaryController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\DebitNoteController;
use App\Http\Controllers\Api\RetentionController;
use App\Http\Controllers\Api\VoidedDocumentController;
use App\Http\Controllers\Api\DispatchGuideController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\CompanyConfigController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CorrelativeController;
use App\Http\Controllers\Api\GreCredentialsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultaCpeController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\UbigeoController;
use App\Http\Controllers\Api\ConsultaCpeControllerMejorado;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\WebhookController;

// ========================
// RUTAS PÚBLICAS (SIN AUTENTICACIÓN)
// ========================

// Información del sistema
Route::get('/system/info', [AuthController::class, 'systemInfo']);

// Health Check endpoints (sin autenticación para monitoreo externo)
Route::get('/health', [App\Http\Controllers\HealthController::class, 'check']);
Route::get('/ping', [App\Http\Controllers\HealthController::class, 'ping']);

// Setup del sistema
Route::prefix('setup')->group(function () {
    Route::post('/migrate', [SetupController::class, 'migrate']);
    Route::post('/seed', [SetupController::class, 'seed']);
    Route::get('/status', [SetupController::class, 'status']);
});

// Inicialización del sistema
Route::post('/auth/initialize', [AuthController::class, 'initialize']);

// Autenticación
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth');

// ========================
// RUTAS PROTEGIDAS (CON AUTENTICACIÓN)
// ========================
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // ========================
    // AUTENTICACIÓN Y USUARIO
    // ========================
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/create-user', [AuthController::class, 'createUser']);
    
    // Usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========================
    // DASHBOARD Y ESTADÍSTICAS
    // ========================
    Route::prefix('dashboard')->group(function () {
        Route::get('/statistics', [DashboardController::class, 'statistics']);
        Route::get('/monthly-summary', [DashboardController::class, 'monthlySummary']);
        Route::get('/client-statistics', [DashboardController::class, 'clientStatistics']);
        Route::get('/requires-resend', [DashboardController::class, 'requiresResend']);
        Route::get('/expired-certificates', [DashboardController::class, 'expiredCertificates']);
    });

    // ========================
    // WEBHOOKS
    // ========================
    Route::prefix('webhooks')->group(function () {
        Route::get('/', [WebhookController::class, 'index']);
        Route::post('/', [WebhookController::class, 'store']);
        Route::get('/{id}', [WebhookController::class, 'show']);
        Route::put('/{id}', [WebhookController::class, 'update']);
        Route::delete('/{id}', [WebhookController::class, 'destroy']);
        Route::post('/{id}/test', [WebhookController::class, 'test']);
        Route::get('/{id}/statistics', [WebhookController::class, 'statistics']);
        Route::get('/{id}/deliveries', [WebhookController::class, 'deliveries']);
        Route::post('/deliveries/{deliveryId}/retry', [WebhookController::class, 'retryDelivery']);
    });

    // ========================
    // SETUP AVANZADO
    // ========================
    Route::prefix('setup')->group(function () {
        Route::post('/complete', [SetupController::class, 'setup']);
        Route::post('/configure-sunat', [SetupController::class, 'configureSunat']);
    });

    // ========================
    // GESTIÓN DE UBIGEOS
    // ========================
    Route::prefix('ubigeos')->group(function () {
        Route::get('/regiones', [UbigeoController::class, 'getRegiones']);
        Route::get('/provincias', [UbigeoController::class, 'getProvincias']);
        Route::get('/distritos', [UbigeoController::class, 'getDistritos']);
        Route::get('/search', [UbigeoController::class, 'searchUbigeo']);
        Route::get('/{id}', [UbigeoController::class, 'getUbigeoById']);
    });

    // ========================
    // EMPRESAS Y CONFIGURACIONES
    // ========================
    
    // Empresas
    Route::apiResource('companies', CompanyController::class);
    Route::post('/companies/{company}/activate', [CompanyController::class, 'activate']);
    Route::post('/companies/{company}/toggle-production', [CompanyController::class, 'toggleProductionMode']);

    // Configuraciones de empresas
    Route::prefix('companies/{company_id}/config')->group(function () {
        Route::get('/', [CompanyConfigController::class, 'show']);
        Route::get('/{section}', [CompanyConfigController::class, 'getSection']);
        Route::put('/{section}', [CompanyConfigController::class, 'updateSection']);
        Route::get('/validate/services', [CompanyConfigController::class, 'validateServices']);
        Route::post('/reset', [CompanyConfigController::class, 'resetToDefaults']);
        Route::post('/migrate', [CompanyConfigController::class, 'migrateCompany']);
        Route::delete('/cache', [CompanyConfigController::class, 'clearCache']);
    });

    // Configuraciones generales
    Route::prefix('config')->group(function () {
        Route::get('/defaults', [CompanyConfigController::class, 'getDefaults']);
        Route::get('/summary', [CompanyConfigController::class, 'getSummary']);
    });

    // ========================
    // CREDENCIALES GRE
    // ========================
    
    // Credenciales GRE por empresa
    Route::prefix('companies/{company}/gre-credentials')->group(function () {
        Route::get('/', [GreCredentialsController::class, 'show']);
        Route::put('/', [GreCredentialsController::class, 'update']);
        Route::post('/test-connection', [GreCredentialsController::class, 'testConnection']);
        Route::delete('/clear', [GreCredentialsController::class, 'clear']);
        Route::post('/copy', [GreCredentialsController::class, 'copy']);
    });

    // Credenciales GRE - Configuraciones globales
    Route::prefix('gre-credentials')->group(function () {
        Route::get('/defaults/{mode}', [GreCredentialsController::class, 'getDefaults'])
            ->where('mode', 'beta|produccion');
    });

    // ========================
    // SUCURSALES
    // ========================
    Route::apiResource('branches', BranchController::class);
    Route::post('/branches/{branch}/activate', [BranchController::class, 'activate']);
    Route::get('/companies/{company}/branches', [BranchController::class, 'getByCompany']);

    // ========================
    // CLIENTES
    // ========================
    Route::apiResource('clients', ClientController::class);
    Route::post('/clients/{client}/activate', [ClientController::class, 'activate']);
    Route::get('/companies/{company}/clients', [ClientController::class, 'getByCompany']);
    Route::post('/clients/search-by-document', [ClientController::class, 'searchByDocument']);

    // ========================
    // CORRELATIVOS
    // ========================
    Route::get('/branches/{branch}/correlatives', [CorrelativeController::class, 'index']);
    Route::post('/branches/{branch}/correlatives', [CorrelativeController::class, 'store']);
    Route::put('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'update']);
    Route::delete('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'destroy']);
    Route::post('/branches/{branch}/correlatives/batch', [CorrelativeController::class, 'createBatch']);
    Route::post('/branches/{branch}/correlatives/{correlative}/increment', [CorrelativeController::class, 'increment']);
    
    // Catálogos de correlativos
    Route::get('/correlatives/document-types', [CorrelativeController::class, 'getDocumentTypes']);

    // ========================
    // DOCUMENTOS ELECTRÓNICOS SUNAT
    // ========================

    // PDF Formatos
    Route::prefix('pdf')->group(function () {
        Route::get('/formats', [PdfController::class, 'getAvailableFormats']);
    });

    // Facturas
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index'])->name('api.v1.invoices.index');
        Route::post('/', [InvoiceController::class, 'store'])->name('api.v1.invoices.store');
        Route::put('/{id}', [InvoiceController::class, 'update'])->name('api.v1.invoices.update');
        Route::patch('/{id}', [InvoiceController::class, 'update'])->name('api.v1.invoices.patch');
        Route::get('/{id}', [InvoiceController::class, 'show'])->name('api.v1.invoices.show');
        Route::post('/{id}/send-sunat', [InvoiceController::class, 'sendToSunat'])
            ->middleware('throttle:sunat-send')
            ->name('api.v1.invoices.send-sunat');
        Route::post('/{id}/send-sunat-async', [InvoiceController::class, 'sendToSunatAsync'])
            ->middleware('throttle:sunat-send')
            ->name('api.v1.invoices.send-sunat-async');
        Route::get('/{id}/download-xml', [InvoiceController::class, 'downloadXml'])->name('api.v1.invoices.download-xml');
        Route::get('/{id}/download-cdr', [InvoiceController::class, 'downloadCdr'])->name('api.v1.invoices.download-cdr');
        Route::get('/{id}/download-pdf', [InvoiceController::class, 'downloadPdf'])->name('api.v1.invoices.download-pdf');
        Route::post('/{id}/generate-pdf', [InvoiceController::class, 'generatePdf'])->name('api.v1.invoices.generate-pdf');
    });

    // Boletas
    Route::prefix('boletas')->group(function () {
        Route::get('/', [BoletaController::class, 'index']);
        Route::post('/', [BoletaController::class, 'store']);
        Route::get('/{id}', [BoletaController::class, 'show']);
        Route::put('/{id}', [BoletaController::class, 'update']);
        Route::patch('/{id}', [BoletaController::class, 'update']);
        Route::post('/{id}/send-sunat', [BoletaController::class, 'sendToSunat'])
            ->middleware('throttle:sunat-send');
        Route::get('/{id}/download-xml', [BoletaController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [BoletaController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [BoletaController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [BoletaController::class, 'generatePdf']);

        // Funciones de resumen diario desde boletas
        Route::get('/pending-for-summary', [BoletaController::class, 'getBoletsasPendingForSummary']);
        Route::post('/create-daily-summary', [BoletaController::class, 'createDailySummaryFromDate']);
        Route::post('/summary/{id}/send-sunat', [BoletaController::class, 'sendSummaryToSunat']);
        Route::post('/summary/{id}/check-status', [BoletaController::class, 'checkSummaryStatus']);
    });

    // Resúmenes Diarios
    Route::prefix('daily-summaries')->group(function () {
        Route::get('/', [DailySummaryController::class, 'index']);
        Route::post('/', [DailySummaryController::class, 'store']);
        Route::get('/{id}', [DailySummaryController::class, 'show']);
        Route::post('/{id}/send-sunat', [DailySummaryController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [DailySummaryController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [DailySummaryController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DailySummaryController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DailySummaryController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DailySummaryController::class, 'generatePdf']);

        // Funciones de gestión masiva
        Route::get('/pending', [DailySummaryController::class, 'getPendingSummaries']);
        Route::post('/check-all-pending', [DailySummaryController::class, 'checkAllPendingStatus']);
    });

    // Notas de Crédito
    Route::prefix('credit-notes')->group(function () {
        Route::get('/', [CreditNoteController::class, 'index']);
        Route::post('/', [CreditNoteController::class, 'store']);
        Route::get('/{id}', [CreditNoteController::class, 'show']);
        Route::post('/{id}/send-sunat', [CreditNoteController::class, 'sendToSunat'])
            ->middleware('throttle:sunat-send');
        Route::get('/{id}/download-xml', [CreditNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [CreditNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [CreditNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [CreditNoteController::class, 'generatePdf']);

        // Catálogo de motivos
        Route::get('/catalogs/motivos', [CreditNoteController::class, 'getMotivos']);
    });

    // Notas de Débito
    Route::prefix('debit-notes')->group(function () {
        Route::get('/', [DebitNoteController::class, 'index']);
        Route::post('/', [DebitNoteController::class, 'store']);
        Route::get('/{id}', [DebitNoteController::class, 'show']);
        Route::post('/{id}/send-sunat', [DebitNoteController::class, 'sendToSunat'])
            ->middleware('throttle:sunat-send');
        Route::get('/{id}/download-xml', [DebitNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DebitNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DebitNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DebitNoteController::class, 'generatePdf']);

        // Catálogo de motivos
        Route::get('/catalogs/motivos', [DebitNoteController::class, 'getMotivos']);
    });

    // Comprobantes de Retención
    Route::prefix('retentions')->group(function () {
        Route::get('/', [RetentionController::class, 'index']);
        Route::post('/', [RetentionController::class, 'store']);
        Route::get('/{id}', [RetentionController::class, 'show']);
        Route::post('/{id}/send-sunat', [RetentionController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [RetentionController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [RetentionController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [RetentionController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [RetentionController::class, 'generatePdf']);
    });

    // Comunicaciones de Baja
    Route::prefix('voided-documents')->group(function () {
        Route::get('/', [VoidedDocumentController::class, 'index']);
        Route::post('/', [VoidedDocumentController::class, 'store']);
        Route::get('/available-documents', [VoidedDocumentController::class, 'getDocumentsForVoiding']);
        Route::get('/{id}', [VoidedDocumentController::class, 'show']);
        Route::post('/{id}/send-sunat', [VoidedDocumentController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [VoidedDocumentController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [VoidedDocumentController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [VoidedDocumentController::class, 'downloadCdr']);
    });

    // Guías de Remisión
    Route::prefix('dispatch-guides')->group(function () {
        Route::get('/', [DispatchGuideController::class, 'index']);
        Route::post('/', [DispatchGuideController::class, 'store']);
        Route::get('/{id}', [DispatchGuideController::class, 'show']);
        Route::post('/{id}/send-sunat', [DispatchGuideController::class, 'sendToSunat']);
        Route::post('/{id}/check-status', [DispatchGuideController::class, 'checkStatus']);
        Route::get('/{id}/download-xml', [DispatchGuideController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DispatchGuideController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DispatchGuideController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DispatchGuideController::class, 'generatePdf']);

        // Catálogos
        Route::get('/catalogs/transfer-reasons', [DispatchGuideController::class, 'getTransferReasons']);
        Route::get('/catalogs/transport-modes', [DispatchGuideController::class, 'getTransportModes']);
    });

    // ========================
    // CONSULTA DE COMPROBANTES ELECTRÓNICOS (CPE)
    // ========================
    Route::prefix('consulta-cpe')->middleware('throttle:cpe-consulta')->group(function () {
        // Consultas individuales por tipo de documento
        Route::post('/factura/{id}', [ConsultaCpeController::class, 'consultarFactura']);
        Route::post('/boleta/{id}', [ConsultaCpeController::class, 'consultarBoleta']);
        Route::post('/nota-credito/{id}', [ConsultaCpeController::class, 'consultarNotaCredito']);
        Route::post('/nota-debito/{id}', [ConsultaCpeController::class, 'consultarNotaDebito']);
        
        // Consulta masiva
        Route::post('/masivo', [ConsultaCpeController::class, 'consultarDocumentosMasivo']);
        
        // Estadísticas de consultas
        Route::get('/estadisticas', [ConsultaCpeController::class, 'estadisticasConsultas']);
    });
});

// ========================
// RUTAS ADICIONALES - CONSULTA CPE MEJORADA
// ========================
require __DIR__.'/api_consulta_mejorada.php';
