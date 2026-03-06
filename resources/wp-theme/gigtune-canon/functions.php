<?php
if (!defined('ABSPATH')) { exit; }

function gigtune_canon_enqueue_assets() {
    $css_path = get_template_directory() . '/assets/css/tailwind.css';
    $css_uri  = get_template_directory_uri() . '/assets/css/tailwind.css';

    wp_enqueue_style(
        'gigtune-tailwind',
        $css_uri,
        array(),
        file_exists($css_path) ? filemtime($css_path) : null
    );

    $theme_style_path = get_stylesheet_directory() . '/style.css';
    $theme_style_uri = get_stylesheet_uri();
    wp_enqueue_style(
        'gigtune-canon-style',
        $theme_style_uri,
        array('gigtune-tailwind'),
        file_exists($theme_style_path) ? filemtime($theme_style_path) : null
    );

    // --- IMPORTANT ---
    // This theme expects a COMPILED Tailwind build at /assets/css/tailwind.css.
    // If that file is missing (or is still the placeholder), the UI will render
    // as unstyled HTML.
    //
    // To help you validate the theme conversion quickly, we provide a TEMPORARY
    // fallback: load Tailwind via CDN if the compiled file is missing/empty.
    //
    // Remove this fallback once you have a proper build pipeline in place.
    // (CDN is not recommended for production.)
    if (!file_exists($css_path) || filesize($css_path) < 200) {
        add_action('wp_head', function () {
            echo "\n<!-- TEMP: Tailwind CDN fallback (remove after compiling assets/css/tailwind.css) -->\n";
            echo '<script src="https://cdn.tailwindcss.com"></script>' . "\n";
        }, 1);
    }

    // Minimal global helpers that the Gemini UI relied on:
    // - fade-in animation class
    // - hide-scrollbar utility class
    // These are kept as inline CSS to avoid needing extra build steps.
    $inline_css = "
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.animate-fade-in{animation:fadeIn .4s ease-out forwards}
.hide-scrollbar::-webkit-scrollbar{display:none}
.hide-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
";
    wp_add_inline_style('gigtune-tailwind', $inline_css);
}
add_action('wp_enqueue_scripts', 'gigtune_canon_enqueue_assets');

function gigtune_canon_hide_admin_bar_for_non_admins() {
    if (!is_user_logged_in()) {
        return;
    }
    if (!current_user_can('manage_options')) {
        show_admin_bar(false);
    }
}
add_action('after_setup_theme', 'gigtune_canon_hide_admin_bar_for_non_admins');

function gigtune_canon_output_pwa_meta() {
    if (is_admin()) {
        return;
    }

    $manifest_url = home_url('/manifest.webmanifest');
    $icon_url = trailingslashit(get_template_directory_uri()) . 'assets/img/gigtune-app-icon-192.png';
    ?>
    <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
    <meta name="theme-color" content="#0f172a">
    <link rel="apple-touch-icon" href="<?php echo esc_url($icon_url); ?>">
    <?php
}
add_action('wp_head', 'gigtune_canon_output_pwa_meta', 2);

function gigtune_canon_register_service_worker_script() {
    if (is_admin()) {
        return;
    }
    $sw_url = home_url('/service-worker.js');
    ?>
    <script>
    (function() {
      if (!('serviceWorker' in navigator)) return;
      window.addEventListener('load', function() {
        navigator.serviceWorker.register(<?php echo wp_json_encode($sw_url); ?>, { scope: '/' })
          .catch(function() { return null; });
      });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'gigtune_canon_register_service_worker_script', 5);

function gigtune_canon_serve_pwa_assets() {
    if (is_admin()) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $request_path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH));
    if ($request_path === '') {
        return;
    }

    if ($request_path === '/service-worker.js') {
        $sw_path = get_template_directory() . '/service-worker.js';
        if (!file_exists($sw_path)) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        readfile($sw_path);
        exit;
    }

    if ($request_path === '/manifest.webmanifest') {
        $manifest_path = get_template_directory() . '/manifest.webmanifest';
        if (!file_exists($manifest_path)) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: application/manifest+json; charset=UTF-8');
        readfile($manifest_path);
        exit;
    }
}
add_action('template_redirect', 'gigtune_canon_serve_pwa_assets', 0);

