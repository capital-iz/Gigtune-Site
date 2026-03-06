<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Bookings Archive</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl">Archived bookings are stored here for clean dashboard organization.</p>
    </div>

    <div class="mt-8">
      <?php if (is_user_logged_in()): ?>
        <?php
          $user = wp_get_current_user();
          $roles = ($user instanceof WP_User) ? (array) $user->roles : [];
          $is_artist = in_array('gigtune_artist', $roles, true);
          $is_client = in_array('gigtune_client', $roles, true);
          $is_admin = in_array('administrator', $roles, true) || in_array('gigtune_admin', $roles, true) || in_array('gts_admin', $roles, true);
        ?>

        <?php if ($is_admin): ?>
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">
            Admin booking management is available on <a class="underline" href="/admin-dashboard/bookings">/admin-dashboard/bookings</a>.
          </div>
        <?php elseif ($is_artist): ?>
          <?php echo do_shortcode('[gigtune_artist_bookings_archive]'); ?>
        <?php elseif ($is_client): ?>
          <?php echo do_shortcode('[gigtune_client_bookings_archive]'); ?>
        <?php else: ?>
          <div class="rounded-xl border border-white/10 bg-white/5 p-4 text-sm text-slate-200">
            No role-specific booking archive was found for this account.
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="rounded-xl border border-slate-700 bg-slate-900/70 px-4 py-3 text-sm text-slate-300">Sign in required.</div>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php get_footer(); ?>
