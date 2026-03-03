<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="max-w-3xl mx-auto px-4 py-24 text-center">
    <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-8">
      <h1 class="text-2xl font-bold text-white mb-3">Signing you out…</h1>
      <p class="text-slate-400 mb-6">Please wait.</p>
    </div>
  </div>
<?php
  if (is_user_logged_in()) {
    wp_logout();
  }
  wp_safe_redirect(home_url('/'));
  exit;
?>
</main>

<?php get_footer(); ?>
