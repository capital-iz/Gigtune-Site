<?php

namespace App\Support;

use App\Services\GigTuneShortcodeService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WpThemeRuntime
{
    private static ?Request $request = null;
    private static ?array $currentUser = null;
    private static ?array $page = null;
    private static ?GigTuneShortcodeService $shortcodes = null;
    private static string $themeRoot = '';
    private static string $themeUrl = '/wp-content/themes/gigtune-canon';
    private static bool $loopConsumed = false;
    private static int $responseStatus = 200;

    public static function init(Request $request, ?array $currentUser, ?array $page, GigTuneShortcodeService $shortcodes, string $themeRoot, int $responseStatus = 200): void
    {
        self::$request = $request;
        self::$currentUser = $currentUser;
        self::$page = $page;
        self::$shortcodes = $shortcodes;
        self::$themeRoot = rtrim($themeRoot, '\\/');
        self::$loopConsumed = false;
        self::$responseStatus = $responseStatus;

        if (!defined('ABSPATH')) {
            define('ABSPATH', base_path() . DIRECTORY_SEPARATOR);
        }
    }

    public static function renderTemplate(string $templatePath): string
    {
        ob_start();
        include $templatePath;
        return (string) ob_get_clean();
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function currentUser(): ?array
    {
        return self::$currentUser;
    }

    public static function currentPage(): ?array
    {
        return self::$page;
    }

    public static function themeRoot(): string
    {
        return self::$themeRoot;
    }

    public static function themeUrl(): string
    {
        return self::$themeUrl;
    }

    public static function responseStatus(): int
    {
        return self::$responseStatus;
    }

    public static function templatePath(string $relative): string
    {
        return self::$themeRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, '\\/'));
    }

    public static function hasShortcode(string $tag): bool
    {
        return self::$shortcodes?->has($tag) ?? false;
    }

    public static function doShortcode(string $content): string
    {
        $request = self::$request;
        $context = [
            'request_method' => strtoupper((string) ($request?->method() ?? 'GET')),
            'post' => $request?->all() ?? [],
            'request' => $request,
        ];

        return self::$shortcodes?->render($content, self::$currentUser, $context) ?? '';
    }

    public static function homeUrl(string $path = ''): string
    {
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $request = self::$request;
        if ($request !== null) {
            // Use Symfony-normalized host so non-standard ports (e.g. :8002) are preserved.
            $base = rtrim($request->getSchemeAndHttpHost(), '/');
            $basePath = trim((string) $request->getBaseUrl());
            if ($basePath !== '') {
                $base .= '/' . trim($basePath, '/');
            }
        } else {
            $base = rtrim((string) config('app.url', ''), '/');
        }

        if ($path === '') {
            return $base !== '' ? $base : '/';
        }

        return ($base !== '' ? $base : '') . '/' . ltrim($path, '/');
    }

    public static function pageSlug(): string
    {
        return trim((string) (self::$page['post_name'] ?? ''));
    }

    public static function pageTitle(): string
    {
        return trim((string) (self::$page['post_title'] ?? 'GigTune'));
    }

    public static function pageContentRendered(): string
    {
        return self::doShortcode((string) (self::$page['post_content'] ?? ''));
    }

    public static function pageBodyClasses(string $extra = ''): string
    {
        $classes = ['min-h-screen', 'bg-slate-950', 'text-slate-200', 'font-sans', 'selection:bg-purple-500/30', 'selection:text-white', 'flex', 'flex-col'];
        $slug = self::pageSlug();

        if ($slug !== '') {
            $classes[] = 'page';
            $classes[] = 'page-' . $slug;
        }
        if ($slug === 'home' || self::isFrontPage()) {
            $classes[] = 'home';
        }
        if ($extra !== '') {
            foreach (preg_split('/\s+/', trim($extra)) as $token) {
                if ($token !== null && $token !== '') {
                    $classes[] = $token;
                }
            }
        }

        return implode(' ', array_values(array_unique($classes)));
    }

    public static function isFrontPage(): bool
    {
        $page = self::$page;
        if (!is_array($page)) {
            return false;
        }

        $frontId = (int) self::db()->table(self::prefix() . 'options')
            ->where('option_name', 'page_on_front')
            ->value('option_value');

        return $frontId > 0 && $frontId === (int) ($page['ID'] ?? 0);
    }

    public static function getPageByPath(string $slug): ?array
    {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return null;
        }

        $row = self::db()->table(self::prefix() . 'posts')
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

    public static function permalinkById(int $pageId): string
    {
        if ($pageId <= 0) {
            return self::homeUrl('/');
        }

        $slug = (string) self::db()->table(self::prefix() . 'posts')
            ->where('ID', $pageId)
            ->value('post_name');

        if ($slug === '') {
            return self::homeUrl('/');
        }

        return self::homeUrl('/' . trim($slug, '/') . '/');
    }

    public static function wpUserObject(): object
    {
        $u = self::$currentUser;
        if (!is_array($u)) {
            return new \WP_User();
        }

        return new \WP_User([
            'ID' => (int) ($u['id'] ?? 0),
            'user_login' => (string) ($u['login'] ?? ''),
            'user_email' => (string) ($u['email'] ?? ''),
            'display_name' => (string) ($u['display_name'] ?? ''),
            'roles' => is_array($u['roles'] ?? null) ? $u['roles'] : [],
        ]);
    }

    public static function loopHasPosts(): bool
    {
        return !self::$loopConsumed && is_array(self::$page);
    }

    public static function loopNext(): void
    {
        self::$loopConsumed = true;
    }

    public static function resetLoop(): void
    {
        self::$loopConsumed = false;
    }

    public static function getArtists(array $args = []): array
    {
        return self::$shortcodes?->getArtists($args) ?? ['items' => []];
    }

    public static function getArtistById(int $id): ?array
    {
        return self::$shortcodes?->getArtistById($id);
    }

    public static function getFilterOptions(): array
    {
        return self::$shortcodes?->getFilterOptions() ?? [];
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection((string) config('gigtune.wordpress.database_connection', 'wordpress'));
    }

    private static function prefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }
}
