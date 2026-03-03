<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 ">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">About Us</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl ">Building the operating system for live entertainment.</p>
    </div>    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 md:p-8">
      <div class="prose prose-invert max-w-none text-slate-300">
        <p>GigTune is a fit-based marketplace designed to solve two core problems in live entertainment: client uncertainty and artist reliability.</p>
        <p>We combine verified profiles, transparent reliability signals, and Temporary Holding-style payment protection so clients can book with confidence — and artists can get paid without chasing.</p>
        <p>Our mission is simple: professionalise the industry, reduce friction, and make every booking predictable.</p>
      </div>
    </div>
  </div>
</main>

<?php get_footer(); ?>
