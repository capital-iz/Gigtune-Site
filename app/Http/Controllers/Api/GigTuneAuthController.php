<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WordPressUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GigTuneAuthController extends Controller
{
    public function __construct(
        private readonly WordPressUserService $users,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $payload = $this->requestPayload($request);
        $identifier = trim((string) ($payload['identifier'] ?? $payload['username'] ?? $payload['email'] ?? $payload['log'] ?? ''));
        $password = (string) ($payload['password'] ?? $payload['pwd'] ?? '');
        $remember = $this->toBool($payload['remember'] ?? $payload['rememberme'] ?? false);

        if ($identifier === '' || $password === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Identifier and password are required.',
            ], 422);
        }

        $user = $this->users->verifyCredentials($identifier, $password);
        if ($user === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Username or password is incorrect.',
            ], 401);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('gigtune_auth_user_id', (int) $user['id']);
        $request->session()->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $request->session()->put('gigtune_auth_remember', $remember);

        $policy = $this->users->getPolicyStatus((int) $user['id']);
        $redirectTo = (bool) $user['is_admin'] || (bool) $policy['has_latest']
            ? (string) $user['dashboard_url']
            : (string) $policy['consent_url'];

        return response()->json([
            'ok' => true,
            'user' => $user,
            'policy' => $policy,
            'redirect_to' => $redirectTo,
        ], 200);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->attributes->get('gigtune_user');
        if (!is_array($user)) {
            return response()->json([
                'ok' => false,
                'error' => 'Authentication required.',
            ], 401);
        }

        return response()->json([
            'ok' => true,
            'user' => $user,
            'policy' => $this->users->getPolicyStatus((int) $user['id']),
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->forget([
            'gigtune_auth_user_id',
            'gigtune_auth_logged_in_at',
            'gigtune_auth_remember',
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'ok' => true,
            'message' => 'Logged out.',
        ], 200);
    }

    private function requestPayload(Request $request): array
    {
        $payload = $request->all();
        if (!empty($payload)) {
            return is_array($payload) ? $payload : [];
        }

        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
