<?php

namespace App\Http\Middleware;

use App\Services\GigTuneSiteMaintenanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceGigTuneSiteMaintenance
{
    public function __construct(
        private readonly GigTuneSiteMaintenanceService $maintenance,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->maintenance->isEnabled() || $this->isBypassedPath($request)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('wp-json/*')) {
            return response()->json([
                'ok' => false,
                'error' => 'GigTune is currently undergoing scheduled maintenance.',
            ], 503);
        }

        return response()
            ->view('maintenance.site', [], 503)
            ->header('Retry-After', '3600');
    }

    private function isBypassedPath(Request $request): bool
    {
        $path = '/' . ltrim($request->path(), '/');
        if ($path === '//') {
            $path = '/';
        }

        $prefixes = [
            '/admin',
            '/admin-dashboard',
            '/gts-admin-users',
            '/secret-admin-login-security',
            '/up',
            '/wp-content/',
            '/storage/',
        ];

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        if ($path === '/favicon.ico') {
            return true;
        }

        return false;
    }
}

