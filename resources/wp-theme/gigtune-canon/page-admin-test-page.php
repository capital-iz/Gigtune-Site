<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Admin Reliability Test</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">System diagnostics for admins.</p>
    </div>    <?php if (!current_user_can('manage_options')): ?>
      <div class="bg-red-500/10 border border-red-500/20 text-red-300 rounded-xl p-6">
        <div class="font-bold mb-2">Restricted</div>
        <div class="text-sm">You need administrator access to view this page.</div>
      </div>
    <?php else: ?>
      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 md:p-8 space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="bg-slate-900/40 border border-slate-700/50 rounded-lg p-4">
            <div class="text-xs text-slate-500 uppercase mb-1">PHP Version</div>
            <div class="text-white font-bold"><?php echo esc_html(PHP_VERSION); ?></div>
          </div>
          <div class="bg-slate-900/40 border border-slate-700/50 rounded-lg p-4">
            <div class="text-xs text-slate-500 uppercase mb-1">WP Version</div>
            <div class="text-white font-bold"><?php echo esc_html(get_bloginfo('version')); ?></div>
          </div>
          <div class="bg-slate-900/40 border border-slate-700/50 rounded-lg p-4">
            <div class="text-xs text-slate-500 uppercase mb-1">Theme</div>
            <div class="text-white font-bold"><?php $t = wp_get_theme(); echo esc_html($t->get('Name').' '.$t->get('Version')); ?></div>
          </div>
        </div>

        <div class="bg-slate-900/40 border border-slate-700/50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase mb-2">GigTune REST Check</div>
          <?php
            $endpoint = home_url('/wp-json/gigtune/v1/psas');
            $resp = wp_remote_get($endpoint, ['timeout' => 8]);
            if (is_wp_error($resp)) {
              echo '<div class="text-red-300 text-sm">Request failed: '.esc_html($resp->get_error_message()).'</div>';
            } else {
              $code = wp_remote_retrieve_response_code($resp);
              echo '<div class="text-slate-300 text-sm">GET '.esc_html($endpoint).' → HTTP '.esc_html($code).'</div>';
              echo '<div class="text-xs text-slate-500 mt-2">Tip: 401 is expected if the endpoint requires authentication.</div>';
            }
          ?>
        </div>

        <div class="bg-slate-900/40 border border-slate-700/50 rounded-lg p-4">
          <div class="text-xs text-slate-500 uppercase mb-2">Shortcode Render Check</div>
          <div class="text-slate-300 text-sm">If you see UI below, the UI renderer hook is active:</div>
          <div class="mt-4">
            <?php echo do_shortcode('[gigtune_notifications_bell]'); ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

<?php get_footer(); ?>
