<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="min-h-[calc(100vh-300px)] bg-slate-950 text-slate-200 font-sans">
  <section class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-16 md:py-24">
    <div class="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-white/5 p-6 md:p-10 text-center">
      <div class="inline-flex items-center rounded-full border border-rose-400/30 bg-rose-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-rose-200">
        404 Not Found
      </div>
      <h1 class="mt-4 text-3xl md:text-5xl font-bold text-white">This page does not exist</h1>
      <p class="mt-4 text-sm md:text-base text-slate-300">
        The link may be outdated or the page may have moved. You can continue from the homepage or browse artists.
      </p>
      <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500">
          Go to Home
        </a>
        <a href="<?php echo esc_url(home_url('/artists/')); ?>" class="inline-flex items-center justify-center rounded-xl px-5 py-3 text-sm font-semibold text-white bg-white/10 hover:bg-white/15">
          Discover Artists
        </a>
      </div>
    </div>
  </section>
</main>

<?php get_footer(); ?>