function gigtune_canon_handle_cookie_consent_ajax() {
    check_ajax_referer('gigtune_cookie_consent_nonce', 'nonce');

    $choice = sanitize_key((string) ($_POST['choice'] ?? ''));
    if (!in_array($choice, ['accept', 'reject'], true)) {
        wp_send_json_error(['message' => 'Invalid choice.'], 400);
    }

    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), 'gigtune_cookie_consent', $choice);
        update_user_meta(get_current_user_id(), 'gigtune_cookie_consent_at', current_time('mysql'));
    }

    wp_send_json_success(['ok' => true]);
}
add_action('wp_ajax_gigtune_cookie_consent', 'gigtune_canon_handle_cookie_consent_ajax');

function gigtune_render_cookie_banner() {
    if (is_admin()) {
        return;
    }

    $current_consent = sanitize_key((string) ($_COOKIE['gt_cookie_consent'] ?? ''));
    if (in_array($current_consent, ['accept', 'reject'], true)) {
        return;
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('gigtune_cookie_consent_nonce');
    $is_logged_in = is_user_logged_in();
    ?>
    <div id="gt-cookie-banner" class="fixed inset-x-4 bottom-4 z-[70] sm:inset-x-auto sm:right-6 sm:w-[420px] rounded-2xl border border-white/10 bg-slate-950/90 p-4 text-slate-200 shadow-xl backdrop-blur">
      <p class="text-sm font-semibold text-white">Cookie preferences</p>
      <p class="mt-2 text-xs text-slate-300">We use cookies to keep your session secure and improve platform experience. Choose your preference.</p>
      <div class="mt-4 grid grid-cols-2 gap-2">
        <button type="button" class="gt-btn gt-btn-primary px-4 py-2 text-sm" data-cookie-choice="accept">Accept</button>
        <button type="button" class="gt-btn gt-btn-muted px-4 py-2 text-sm" data-cookie-choice="reject">Reject</button>
      </div>
    </div>
    <script>
    (function () {
      var banner = document.getElementById('gt-cookie-banner');
      if (!banner) return;

      var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
      var nonce = <?php echo wp_json_encode($nonce); ?>;
      var isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
      var maxAge = 60 * 60 * 24 * 180;

      function setCookie(name, value, ttl) {
        document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + ttl + '; SameSite=Lax';
      }

      function saveMeta(choice) {
        if (!isLoggedIn) return;
        var body = new URLSearchParams();
        body.set('action', 'gigtune_cookie_consent');
        body.set('nonce', nonce);
        body.set('choice', choice);
        fetch(ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body.toString()
        }).catch(function () { return null; });
      }

      banner.querySelectorAll('[data-cookie-choice]').forEach(function (button) {
        button.addEventListener('click', function () {
          var choice = String(button.getAttribute('data-cookie-choice') || '').toLowerCase();
          if (choice !== 'accept' && choice !== 'reject') return;
          var ts = new Date().toISOString();
          setCookie('gt_cookie_consent', choice, maxAge);
          setCookie('gt_cookie_consent_at', ts, maxAge);
          saveMeta(choice);
          banner.remove();
        });
      });
    })();
    </script>
    <?php
}
add_action('wp_footer', 'gigtune_render_cookie_banner', 20);

function gigtune_canon_is_internal_pwa_request_path($path) {
    $path = '/' . ltrim((string) $path, '/');
    return in_array($path, ['/service-worker.js', '/manifest.webmanifest'], true);
}

function gigtune_canon_should_skip_404_redirect() {
    if (is_admin()) {
        return true;
    }
    if (wp_doing_ajax()) {
        return true;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    if (is_feed() || is_trackback() || is_preview()) {
        return true;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET') {
        return true;
    }

    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $request_path = '/' . ltrim($request_path, '/');
    if (strpos($request_path, '/wp-json') === 0 || $request_path === '/xmlrpc.php') {
        return true;
    }
    if (gigtune_canon_is_internal_pwa_request_path($request_path)) {
        return true;
    }
    return false;
}

function gigtune_canon_handle_404_redirect() {
    $enabled = defined('GIGTUNE_CANON_404_REDIRECT_ENABLED') ? (bool) GIGTUNE_CANON_404_REDIRECT_ENABLED : true;
    if (!$enabled || !is_404() || gigtune_canon_should_skip_404_redirect()) {
        return;
    }

    $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
    $request_path = '/' . ltrim($request_path, '/');

    $target_url = apply_filters('gigtune_canon_404_redirect_target', home_url('/'));
    $target_url = (string) wp_validate_redirect($target_url, home_url('/'));
    $redirect_url = add_query_arg('gt_not_found', '1', $target_url);

    if ($request_path !== '/' && !headers_sent()) {
        wp_safe_redirect($redirect_url, 302, 'GigTune Canon');
        exit;
    }
}
add_action('template_redirect', 'gigtune_canon_handle_404_redirect', 20);
