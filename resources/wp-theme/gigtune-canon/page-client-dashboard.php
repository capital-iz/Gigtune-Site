<?php
/**
 * Template Name: Client Dashboard (GigTune)
 * File: page-client-dashboard.php
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>
<main class="min-h-[70vh] bg-slate-950 text-white">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="text-center">
      <h1 class="text-4xl sm:text-5xl font-bold tracking-tight">Client Dashboard</h1>
      <p class="mt-4 text-slate-300 text-lg">Track bookings, Temporary Holding, and messages.</p>
    </div>

    <div class="mt-10">
      <?php
        // Always attempt to render. Some GigTune shortcodes echo via the gigtune_ui_render action and return ''.
        $before = ob_get_level();
        ob_start();
        echo do_shortcode('[gigtune_client_dashboard]');
        $buffer = ob_get_clean();

        // If the shortcode doesn't exist, WordPress leaves it as text. Detect that and show a clear notice.
        if (strpos($buffer, '[gigtune_client_dashboard]') !== false) {
          echo '<div class="rounded-xl border border-white/10 bg-white/5 p-6 text-slate-200">'
            . '<p class="font-semibold">Dashboard unavailable</p>'
            . '<p class="mt-2 text-sm text-slate-300">The shortcode <code class="px-2 py-1 rounded bg-black/30">[gigtune_client_dashboard]</code> is not registered. Please ensure the GigTune Core plugin is active and the shortcode is defined.</p>'
            . '</div>';
        } else {
          echo $buffer;
        }
      ?>
    </div>
  </div>
</main>
<?php get_footer(); ?>
