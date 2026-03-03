<?php if (!defined('ABSPATH')) exit; ?>

<footer class="bg-slate-950 border-t border-slate-900 pt-12 md:pt-16 pb-8">
  <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8 md:gap-12 mb-12">
    <div class="col-span-1 sm:col-span-2 md:col-span-1">
      <div class="flex items-center gap-3 mb-4">
        <img
          src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/gigtune-logo-bp.png'); ?>"
          alt="GigTune"
          class="object-contain rounded-md w-12 h-12"
          onerror="this.onerror=null;this.src='https://placehold.co/40x40/2563eb/ffffff?text=GT';"
        />
        <span class="text-lg font-bold text-white">GigTune</span>
      </div>
      <p class="text-slate-500 text-sm leading-relaxed">
        The professional operating system for live entertainment. Fit-based matching, secure Temporary Holding, and verified reliability.
      </p>
    </div>

    <div>
      <h4 class="text-white font-semibold mb-4">Platform</h4>
      <ul class="space-y-3 text-sm text-slate-400">
        <li><a href="<?php echo esc_url(home_url('/artists/')); ?>" class="hover:text-purple-400">Artist Directory</a></li>
        <li><a href="<?php echo esc_url(home_url('/how-it-works/')); ?>" class="hover:text-purple-400">How It Works</a></li>
        <li><a href="<?php echo esc_url(home_url('/pricing/')); ?>" class="hover:text-purple-400">Pricing</a></li>
        <li><a href="<?php echo esc_url(home_url('/open-posts/')); ?>" class="hover:text-purple-400">Open Posts</a></li>
      </ul>
    </div>

    <div>
      <h4 class="text-white font-semibold mb-4">Support</h4>
      <ul class="space-y-3 text-sm text-slate-400">
        <li><a href="<?php echo esc_url(home_url('/support-contact/')); ?>" class="hover:text-purple-400">Support / Contact</a></li>
      </ul>
    </div>

    <div>
      <h4 class="text-white font-semibold mb-4">Legal</h4>
      <ul class="space-y-3 text-sm text-slate-400">
        <li><a href="<?php echo esc_url(home_url('/acceptable-use-policy/')); ?>" class="hover:text-purple-400">Acceptable Use</a></li>
        <li><a href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" class="hover:text-purple-400">Privacy Policy</a></li>
        <li><a href="<?php echo esc_url(home_url('/cookie-policy/')); ?>" class="hover:text-purple-400">Cookie Policy</a></li>
        <li><a href="<?php echo esc_url(home_url('/terms-and-conditions/')); ?>" class="hover:text-purple-400">Terms &amp; Conditions</a></li>
        <li><a href="<?php echo esc_url(home_url('/return-policy/')); ?>" class="hover:text-purple-400">Refund Policy</a></li>
      </ul>
    </div>
  </div>

  <div class="max-w-7xl mx-auto px-4 border-t border-slate-900 pt-8 text-center text-white text-sm">
    &copy; <?php echo esc_html(date('Y')); ?> GigTune. All rights reserved. Powered by <a href="https://capital-iz.com/" class="text-red-400 hover:text-red-300">Capital-Iz</a>.
  </div>
</footer>
