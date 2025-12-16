<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    /**
     * Check system health
     */
    public function check(): JsonResponse
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'application' => [
                'name' => config('app.name'),
                'env' => config('app.env'),
                'version' => '1.0.0'
            ],
            'checks' => []
        ];

        $overallHealthy = true;

        // 1. Check Database
        try {
            DB::connection()->getPdo();
            $health['checks']['database'] = [
                'status' => 'up',
                'connection' => config('database.default'),
                'message' => 'Database connection successful'
            ];
        } catch (\Exception $e) {
            $overallHealthy = false;
            $health['checks']['database'] = [
                'status' => 'down',
                'error' => 'Cannot connect to database',
                'message' => $e->getMessage()
            ];
        }

        // 2. Check Cache
        try {
            $testKey = 'health_check_' . time();
            Cache::put($testKey, true, 5);
            $cacheWorks = Cache::get($testKey) === true;
            Cache::forget($testKey);

            $health['checks']['cache'] = [
                'status' => $cacheWorks ? 'up' : 'down',
                'driver' => config('cache.default'),
                'message' => $cacheWorks ? 'Cache is working' : 'Cache is not working'
            ];

            if (!$cacheWorks) {
                $overallHealthy = false;
            }
        } catch (\Exception $e) {
            $overallHealthy = false;
            $health['checks']['cache'] = [
                'status' => 'down',
                'error' => $e->getMessage()
            ];
        }

        // 3. Check Queue
        try {
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            $queueStatus = 'up';
            if ($failedJobs > 100) {
                $queueStatus = 'degraded';
            }

            $health['checks']['queue'] = [
                'status' => $queueStatus,
                'driver' => config('queue.default'),
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $failedJobs,
                'message' => $failedJobs > 100 ? 'Too many failed jobs' : 'Queue is operational'
            ];
        } catch (\Exception $e) {
            $health['checks']['queue'] = [
                'status' => 'unknown',
                'message' => 'Could not check queue status'
            ];
        }

        // 4. Check Storage
        try {
            $certPath = storage_path('app/public/certificado/certificado.pem');
            $certExists = file_exists($certPath);
            $storageWritable = is_writable(storage_path('app'));
            $logsWritable = is_writable(storage_path('logs'));

            $storageStatus = ($certExists && $storageWritable && $logsWritable) ? 'up' : 'degraded';

            $health['checks']['storage'] = [
                'status' => $storageStatus,
                'writable' => $storageWritable,
                'logs_writable' => $logsWritable,
                'certificate' => [
                    'exists' => $certExists,
                    'path' => $certExists ? $certPath : 'not found'
                ],
                'disk_space' => [
                    'free' => $this->formatBytes(disk_free_space(storage_path())),
                    'total' => $this->formatBytes(disk_total_space(storage_path()))
                ]
            ];

            if (!$certExists) {
                $health['checks']['storage']['warnings'][] = 'Certificate file not found';
            }
        } catch (\Exception $e) {
            $health['checks']['storage'] = [
                'status' => 'down',
                'error' => $e->getMessage()
            ];
        }

        // 5. Check SUNAT Endpoints
        $health['checks']['sunat'] = $this->checkSunatEndpoints();

        // 6. Check PHP Configuration
        $health['checks']['php'] = [
            'status' => 'up',
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => [
                'openssl' => extension_loaded('openssl'),
                'soap' => extension_loaded('soap'),
                'zip' => extension_loaded('zip'),
                'curl' => extension_loaded('curl')
            ]
        ];

        // Check critical extensions
        $requiredExtensions = ['openssl', 'soap', 'curl'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $overallHealthy = false;
                $health['checks']['php']['status'] = 'degraded';
                $health['checks']['php']['errors'][] = "Missing required extension: {$ext}";
            }
        }

        // 7. Check System Resources
        $health['checks']['system'] = [
            'status' => 'up',
            'load_average' => sys_getloadavg(),
            'memory_usage' => [
                'current' => $this->formatBytes(memory_get_usage(true)),
                'peak' => $this->formatBytes(memory_get_peak_usage(true))
            ]
        ];

        // Overall status
        $health['status'] = $overallHealthy ? 'healthy' : 'unhealthy';

        $statusCode = $health['status'] === 'healthy' ? 200 : 503;

        return response()->json($health, $statusCode);
    }

    /**
     * Simple ping endpoint
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Check SUNAT endpoints availability
     */
    protected function checkSunatEndpoints(): array
    {
        $endpoints = [
            'beta_facturacion' => 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl',
            'beta_guias' => 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService?wsdl',
        ];

        $results = [];
        $allReachable = true;

        foreach ($endpoints as $name => $url) {
            try {
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
                curl_close($ch);

                $isReachable = $httpCode === 200;
                $results[$name] = [
                    'status' => $isReachable ? 'reachable' : 'unreachable',
                    'http_code' => $httpCode,
                    'response_time' => round($totalTime * 1000) . 'ms'
                ];

                if (!$isReachable) {
                    $allReachable = false;
                }
            } catch (\Exception $e) {
                $results[$name] = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
                $allReachable = false;
            }
        }

        return [
            'status' => $allReachable ? 'up' : 'degraded',
            'endpoints' => $results,
            'message' => $allReachable ? 'All SUNAT endpoints are reachable' : 'Some SUNAT endpoints are unreachable'
        ];
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
