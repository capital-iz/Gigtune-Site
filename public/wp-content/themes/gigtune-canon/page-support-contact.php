<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Support / Contact</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">Get help fast — we’ll point you to the right place.</p>
    </div>    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
        <div class="text-white font-bold mb-2">Email Support</div>
        <div class="text-slate-400 text-sm">Send a ticket and we’ll respond.</div>
        <div class="mt-4">
          <a class="text-purple-400 hover:text-purple-300" href="mailto:support@gigtune.africa">support@gigtune.africa</a>
        </div>
      </div>
      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6">
        <div class="text-white font-bold mb-2">Urgent Booking Issue</div>
        <div class="text-slate-400 text-sm">If a live booking needs attention, include your booking ID.</div>
      </div>
    </div>

    <?php if (have_posts()): while (have_posts()): the_post(); ?>
      <article class="prose prose-invert max-w-none">
        <?php the_content(); ?>
      </article>
    <?php endwhile; endif; ?>

  </div>
</main>

<?php get_footer(); ?>
