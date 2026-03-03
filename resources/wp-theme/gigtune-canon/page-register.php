<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="max-w-md mx-auto px-4 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Register</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">Create your WooCommerce account.</p>
    </div>    <div class="mt-8">
      <?php echo do_shortcode('[woocommerce_my_account]'); ?>
    </div>
  </div>
</main>

<?php get_footer(); ?>
