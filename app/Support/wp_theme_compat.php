<?php

use App\Support\WpThemeRuntime;

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = 'error',
            public string $message = 'Error'
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User
    {
        public int $ID = 0;
        public string $user_login = '';
        public string $user_email = '';
        public string $display_name = '';
        /** @var array<int,string> */
        public array $roles = [];

        /** @param array<string,mixed> $data */
        public function __construct(array $data = [])
        {
            $this->ID = (int) ($data['ID'] ?? 0);
            $this->user_login = (string) ($data['user_login'] ?? '');
            $this->user_email = (string) ($data['user_email'] ?? '');
            $this->display_name = (string) ($data['display_name'] ?? '');
            $this->roles = is_array($data['roles'] ?? null) ? array_values($data['roles']) : [];
        }
    }
}

if (!function_exists('gigtune_wp_hook_store')) {
    /**
     * @return array{actions: array<string,array<int,array<int,array{callback:callable,accepted_args:int}>>>, filters: array<string,array<int,array<int,array{callback:callable,accepted_args:int}>>>, did_actions: array<string,int>}
     */
    function gigtune_wp_hook_store(): array
    {
        if (!isset($GLOBALS['gigtune_wp_hook_store']) || !is_array($GLOBALS['gigtune_wp_hook_store'])) {
            $GLOBALS['gigtune_wp_hook_store'] = [
                'actions' => [],
                'filters' => [],
                'did_actions' => [],
            ];
        }
        return $GLOBALS['gigtune_wp_hook_store'];
    }
}

if (!function_exists('gigtune_wp_set_hook_store')) {
    /**
     * @param array{actions: array<string,array<int,array<int,array{callback:callable,accepted_args:int}>>>, filters: array<string,array<int,array<int,array{callback:callable,accepted_args:int}>>>, did_actions: array<string,int>} $store
     */
    function gigtune_wp_set_hook_store(array $store): void
    {
        $GLOBALS['gigtune_wp_hook_store'] = $store;
    }
}

if (!function_exists('gigtune_wp_enqueue_store')) {
    /**
     * @return array{
     *   styles: array<string,array{src:string,deps:array<int,string>,ver:string,media:string}>,
     *   style_inline: array<string,array<int,string>>,
     *   scripts: array<string,array{src:string,deps:array<int,string>,ver:string,in_footer:bool}>,
     *   did_enqueue: bool
     * }
     */
    function gigtune_wp_enqueue_store(): array
    {
        if (!isset($GLOBALS['gigtune_wp_enqueue_store']) || !is_array($GLOBALS['gigtune_wp_enqueue_store'])) {
            $GLOBALS['gigtune_wp_enqueue_store'] = [
                'styles' => [],
                'style_inline' => [],
                'scripts' => [],
                'did_enqueue' => false,
            ];
        }
        return $GLOBALS['gigtune_wp_enqueue_store'];
    }
}

if (!function_exists('gigtune_wp_set_enqueue_store')) {
    /**
     * @param array{
     *   styles: array<string,array{src:string,deps:array<int,string>,ver:string,media:string}>,
     *   style_inline: array<string,array<int,string>>,
     *   scripts: array<string,array{src:string,deps:array<int,string>,ver:string,in_footer:bool}>,
     *   did_enqueue: bool
     * } $store
     */
    function gigtune_wp_set_enqueue_store(array $store): void
    {
        $GLOBALS['gigtune_wp_enqueue_store'] = $store;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $store = gigtune_wp_hook_store();
        $store['actions'][$hook][$priority][] = [
            'callback' => $callback,
            'accepted_args' => max(0, $accepted_args),
        ];
        gigtune_wp_set_hook_store($store);
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $store = gigtune_wp_hook_store();
        $store['filters'][$hook][$priority][] = [
            'callback' => $callback,
            'accepted_args' => max(0, $accepted_args),
        ];
        gigtune_wp_set_hook_store($store);
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        $store = gigtune_wp_hook_store();
        if (!isset($store['actions'][$hook]) || !is_array($store['actions'][$hook])) {
            return;
        }

        $priorities = array_keys($store['actions'][$hook]);
        sort($priorities, SORT_NUMERIC);
        foreach ($priorities as $priority) {
            foreach ($store['actions'][$hook][$priority] as $entry) {
                $accepted = (int) ($entry['accepted_args'] ?? 1);
                $callArgs = $accepted > 0 ? array_slice($args, 0, $accepted) : [];
                call_user_func_array($entry['callback'], $callArgs);
            }
        }

        $store['did_actions'][$hook] = (int) ($store['did_actions'][$hook] ?? 0) + 1;
        gigtune_wp_set_hook_store($store);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $store = gigtune_wp_hook_store();
        if (!isset($store['filters'][$hook]) || !is_array($store['filters'][$hook])) {
            return $value;
        }

        $priorities = array_keys($store['filters'][$hook]);
        sort($priorities, SORT_NUMERIC);
        foreach ($priorities as $priority) {
            foreach ($store['filters'][$hook][$priority] as $entry) {
                $accepted = (int) ($entry['accepted_args'] ?? 1);
                $remaining = $accepted > 1 ? array_slice($args, 0, $accepted - 1) : [];
                $value = call_user_func_array($entry['callback'], array_merge([$value], $remaining));
            }
        }

        return $value;
    }
}

