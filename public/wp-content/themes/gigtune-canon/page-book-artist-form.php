<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Book Artist Form</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">Complete the details for your booking request.</p>
    </div>
    <div class="mt-8">
      <?php
        $availability_artist_id = isset($_GET['artist_id']) ? absint($_GET['artist_id']) : 0;
        if ($availability_artist_id > 0) {
          echo do_shortcode('[gigtune_artist_availability_summary artist_id="' . esc_attr((string) $availability_artist_id) . '" heading="Artist availability"]');
          echo '<div class="h-4"></div>';
        }
      ?>
      <?php echo do_shortcode('[gigtune_book_artist]'); ?>
    </div>
  </div>
</main>

<?php get_footer(); ?>
