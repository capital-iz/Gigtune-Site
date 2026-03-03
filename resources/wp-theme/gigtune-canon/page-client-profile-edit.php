<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Edit Client Profile</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl">Update your account profile details used in booking and communication.</p>
    </div>
    <div class="mt-8">
      <?php
        $portal_html = do_shortcode('[gigtune_account_portal]');
        if (trim((string) $portal_html) === '') {
          error_log('GT THEME: client profile edit portal shortcode returned empty output');
          echo '<div class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">Client profile editor is temporarily unavailable.</div>';
        } else {
          echo $portal_html;
        }
      ?>
    </div>
  </div>
</main>

<?php get_footer(); ?>