if (!function_exists('did_action')) {
    function did_action(string $hook): int
    {
        $store = gigtune_wp_hook_store();
        return (int) ($store['did_actions'][$hook] ?? 0);
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|int|bool|null $ver = null, string $media = 'all'): void
    {
        $handle = trim($handle);
        if ($handle === '') {
            return;
        }
        $store = gigtune_wp_enqueue_store();
        $version = ($ver === null || $ver === false) ? '' : (string) $ver;
        $store['styles'][$handle] = [
            'src' => $src,
            'deps' => array_values(array_unique(array_filter(array_map(static fn ($d) => trim((string) $d), $deps), static fn ($d) => $d !== ''))),
            'ver' => $version,
            'media' => trim($media) !== '' ? trim($media) : 'all',
        ];
        gigtune_wp_set_enqueue_store($store);
    }
}

if (!function_exists('wp_add_inline_style')) {
    function wp_add_inline_style(string $handle, string $data): bool
    {
        $handle = trim($handle);
        if ($handle === '' || trim($data) === '') {
            return false;
        }
        $store = gigtune_wp_enqueue_store();
        if (!isset($store['style_inline'][$handle])) {
            $store['style_inline'][$handle] = [];
        }
        $store['style_inline'][$handle][] = $data;
        gigtune_wp_set_enqueue_store($store);
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|int|bool|null $ver = null, bool $in_footer = false): void
    {
        $handle = trim($handle);
        if ($handle === '') {
            return;
        }
        $store = gigtune_wp_enqueue_store();
        $version = ($ver === null || $ver === false) ? '' : (string) $ver;
        $store['scripts'][$handle] = [
            'src' => $src,
            'deps' => array_values(array_unique(array_filter(array_map(static fn ($d) => trim((string) $d), $deps), static fn ($d) => $d !== ''))),
            'ver' => $version,
            'in_footer' => $in_footer,
        ];
        gigtune_wp_set_enqueue_store($store);
    }
}

if (!function_exists('gigtune_wp_render_styles')) {
    function gigtune_wp_render_styles(): void
    {
        $store = gigtune_wp_enqueue_store();
        $styles = $store['styles'] ?? [];
        if (!is_array($styles) || $styles === []) {
            return;
        }

        $rendered = [];
        $renderOne = function (string $handle) use (&$renderOne, &$rendered, $styles, $store): void {
            if (isset($rendered[$handle])) {
                return;
            }
            $entry = $styles[$handle] ?? null;
            if (!is_array($entry)) {
                return;
            }
            $deps = is_array($entry['deps'] ?? null) ? $entry['deps'] : [];
            foreach ($deps as $dep) {
                if (is_string($dep) && $dep !== '') {
                    $renderOne($dep);
                }
            }

            $src = (string) ($entry['src'] ?? '');
            if ($src !== '') {
                $sep = str_contains($src, '?') ? '&' : '?';
                $ver = (string) ($entry['ver'] ?? '');
                if ($ver !== '') {
                    $src .= $sep . 'ver=' . rawurlencode($ver);
                }
                echo '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($src) . '" media="' . esc_attr((string) ($entry['media'] ?? 'all')) . '">' . PHP_EOL;
            }

            $inline = $store['style_inline'][$handle] ?? [];
            if (is_array($inline) && $inline !== []) {
                echo '<style id="' . esc_attr($handle) . '-inline-css">' . PHP_EOL;
                foreach ($inline as $css) {
                    echo (string) $css . PHP_EOL;
                }
                echo '</style>' . PHP_EOL;
            }

            $rendered[$handle] = true;
        };

        foreach (array_keys($styles) as $handle) {
            if (is_string($handle) && $handle !== '') {
                $renderOne($handle);
            }
        }
    }
}

