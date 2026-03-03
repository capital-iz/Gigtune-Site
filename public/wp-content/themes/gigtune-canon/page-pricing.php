<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 text-center">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">Transparent Pricing</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl mx-auto">No hidden fees. We only earn when you succeed.</p>
    </div>    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto">
      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 md:p-8 flex flex-col">
        <div class="text-xs font-bold text-blue-400 uppercase tracking-wider mb-3">For Clients</div>
        <h2 class="text-2xl font-bold text-white mb-2">Booking Fee</h2>
        <div class="text-4xl font-bold text-white mb-6">10% <span class="text-lg text-slate-500 font-normal">per booking</span></div>
        <ul class="space-y-3 text-slate-300 mb-8 flex-1">
          <li>• Temporary Holding-style payment protection</li>
          <li>• Verified artist signals & reliability</li>
          <li>• Support for disputes and cancellations</li>
        </ul>
        <a href="<?php echo esc_url(home_url('/artists/')); ?>" class="inline-flex items-center justify-center px-6 py-3 rounded-lg font-medium bg-gradient-to-r from-blue-600 to-purple-600 text-white">
          Start Booking
        </a>
      </div>

      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 md:p-8 flex flex-col">
        <div class="text-xs font-bold text-purple-400 uppercase tracking-wider mb-3">For Artists</div>
        <h2 class="text-2xl font-bold text-white mb-2">Service Fee</h2>
        <div class="text-4xl font-bold text-white mb-6">5% <span class="text-lg text-slate-500 font-normal">per gig</span></div>
        <ul class="space-y-3 text-slate-300 mb-8 flex-1">
          <li>• Faster, predictable payouts</li>
          <li>• Profile hosting & discovery</li>
          <li>• Professional booking workflow</li>
        </ul>
        <a href="<?php echo esc_url(home_url('/join/')); ?>" class="inline-flex items-center justify-center px-6 py-3 rounded-lg font-medium bg-slate-800 hover:bg-slate-700 text-white border border-slate-700">
          Join as Artist
        </a>
      </div>
    </div>
  </div>
</main>

<?php get_footer(); ?>
