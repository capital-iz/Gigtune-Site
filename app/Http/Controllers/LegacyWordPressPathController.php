<?php

namespace App\Http\Controllers;

use App\Services\WordPressNotificationService;
use App\Services\WordPressUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyWordPressPathController extends Controller
{
    public function __construct(
        private readonly WordPressUserService $users,
        private readonly WordPressNotificationService $notifications,
    ) {
    }

    public function wpAdmin(): RedirectResponse
    {
        return redirect('/secret-admin-login-security', 302);
    }

    public function adminAjax(Request $request): JsonResponse
    {
        $action = trim(strtolower((string) $request->input('action', '')));
        if ($action === 'gigtune_unread_notification_count') {
            $userId = (int) $request->session()->get('gigtune_auth_user_id', 0);
            if ($userId <= 0 || !is_array($this->users->getUserById($userId))) {
                return response()->json(['count' => 0]);
            }

            $count = $this->notifications->unreadCount($userId);
            return response()->json(['count' => (int) $count]);
        }

        if ($action === 'gigtune_cookie_consent') {
            return response()->json(['success' => true, 'data' => ['ok' => true]]);
        }

        return response()->json(['success' => false, 'data' => ['message' => 'Unsupported action.']], 400);
    }

    public function wpLogin(Request $request): RedirectResponse
    {
        $action = trim(strtolower((string) $request->query('action', '')));

        if ($action === 'logout') {
            $request->session()->forget([
                'gigtune_auth_user_id',
                'gigtune_auth_logged_in_at',
                'gigtune_auth_remember',
            ]);
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $redirectTo = trim((string) $request->query('redirect_to', '/'));
            if ($redirectTo === '' || !str_starts_with($redirectTo, '/') || str_starts_with($redirectTo, '//')) {
                $redirectTo = '/';
            }
            return redirect($redirectTo, 302);
        }

        if ($action === 'lostpassword') {
            return redirect('/forgot-password/', 302);
        }

        if (strtoupper($request->method()) === 'POST') {
            $identifier = trim((string) $request->input('log', ''));
            $password = (string) $request->input('pwd', '');
            $user = $this->users->verifyCredentials($identifier, $password);

            if (!is_array($user)) {
                return redirect('/sign-in/?login=failed', 302);
            }

            $request->session()->put('gigtune_auth_user_id', (int) ($user['id'] ?? 0));
            $request->session()->put('gigtune_auth_logged_in_at', now()->toIso8601String());
            $request->session()->put('gigtune_auth_remember', trim((string) $request->input('rememberme', '')) !== '');

            $redirectTo = trim((string) $request->input('redirect_to', ''));
            if ($redirectTo === '' || str_contains($redirectTo, '/sign-in') || str_contains($redirectTo, '/login')) {
                $redirectTo = (string) ($user['dashboard_url'] ?? '/');
            }

            return redirect($redirectTo, 302);
        }

        return redirect('/sign-in/', 302);
    }
}
