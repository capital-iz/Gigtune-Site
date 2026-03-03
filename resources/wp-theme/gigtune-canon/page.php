<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1 w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
  <?php if (have_posts()): while (have_posts()): the_post(); ?>
    <article class="prose prose-invert max-w-none">
      <h1 class="text-3xl font-bold text-white"><?php the_title(); ?></h1>
      <div class="mt-6"><?php the_content(); ?></div>
    </article>
  <?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
