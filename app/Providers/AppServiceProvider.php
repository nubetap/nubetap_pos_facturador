<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Console\Commands\CreateDirectoryStructure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\Invoice;
use App\Models\Company;
use App\Models\Client;
use App\Observers\InvoiceObserver;
use App\Observers\CompanyObserver;
use App\Observers\ClientObserver;
use App\Events\DocumentSentToSunat;
use App\Listeners\SendDocumentNotification;
use Illuminate\Support\Facades\Event;
use App\Repositories\InvoiceRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\ClientRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register repositories
        $this->app->singleton(InvoiceRepository::class);
        $this->app->singleton(CompanyRepository::class);
        $this->app->singleton(ClientRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateDirectoryStructure::class,
            ]);
        }

        $this->configureRateLimiting();
        $this->registerObservers();
        $this->registerEventListeners();
    }

    /**
     * Register model observers
     */
    protected function registerObservers(): void
    {
        Invoice::observe(InvoiceObserver::class);
        Company::observe(CompanyObserver::class);
        Client::observe(ClientObserver::class);
    }

    /**
     * Register event listeners
     */
    protected function registerEventListeners(): void
    {
        Event::listen(
            DocumentSentToSunat::class,
            SendDocumentNotification::class
        );
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Rate limit para envíos a SUNAT - Máximo 10 envíos por minuto
        RateLimiter::for('sunat-send', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiados envíos a SUNAT. Límite: 10 por minuto. Por favor espere.',
                        'retry_after' => $headers['Retry-After'] ?? 60
                    ], 429);
                });
        });

        // Rate limit general para API - Máximo 120 peticiones por minuto
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas peticiones. Límite: 120 por minuto.',
                        'retry_after' => $headers['Retry-After'] ?? 60
                    ], 429);
                });
        });

        // Rate limit para consultas CPE - Máximo 30 consultas por minuto
        RateLimiter::for('cpe-consulta', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas consultas CPE. Límite: 30 por minuto.',
                        'retry_after' => $headers['Retry-After'] ?? 60
                    ], 429);
                });
        });

        // Rate limit para autenticación - Máximo 5 intentos por minuto
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiados intentos de autenticación. Espere antes de reintentar.',
                        'retry_after' => $headers['Retry-After'] ?? 60
                    ], 429);
                });
        });
    }
}