if (!function_exists('gigtune_wp_render_scripts')) {
    function gigtune_wp_render_scripts(bool $inFooter): void
    {
        $store = gigtune_wp_enqueue_store();
        $scripts = $store['scripts'] ?? [];
        if (!is_array($scripts) || $scripts === []) {
            return;
        }

        foreach ($scripts as $handle => $entry) {
            if (!is_array($entry) || (bool) ($entry['in_footer'] ?? false) !== $inFooter) {
                continue;
            }
            $src = (string) ($entry['src'] ?? '');
            if ($src === '') {
                continue;
            }
            $sep = str_contains($src, '?') ? '&' : '?';
            $ver = (string) ($entry['ver'] ?? '');
            if ($ver !== '') {
                $src .= $sep . 'ver=' . rawurlencode($ver);
            }
            echo '<script id="' . esc_attr((string) $handle) . '-js" src="' . esc_url($src) . '"></script>' . PHP_EOL;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return WpThemeRuntime::homeUrl($path);
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return home_url($path);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_js')) {
    function esc_js(string $value): string
    {
        return addslashes($value);
    }
}

if (!function_exists('wp_head')) {
    function wp_head(): void
    {
        $store = gigtune_wp_enqueue_store();
        if (!(bool) ($store['did_enqueue'] ?? false)) {
            do_action('wp_enqueue_scripts');
            $store = gigtune_wp_enqueue_store();
            $store['did_enqueue'] = true;
            gigtune_wp_set_enqueue_store($store);
        }
        gigtune_wp_render_styles();
        gigtune_wp_render_scripts(false);
        do_action('wp_head');
    }
}

if (!function_exists('wp_footer')) {
    function wp_footer(): void
    {
        gigtune_wp_render_scripts(true);
        do_action('wp_footer');
    }
}

if (!function_exists('language_attributes')) {
    function language_attributes(): void
    {
        echo 'lang="en-US"';
    }
}

if (!function_exists('bloginfo')) {
    function bloginfo(string $show = ''): void
    {
        echo get_bloginfo($show);
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        $show = strtolower(trim($show));
        return match ($show) {
            'charset' => 'UTF-8',
            'version' => '6.7.1',
            default => 'GigTune',
        };
    }
}

if (!function_exists('body_class')) {
    function body_class(string $extra = ''): void
    {
        echo 'class="' . esc_attr(WpThemeRuntime::pageBodyClasses($extra)) . '"';
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}

if (!function_exists('get_template_directory_uri')) {
    function get_template_directory_uri(): string
    {
        return WpThemeRuntime::themeUrl();
    }
}

if (!function_exists('get_stylesheet_directory_uri')) {
    function get_stylesheet_directory_uri(): string
    {
        return WpThemeRuntime::themeUrl();
    }
}

if (!function_exists('get_template_directory')) {
    function get_template_directory(): string
    {
        return public_path('wp-content/themes/gigtune-canon');
    }
}

if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory(): string
    {
        return get_template_directory();
    }
}

if (!function_exists('get_stylesheet_uri')) {
    function get_stylesheet_uri(): string
    {
        return get_stylesheet_directory_uri() . '/style.css';
    }
}

if (!function_exists('get_header')) {
    function get_header(): void
    {
        include WpThemeRuntime::templatePath('header.php');
    }
}

if (!function_exists('get_footer')) {
    function get_footer(): void
    {
        include WpThemeRuntime::templatePath('footer.php');
    }
}

if (!function_exists('get_template_part')) {
    function get_template_part(string $slug): void
    {
        $path = WpThemeRuntime::templatePath($slug . '.php');
        if (is_file($path)) {
            include $path;
        }
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        $user = WpThemeRuntime::currentUser();
        return is_array($user) && (int) ($user['id'] ?? 0) > 0;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): WP_User
    {
        return WpThemeRuntime::wpUserObject();
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        $u = WpThemeRuntime::currentUser();
        return is_array($u) ? (int) ($u['id'] ?? 0) : 0;
    }
}

if (!function_exists('wp_logout_url')) {
    function wp_logout_url(string $redirect = ''): string
    {
        $target = $redirect !== '' ? $redirect : home_url('/');
        return '/sign-out/?redirect_to=' . rawurlencode($target);
    }
}

if (!function_exists('wp_logout')) {
    function wp_logout(): void
    {
        $request = WpThemeRuntime::request();
        if ($request !== null) {
            $request->session()->forget(['gigtune_auth_user_id', 'gigtune_auth_logged_in_at', 'gigtune_auth_remember']);
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}

if (!function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool
    {
        return WpThemeRuntime::hasShortcode($tag);
    }
}

if (!function_exists('do_shortcode')) {
    function do_shortcode(string $content): string
    {
        return WpThemeRuntime::doShortcode($content);
    }
}

if (!function_exists('have_posts')) {
    function have_posts(): bool
    {
        return WpThemeRuntime::loopHasPosts();
    }
}

if (!function_exists('the_post')) {
    function the_post(): void
    {
        WpThemeRuntime::loopNext();
    }
}

if (!function_exists('the_title')) {
    function the_title(): void
    {
        echo esc_html(WpThemeRuntime::pageTitle());
    }
}

if (!function_exists('the_content')) {
    function the_content(): void
    {
        echo WpThemeRuntime::pageContentRendered();
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(int $id = 0): string
    {
        if ($id > 0) {
            return WpThemeRuntime::permalinkById($id);
        }

        $slug = WpThemeRuntime::pageSlug();
        return $slug !== '' ? home_url('/' . $slug . '/') : home_url('/');
    }
}

if (!function_exists('get_page_by_path')) {
    function get_page_by_path(string $path): ?object
    {
        $page = WpThemeRuntime::getPageByPath($path);
        if (!is_array($page)) {
            return null;
        }
        return (object) $page;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string|array $key, mixed $value = null, string $url = ''): string
    {
        $url = $url !== '' ? $url : (WpThemeRuntime::request()?->fullUrl() ?? home_url('/'));
        $parts = parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if (is_array($key)) {
            foreach ($key as $argKey => $argValue) {
                $query[(string) $argKey] = $argValue;
            }
        } else {
            $query[$key] = $value;
        }

        $scheme = (string) ($parts['scheme'] ?? parse_url((string) config('app.url', ''), PHP_URL_SCHEME) ?? 'http');
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $fragment = isset($parts['fragment']) ? '#' . (string) $parts['fragment'] : '';

        if ($host !== '') {
            $base = $scheme . '://' . $host . $port . $path;
        } else {
            $base = rtrim((string) config('app.url', ''), '/') . $path;
        }

        $qs = http_build_query($query);
        return $base . ($qs !== '' ? ('?' . $qs) : '') . $fragment;
    }
}

if (!function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        $v = strtolower(trim($value));
        return preg_replace('/[^a-z0-9_\-]/', '', $v) ?? '';
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $value): string
    {
        return filter_var($value, FILTER_SANITIZE_EMAIL) ?: '';
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(string $value): string
    {
        return stripslashes($value);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (!function_exists('checked')) {
    function checked(mixed $checked, mixed $current = true, bool $echo = true): string
    {
        $out = ((string) $checked === (string) $current) ? 'checked="checked"' : '';
        if ($echo) {
            echo $out;
        }
        return $out;
    }
}

if (!function_exists('selected')) {
    function selected(mixed $selected, mixed $current = true, bool $echo = true): string
    {
        $out = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        if ($echo) {
            echo $out;
        }
        return $out;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '', string $name = '_wpnonce'): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        return $redirect !== ''
            ? '/sign-in/?redirect_to=' . rawurlencode($redirect)
            : '/sign-in/';
    }
}

if (!function_exists('wp_lostpassword_url')) {
    function wp_lostpassword_url(): string
    {
        return home_url('/forgot-password/');
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        $user = WpThemeRuntime::currentUser();
        if (!is_array($user)) {
            return false;
        }
        if ((bool) ($user['is_admin'] ?? false)) {
            return true;
        }
        $roles = is_array($user['roles'] ?? null) ? $user['roles'] : [];
        return in_array($capability, $roles, true);
    }
}

if (!function_exists('show_admin_bar')) {
    function show_admin_bar(bool $show): void
    {
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $url, int $status = 302): void
    {
        header('Location: ' . $url, true, $status);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return false;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return home_url('/' . ltrim($path, '/'));
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        $request = WpThemeRuntime::request();
        if ($request === null) {
            return false;
        }
        $path = '/' . ltrim($request->path(), '/');
        return str_ends_with($path, '/admin-ajax.php') || $path === '/admin-ajax.php';
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = ''): string
    {
        $seed = csrf_token() . '|' . $action . '|' . (string) config('app.key', '');
        return hash('sha256', $seed);
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce = '', string $action = ''): bool
    {
        return hash_equals(wp_create_nonce($action), trim($nonce));
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer(string $action = '', string $query_arg = '_ajax_nonce', bool $stop = true): bool
    {
        $request = WpThemeRuntime::request();
        $token = (string) ($request?->input($query_arg, '') ?? '');
        $ok = wp_verify_nonce($token, $action);
        if (!$ok && $stop) {
            http_response_code(403);
            exit;
        }
        return $ok;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success(array $data = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error(array $data = [], int $status = 400): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('is_404')) {
    function is_404(): bool
    {
        return (int) (WpThemeRuntime::responseStatus() ?? 200) === 404;
    }
}

if (!function_exists('is_feed')) {
    function is_feed(): bool
    {
        return false;
    }
}

if (!function_exists('is_trackback')) {
    function is_trackback(): bool
    {
        return false;
    }
}

if (!function_exists('is_preview')) {
    function is_preview(): bool
    {
        return false;
    }
}

if (!function_exists('wp_validate_redirect')) {
    function wp_validate_redirect(string $location, string $default = ''): string
    {
        $location = trim($location);
        if ($location === '') {
            return $default;
        }

        $parts = parse_url($location);
        if ($parts === false) {
            return $default;
        }

        if (!isset($parts['host'])) {
            if (!str_starts_with($location, '/')) {
                return $default;
            }
            return $location;
        }

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '' && strcasecmp((string) $parts['host'], $appHost) === 0) {
            return $location;
        }

        return $default;
    }
}

if (!function_exists('status_header')) {
    function status_header(int $status_code): void
    {
        http_response_code($status_code);
    }
}

if (!function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

if (!function_exists('wp_get_referer')) {
    function wp_get_referer(): string
    {
        $request = WpThemeRuntime::request();
        return (string) ($request?->headers->get('referer', '') ?? '');
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql'): string|int
    {
        if ($type === 'timestamp') {
            return time();
        }
        if ($type === 'mysql') {
            return now()->format('Y-m-d H:i:s');
        }
        return now()->format('Y-m-d H:i:s');
    }
}

if (!function_exists('date_i18n')) {
    function date_i18n(string $format, int|false $timestamp = false, bool $gmt = false): string
    {
        $ts = $timestamp === false ? time() : (int) $timestamp;
        if ($ts <= 0) {
            $ts = time();
        }

        if ($gmt) {
            return gmdate($format, $ts);
        }

        return date($format, $ts);
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url): array|WP_Error
    {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 10],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return new WP_Error('http_error', 'Request failed');
        }

        $code = 200;
        $headers = $http_response_header ?? [];
        if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', (string) $headers[0], $m) === 1) {
            $code = (int) $m[1];
        }

        return [
            'response' => ['code' => $code],
            'body' => $body,
        ];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int
    {
        if ($response instanceof WP_Error) {
            return 500;
        }
        return (int) ($response['response']['code'] ?? 500);
    }
}

if (!function_exists('wp_get_theme')) {
    function wp_get_theme(): object
    {
        return new class {
            public function get(string $field): string
            {
                return match (strtolower($field)) {
                    'version' => '1.0',
                    default => 'GigTune Canon',
                };
            }
        };
    }
}

if (!function_exists('gigtune_form_flash_get')) {
    function gigtune_form_flash_get(string $scope, string $token): array
    {
        return [];
    }
}

if (!function_exists('gigtune_core_get_artists')) {
    function gigtune_core_get_artists(array $args = []): array
    {
        return WpThemeRuntime::getArtists($args);
    }
}

if (!function_exists('gigtune_core_get_artist')) {
    function gigtune_core_get_artist(int $id): array|WP_Error
    {
        $artist = WpThemeRuntime::getArtistById($id);
        return is_array($artist) ? $artist : new WP_Error('not_found', 'Artist not found');
    }
}

if (!function_exists('gigtune_core_get_filter_options')) {
    function gigtune_core_get_filter_options(): array
    {
        return WpThemeRuntime::getFilterOptions();
    }
}
