<?php if (!defined('ABSPATH')) exit; ?>
<?php get_header(); ?>
<?php get_template_part('template-parts/navbar'); ?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12">
    <div class="mb-10 text-center">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-3">How GigTune Works</h1>
      <p class="text-slate-400 text-base md:text-lg max-w-2xl mx-auto">A secure booking flow that protects both clients and artists.</p>
    </div>    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 md:p-8">
        <h2 class="text-2xl font-bold text-white mb-6">For Clients</h2>
        <ol class="space-y-5 border-l border-slate-700 pl-6">
          <li>
            <div class="text-white font-semibold">1) Discover & Match</div>
            <div class="text-slate-400">Browse verified artists and filter by date, location, and budget.</div>
          </li>
          <li>
            <div class="text-white font-semibold">2) Secure Booking</div>
            <div class="text-slate-400">Send a request and secure payment once accepted.</div>
          </li>
          <li>
            <div class="text-white font-semibold">3) Enjoy the Show</div>
            <div class="text-slate-400">Funds are released after the performance window if no disputes are raised.</div>
          </li>
        </ol>
      </div>

      <div class="bg-slate-800/50 border border-slate-700/50 rounded-xl p-6 md:p-8">
        <h2 class="text-2xl font-bold text-white mb-6">For Artists</h2>
        <ol class="space-y-5 border-l border-slate-700 pl-6">
          <li>
            <div class="text-white font-semibold">1) Create Your Profile</div>
            <div class="text-slate-400">Showcase your sound, rates, and availability.</div>
          </li>
          <li>
            <div class="text-white font-semibold">2) Get Qualified Requests</div>
            <div class="text-slate-400">Receive booking requests aligned with your style and rules.</div>
          </li>
          <li>
            <div class="text-white font-semibold">3) Guaranteed Payment</div>
            <div class="text-slate-400">Client funds are secured before the gig is confirmed.</div>
          </li>
        </ol>
      </div>
    </div>
  </div>
</main>

<?php get_footer(); ?>
