<?php

namespace App\Http\Middleware;

use App\Services\WordPressUserService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureGigTuneAuthenticated
{
    public function __construct(
        private readonly WordPressUserService $users,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $userId = (int) $request->session()->get('gigtune_auth_user_id', 0);
        if ($userId <= 0) {
            return $this->unauthorizedResponse($request);
        }

        $user = $this->users->getUserById($userId);
        if ($user === null) {
            $request->session()->forget([
                'gigtune_auth_user_id',
                'gigtune_auth_logged_in_at',
                'gigtune_auth_remember',
            ]);

            return $this->unauthorizedResponse($request);
        }

        $request->attributes->set('gigtune_user', $user);
        return $next($request);
    }

    private function unauthorizedResponse(Request $request): JsonResponse|RedirectResponse
    {
        if (!$request->expectsJson() && !$request->is('wp-json/*')) {
            return redirect('/secret-admin-login-security');
        }

        return response()->json([
            'ok' => false,
            'error' => 'Authentication required.',
        ], 401);
    }
}
