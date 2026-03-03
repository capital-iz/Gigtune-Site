<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGigTuneAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user) || !((bool) ($user['is_admin'] ?? false))) {
            if ($request->expectsJson()) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'Administrator access required.',
                ], 403);
            }

            return redirect('/secret-admin-login-security');
        }

        return $next($request);
    }
}
