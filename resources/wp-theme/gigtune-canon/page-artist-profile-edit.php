<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Edit Artist Profile</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">Update your profile, media, and settings.</p>
    </div>    <div class="mt-8">
      <?php echo do_shortcode('[gigtune_artist_profile_edit]'); ?>
    </div>
  </div>
</main>

<?php get_footer(); ?>
