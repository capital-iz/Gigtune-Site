<?php
/**
 * Template Name: Artist Dashboard (GigTune)
 * File: page-artist-dashboard.php
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');

?>
<main class="min-h-[70vh] bg-slate-950 text-white">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="text-center">
      <h1 class="text-4xl sm:text-5xl font-bold tracking-tight">Artist Dashboard</h1>
      <p class="mt-4 text-slate-300 text-lg">Manage gigs, calendar, and profile.</p>
    </div>

    <div class="mt-10">
      <?php
        // Render the GigTune core dashboard if available.
        if (shortcode_exists('gigtune_artist_dashboard')) {
          echo do_shortcode('[gigtune_artist_dashboard]');
        } else {
          echo '<div class="rounded-xl border border-white/10 bg-white/5 p-6 text-slate-200">'
            . '<p class="font-semibold">Dashboard unavailable</p>'
            . '<p class="mt-2 text-sm text-slate-300">The shortcode <code class="px-2 py-1 rounded bg-black/30">[gigtune_artist_dashboard]</code> is not registered. Please ensure the GigTune Core plugin is active and the shortcode is defined.</p>'
            . '</div>';
        }
      ?>
    </div>
  </div>
</main>
<?php get_footer(); ?>
