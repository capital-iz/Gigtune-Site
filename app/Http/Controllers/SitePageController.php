<?php

namespace App\Http\Controllers;

use App\Services\GigTuneShortcodeService;
use App\Services\WordPressNotificationService;
use App\Services\WordPressUserService;
use App\Support\WpThemeRuntime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SitePageController extends Controller
{
    public function __construct(
        private readonly WordPressUserService $users,
        private readonly GigTuneShortcodeService $shortcodes,
        private readonly WordPressNotificationService $notifications,
    ) {
    }

    public function home(Request $request): Response|RedirectResponse
    {
        return $this->renderPath($request, '');
    }

    public function page(Request $request, ?string $path = null): Response|RedirectResponse
    {
        return $this->renderPath($request, (string) $path);
    }

    private function renderPath(Request $request, string $path): Response|RedirectResponse
    {
        $normalizedPath = trim($path, '/');
        $slug = $normalizedPath === '' ? '' : basename($normalizedPath);
        $currentUser = $this->resolveCurrentUser($request);

        if (
            $normalizedPath === ''
            && strtoupper((string) $request->method()) === 'GET'
            && is_array($currentUser)
            && $this->isAdminLikeUser($currentUser)
        ) {
            $request->attributes->set('gigtune_user', $currentUser);
            $adminDashboard = app(AdminPortalController::class)->dashboard($request);
            return response($adminDashboard->render(), 200)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        if (
            $normalizedPath === ''
            && strtoupper((string) $request->method()) === 'GET'
            && is_array($currentUser)
        ) {
            $homeDashboardSlug = $this->homeDashboardSlugForUser($currentUser);
            if ($homeDashboardSlug !== '') {
                $dashboardPage = $this->loadPageBySlug($homeDashboardSlug);
                if (!is_array($dashboardPage)) {
                    $dashboardPage = $this->loadVirtualTemplatePage($homeDashboardSlug);
                }
                if (is_array($dashboardPage)) {
                    $template = $this->resolveTemplate($dashboardPage);
                    return $this->renderTemplate($request, $currentUser, $dashboardPage, $template, 200);
                }
            }
        }

        if ($slug === 'logout' || $slug === 'sign-out') {
            $this->logoutSession($request);
            $redirectTo = $this->safeRedirectPath((string) $request->query('redirect_to', '/'), '/');
            return redirect($redirectTo, 302);
        }

        if (in_array($slug, ['join', 'login', 'sign-in'], true) && is_array($currentUser)) {
            return redirect((string) ($currentUser['dashboard_url'] ?? '/'), 302);
        }

        if (in_array($slug, ['login', 'sign-in'], true) && strtoupper($request->method()) === 'POST') {
            return $this->handleSignIn($request);
        }

        if ($slug === 'join' && strtoupper($request->method()) === 'POST') {
            $result = $this->handleJoin($request);
            if ($result instanceof RedirectResponse) {
                return $result;
            }
            $currentUser = $this->resolveCurrentUser($request);
        }

        $notificationRedirect = $this->handleNotificationPost($request, $slug, $currentUser);
        if ($notificationRedirect instanceof RedirectResponse) {
            return $notificationRedirect;
        }

        $page = $normalizedPath === '' ? $this->loadFrontPage() : $this->loadPageBySlug($slug);
        if (!is_array($page) && $slug !== '') {
            $page = $this->loadVirtualTemplatePage($slug);
        }
        if (!is_array($page)) {
            return $this->renderTemplate($request, $currentUser, null, '404.php', 404);
        }

        $template = $this->resolveTemplate($page);
        return $this->renderTemplate($request, $currentUser, $page, $template, 200);
    }

    private function renderTemplate(Request $request, ?array $currentUser, ?array $page, string $template, int $status): Response
    {
        $themeRoot = resource_path('wp-theme/gigtune-canon');
        $templatePath = $themeRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $template);
        if (!is_file($templatePath)) {
            $templatePath = $themeRoot . DIRECTORY_SEPARATOR . 'page.php';
            if ($status === 404) {
                $templatePath = $themeRoot . DIRECTORY_SEPARATOR . '404.php';
            }
        }

        require_once app_path('Support/wp_theme_compat.php');
        WpThemeRuntime::init($request, $currentUser, $page, $this->shortcodes, $themeRoot, $status);

        $themeFunctions = $themeRoot . DIRECTORY_SEPARATOR . 'functions.php';
        if (is_file($themeFunctions)) {
            require_once $themeFunctions;
            if (function_exists('do_action')) {
                do_action('after_setup_theme');
            }
        }

        try {
            $html = WpThemeRuntime::renderTemplate($templatePath);
        } catch (\Throwable $throwable) {
            $html = '<!doctype html><html><head><meta charset="utf-8"><title>GigTune</title></head><body style="font-family:Arial;background:#020617;color:#e2e8f0;padding:24px;">'
                . '<h1>Template Rendering Error</h1><p>' . e($throwable->getMessage()) . '</p></body></html>';
            $status = 500;
        }

        return response($html, $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function handleNotificationPost(Request $request, string $slug, ?array $currentUser): ?RedirectResponse
    {
        if (!is_array($currentUser) || strtoupper((string) $request->method()) !== 'POST') {
            return null;
        }

        $userId = (int) ($currentUser['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        $isAdmin = (bool) ($currentUser['is_admin'] ?? false);

        if ($slug === 'notifications' && (string) $request->input('gigtune_mark_notifications_read', '') === '1') {
            $nonce = (string) $request->input('gigtune_notifications_nonce', '');
            if ($this->verifyWpNonce($nonce, 'gigtune_mark_notifications_read')) {
                $this->notifications->markAllRead($userId, $userId, $isAdmin);
            }
            $redirect = $this->safeLocalRedirect((string) $request->input('gigtune_notifications_redirect', ''), '/notifications/');
            return redirect($redirect, 302);
        }

        if ($slug === 'notifications-archive' && (string) $request->input('gigtune_restore_notification_submit', '') === '1') {
            $nonce = (string) $request->input('gigtune_restore_notification_nonce', '');
            $notificationId = abs((int) $request->input('gigtune_restore_notification_id', 0));
            if ($notificationId > 0 && $this->verifyWpNonce($nonce, 'gigtune_restore_notification')) {
                $this->notifications->restore($notificationId, $userId, $isAdmin);
            }
            $redirect = $this->safeLocalRedirect((string) $request->input('gigtune_notifications_redirect', ''), '/notifications-archive/');
            return redirect($redirect, 302);
        }

        if ($slug === 'notification-settings' && (string) $request->input('gigtune_notification_settings_submit', '') === '1') {
            if ((string) $request->input('gigtune_notification_settings_cancel', '') === '1') {
                return redirect('/notifications/', 302);
            }

            $nonce = (string) $request->input('gigtune_notification_settings_nonce', '');
            if (!$this->verifyWpNonce($nonce, 'gigtune_notification_settings_action')) {
                return redirect('/notification-settings/?notification_settings_error=1', 302);
            }

            $categories = ['booking', 'psa', 'message', 'payment', 'dispute', 'security'];
            $preferences = [];
            foreach ($categories as $category) {
                $field = 'gigtune_notify_' . $category;
                $preferences[$category] = (string) $request->input($field, '') === '1';
            }
            $this->shortcodes->saveNotificationEmailPreferences($userId, $preferences);
            return redirect('/notification-settings/?notification_settings_saved=1', 302);
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveCurrentUser(Request $request): ?array
    {
        $sessionUserId = (int) $request->session()->get('gigtune_auth_user_id', 0);
        if ($sessionUserId <= 0) {
            return null;
        }

        $user = $this->users->getUserById($sessionUserId);
        return is_array($user) ? $user : null;
    }

    /** @param array<string,mixed> $user */
    private function isAdminLikeUser(array $user): bool
    {
        if ((bool) ($user['is_admin'] ?? false)) {
            return true;
        }

        $roles = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            (array) ($user['roles'] ?? [])
        );

        foreach ([
            'administrator',
            'gigtune_admin',
            'gigtune_administrator',
            'gts_admin',
            'manage_options',
            'update_core',
            'update_plugins',
            'gigtune_manage_payments',
        ] as $adminRole) {
            if (in_array($adminRole, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $user */
    private function homeDashboardSlugForUser(array $user): string
    {
        if ($this->isAdminLikeUser($user)) {
            return '';
        }

        $roles = array_map(
            static fn ($role): string => strtolower(trim((string) $role)),
            (array) ($user['roles'] ?? [])
        );
        if (in_array('gigtune_artist', $roles, true)) {
            return 'artist-dashboard';
        }
        if (in_array('gigtune_client', $roles, true)) {
            return 'client-dashboard';
        }

        $dashboardUrl = trim((string) ($user['dashboard_url'] ?? ''));
        if ($dashboardUrl !== '') {
            $path = parse_url($dashboardUrl, PHP_URL_PATH);
            $slug = trim((string) $path, '/');
            if (in_array($slug, ['artist-dashboard', 'client-dashboard'], true)) {
                return $slug;
            }
        }

        return '';
    }

    private function logoutSession(Request $request): void
    {
        $request->session()->forget([
            'gigtune_auth_user_id',
            'gigtune_auth_logged_in_at',
            'gigtune_auth_remember',
        ]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function resolveTemplate(array $page): string
    {
        $slug = trim((string) ($page['post_name'] ?? ''));
        if ($this->isFrontPage($page)) {
            return 'front-page.php';
        }

        if ($slug !== '') {
            $candidate = 'page-' . $slug . '.php';
            $candidatePath = resource_path('wp-theme/gigtune-canon/' . $candidate);
            if (is_file($candidatePath)) {
                return $candidate;
            }
        }

        return 'page.php';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFrontPage(): ?array
    {
        $frontId = (int) $this->db()->table($this->tablePrefix() . 'options')
            ->where('option_name', 'page_on_front')
            ->value('option_value');

        if ($frontId > 0) {
            $row = $this->db()->table($this->tablePrefix() . 'posts')
                ->where('ID', $frontId)
                ->where('post_type', 'page')
                ->whereIn('post_status', ['publish', 'private'])
                ->first(['ID', 'post_name', 'post_title', 'post_content']);
            if ($row !== null) {
                return [
                    'ID' => (int) $row->ID,
                    'post_name' => (string) $row->post_name,
                    'post_title' => (string) $row->post_title,
                    'post_content' => (string) $row->post_content,
                ];
            }
        }

        return $this->loadPageBySlug('home');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadPageBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $row = $this->db()->table($this->tablePrefix() . 'posts')
            ->where('post_type', 'page')
            ->whereIn('post_status', ['publish', 'private'])
            ->where('post_name', $slug)
            ->orderByDesc('ID')
            ->first(['ID', 'post_name', 'post_title', 'post_content']);

        if ($row === null) {
            return null;
        }

        return [
            'ID' => (int) $row->ID,
            'post_name' => (string) $row->post_name,
            'post_title' => (string) $row->post_title,
            'post_content' => (string) $row->post_content,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadVirtualTemplatePage(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $templatePath = resource_path('wp-theme/gigtune-canon/page-' . $slug . '.php');
        if (!is_file($templatePath)) {
            return null;
        }

        return [
            'ID' => 0,
            'post_name' => $slug,
            'post_title' => ucwords(str_replace(['-', '_'], ' ', $slug)),
            'post_content' => '',
        ];
    }

    private function isFrontPage(array $page): bool
    {
        $frontId = (int) $this->db()->table($this->tablePrefix() . 'options')
            ->where('option_name', 'page_on_front')
            ->value('option_value');
        return $frontId > 0 && $frontId === (int) ($page['ID'] ?? 0);
    }

    private function handleJoin(Request $request): RedirectResponse|bool
    {
        $role = preg_replace('/[^a-z0-9_\\-]/', '', strtolower((string) $request->input('gigtune_role', 'gigtune_client'))) ?? 'gigtune_client';
        if (!in_array($role, ['gigtune_client', 'gigtune_artist'], true)) {
            $role = 'gigtune_client';
        }

        $fullName = trim((string) $request->input('gigtune_full_name', ''));
        $email = strtolower(trim((string) $request->input('gigtune_email', '')));
        $password = (string) $request->input('gigtune_password', '');
        $confirm = (string) $request->input('gigtune_password_confirm', '');
        $requiredPolicies = array_keys((array) config('gigtune.policy.versions', []));
        $acceptedPolicies = $this->users->mapAcceptedPolicyInput($request->all());
        if ((string) $request->input('gigtune_terms_acceptance', '') === '1' && $acceptedPolicies === [] && $requiredPolicies !== []) {
            $acceptedPolicies = $requiredPolicies;
        }
        $hasRequiredPolicyAcceptance = $requiredPolicies === [] || array_values(array_diff($requiredPolicies, $acceptedPolicies)) === [];

        if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || $password !== $confirm || !$hasRequiredPolicyAcceptance) {
            return redirect('/join/?register_error=1&register_error_msg=' . rawurlencode('Please complete all fields and confirm terms.'), 302);
        }

        $loginBase = preg_replace('/[^a-z0-9._-]+/', '', strtolower((string) strstr($email, '@', true))) ?: ('user' . time());
        $login = $loginBase;

        $created = null;
        for ($i = 0; $i < 5; $i++) {
            try {
                $created = $this->users->createUser([
                    'login' => $login,
                    'email' => $email,
                    'password' => $password,
                    'display_name' => $fullName,
                    'roles' => [$role],
                ]);
                break;
            } catch (\Throwable $throwable) {
                if ($i === 4) {
                    return redirect('/join/?register_error=1&register_error_msg=' . rawurlencode($throwable->getMessage()), 302);
                }
                $login = $loginBase . random_int(10, 99);
            }
        }

        if (!is_array($created)) {
            return redirect('/join/?register_error=1&register_error_msg=' . rawurlencode('Unable to create account.'), 302);
        }

        $userId = (int) ($created['id'] ?? 0);
        if ($userId <= 0) {
            return redirect('/join/?register_error=1&register_error_msg=' . rawurlencode('Invalid account state.'), 302);
        }

        $this->ensureRoleProfile($userId, $role, $fullName, $email);
        if ($requiredPolicies !== []) {
            $this->users->storePolicyAcceptance($userId, $requiredPolicies);
        }

        $request->session()->put('gigtune_auth_user_id', $userId);
        $request->session()->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $request->session()->put('gigtune_auth_remember', true);

        $dashboard = $role === 'gigtune_artist' ? '/artist-dashboard/' : '/client-dashboard/';
        return redirect($dashboard, 302);
    }

    private function handleSignIn(Request $request): RedirectResponse
    {
        $identifier = trim((string) $request->input('log', ''));
        $password = (string) $request->input('pwd', '');

        $user = $this->users->verifyCredentials($identifier, $password);
        if (!is_array($user)) {
            return redirect('/sign-in/?login=failed', 302);
        }

        $request->session()->put('gigtune_auth_user_id', (int) ($user['id'] ?? 0));
        $request->session()->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $request->session()->put('gigtune_auth_remember', trim((string) $request->input('rememberme', '')) !== '');
        $request->session()->regenerate();

        $fallback = (string) ($user['dashboard_url'] ?? '/');
        $redirectTo = trim((string) $request->input('redirect_to', ''));
        if ($redirectTo === '' || str_contains($redirectTo, '/sign-in') || str_contains($redirectTo, '/login')) {
            $redirectTo = $fallback;
        }

        return redirect($this->safeRedirectPath($redirectTo, $fallback), 302);
    }

    private function ensureRoleProfile(int $userId, string $role, string $name, string $email): void
    {
        if ($role === 'gigtune_artist') {
            $existing = (int) $this->db()->table($this->tablePrefix() . 'usermeta')
                ->where('user_id', $userId)
                ->where('meta_key', 'gigtune_artist_profile_id')
                ->orderByDesc('umeta_id')
                ->value('meta_value');

            if ($existing <= 0) {
                $postId = (int) $this->db()->table($this->tablePrefix() . 'posts')->insertGetId([
                    'post_author' => $userId,
                    'post_date' => now()->format('Y-m-d H:i:s'),
                    'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_content' => '',
                    'post_title' => $name,
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_name' => $this->slugify($name) . '-' . $userId,
                    'post_modified' => now()->format('Y-m-d H:i:s'),
                    'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_type' => 'artist_profile',
                ]);

                $this->upsertUserMeta($userId, 'gigtune_artist_profile_id', (string) $postId);
                $this->upsertPostMeta($postId, 'gigtune_user_id', (string) $userId);
            }

            return;
        }

        $existing = (int) $this->db()->table($this->tablePrefix() . 'usermeta')
            ->where('user_id', $userId)
            ->where('meta_key', 'gigtune_client_profile_id')
            ->orderByDesc('umeta_id')
            ->value('meta_value');

        if ($existing > 0) {
            return;
        }

        $postId = (int) $this->db()->table($this->tablePrefix() . 'posts')->insertGetId([
            'post_author' => $userId,
            'post_date' => now()->format('Y-m-d H:i:s'),
            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_content' => '',
            'post_title' => $name,
            'post_status' => 'publish',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
            'post_name' => $this->slugify($name) . '-' . $userId,
            'post_modified' => now()->format('Y-m-d H:i:s'),
            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_type' => 'gt_client_profile',
        ]);

        $this->upsertUserMeta($userId, 'gigtune_client_profile_id', (string) $postId);
        $this->upsertPostMeta($postId, 'gigtune_client_user_id', (string) $userId);
        $this->upsertPostMeta($postId, 'gigtune_client_company', $name);
        $this->upsertPostMeta($postId, 'gigtune_client_phone', '');
    }

    private function upsertPostMeta(int $postId, string $key, string $value): void
    {
        $row = $this->db()->table($this->tablePrefix() . 'postmeta')
            ->select('meta_id')
            ->where('post_id', $postId)
            ->where('meta_key', $key)
            ->orderByDesc('meta_id')
            ->first();

        if ($row !== null && isset($row->meta_id)) {
            $this->db()->table($this->tablePrefix() . 'postmeta')
                ->where('meta_id', (int) $row->meta_id)
                ->update(['meta_value' => $value]);
            return;
        }

        $this->db()->table($this->tablePrefix() . 'postmeta')->insert([
            'post_id' => $postId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function upsertUserMeta(int $userId, string $key, string $value): void
    {
        $row = $this->db()->table($this->tablePrefix() . 'usermeta')
            ->select('umeta_id')
            ->where('user_id', $userId)
            ->where('meta_key', $key)
            ->orderByDesc('umeta_id')
            ->first();

        if ($row !== null && isset($row->umeta_id)) {
            $this->db()->table($this->tablePrefix() . 'usermeta')
                ->where('umeta_id', (int) $row->umeta_id)
                ->update(['meta_value' => $value]);
            return;
        }

        $this->db()->table($this->tablePrefix() . 'usermeta')->insert([
            'user_id' => $userId,
            'meta_key' => $key,
            'meta_value' => $value,
        ]);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection((string) config('gigtune.wordpress.database_connection', 'wordpress'));
    }

    private function tablePrefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'user';
    }

    private function createWpNonce(string $action): string
    {
        $seed = csrf_token() . '|' . $action . '|' . (string) config('app.key', '');
        return hash('sha256', $seed);
    }

    private function verifyWpNonce(string $nonce, string $action): bool
    {
        return hash_equals($this->createWpNonce($action), trim($nonce));
    }

    private function safeLocalRedirect(string $candidate, string $fallback): string
    {
        $candidate = trim($candidate);
        if ($candidate === '' || !str_starts_with($candidate, '/')) {
            return $fallback;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return $fallback;
        }

        $path = trim((string) ($parts['path'] ?? ''));
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        $pathBase = rtrim($path, '/');
        if (!in_array($pathBase, ['/notifications', '/notifications-archive'], true)) {
            return $fallback;
        }

        $query = isset($parts['query']) && trim((string) $parts['query']) !== ''
            ? ('?' . (string) $parts['query'])
            : '';
        $fragment = isset($parts['fragment']) && trim((string) $parts['fragment']) !== ''
            ? ('#' . (string) $parts['fragment'])
            : '';

        return $path . $query . $fragment;
    }

    private function safeRedirectPath(string $candidate, string $fallback): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return $fallback;
        }

        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            $parts = parse_url($candidate);
            if (!is_array($parts)) {
                return $fallback;
            }
            $host = strtolower(trim((string) ($parts['host'] ?? '')));
            $appHost = strtolower(trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST)));
            if ($host === '' || $appHost === '' || $host !== $appHost) {
                return $fallback;
            }
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) && trim((string) $parts['query']) !== ''
                ? ('?' . (string) $parts['query'])
                : '';
            $fragment = isset($parts['fragment']) && trim((string) $parts['fragment']) !== ''
                ? ('#' . (string) $parts['fragment'])
                : '';
            $candidate = $path . $query . $fragment;
        }

        if (!str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return $fallback;
        }

        return $candidate;
    }
}
