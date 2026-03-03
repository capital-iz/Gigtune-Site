<?php
/**
 * Identity Verification page template.
 */
if (!defined('ABSPATH')) exit;

get_header();
get_template_part('template-parts/navbar');
?>

<main class="flex-1">
  <div class="w-full px-4 sm:px-6 lg:px-10 xl:px-14 2xl:px-16 py-12 space-y-6">
    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white">Identity Verification (Know Your Customer Compliance)</h1>
      <p class="mt-3 text-slate-300 text-base md:text-lg">Submit verification details to unlock booking requests, artist acceptance, and payouts.</p>
      <p class="mt-2 text-sm text-slate-400">Learn more in the <a class="text-blue-300 hover:text-blue-200 underline" href="<?php echo esc_url(home_url('/privacy-policy/#privacy-kyc-data')); ?>">Privacy Policy Identity Verification section</a>.</p>
    </section>

    <section class="rounded-2xl border border-white/10 bg-white/5 p-6 md:p-8">
      <?php echo do_shortcode('[gigtune_kyc_form]'); ?>
    </section>
  </div>
</main>

<?php get_footer(); ?>
