<?php
/**
 * Admin maintenance page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Admin Maintenance</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">Restricted operations for system administrators.</p>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <?php
        $maintenance_html = do_shortcode('[gigtune_admin_maintenance]');
        $maintenance_rendered = trim((string) $maintenance_html);
        if ($maintenance_rendered === '' || strpos($maintenance_rendered, '[gigtune_admin_maintenance]') !== false) {
          error_log('GT THEME: admin maintenance shortcode returned empty output');
          echo '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Admin maintenance is temporarily unavailable.</div>';
        } else {
          echo $maintenance_html;
        }
      ?>
    </section>
  </div>
</main>

<?php get_footer(); ?>
