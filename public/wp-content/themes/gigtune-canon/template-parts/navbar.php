<?php
/**
 * Template part: Navbar
 * Location: /template-parts/navbar.php
 */
if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$is_logged_in = is_user_logged_in();

// Determine dashboard URL based on GigTune roles.
$dashboard_url   = home_url('/');
$dashboard_label = 'Dashboard';

if ($is_logged_in && $current_user instanceof WP_User) {
  $roles = (array) $current_user->roles;

  if (in_array('administrator', $roles, true)) {
    $dashboard_url   = home_url('/admin-dashboard/');
    $dashboard_label = 'Admin Dashboard';
  } elseif (in_array('gigtune_artist', $roles, true)) {
    $dashboard_url   = home_url('/artist-dashboard/');
    $dashboard_label = 'Artist Dashboard';
  } elseif (in_array('gigtune_client', $roles, true)) {
    $dashboard_url   = home_url('/client-dashboard/');
    $dashboard_label = 'Client Dashboard';
  } else {
    // Fallback for any other role
    $dashboard_url   = home_url('/my-account-page/');
    $dashboard_label = 'My Account';
  }
}

$signin_url = home_url('/sign-in/');
$join_url   = home_url('/join/');
$logout_url = wp_logout_url(home_url('/'));

// Header icon.
$logo_url = trailingslashit(get_stylesheet_directory_uri()) . 'assets/img/gigtune-icon-bg.png';
?>
<nav class="sticky top-0 z-50 bg-slate-900/90 backdrop-blur-md border-b border-slate-800">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex justify-between h-16 items-center">

      <!-- Logo -->
      <a href="<?php echo esc_url(home_url('/')); ?>" class="flex items-center gap-3">
        <img
          src="<?php echo esc_url($logo_url); ?>"
          alt="GigTune"
          class="w-10 h-10 object-contain rounded-md"
          onerror="this.onerror=null; this.src='https://placehold.co/40x40/2563eb/ffffff?text=GT';"
        />
        <span class="text-xl font-bold text-white tracking-tight">GigTune</span>
      </a>

      <!-- Desktop Nav -->
      <div class="hidden md:flex items-center gap-6 lg:gap-8">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2 md:py-0">Home</a>
        <a href="<?php echo esc_url(home_url('/artists/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2 md:py-0">Discover Artists</a>
        <a href="<?php echo esc_url(home_url('/how-it-works/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2 md:py-0">How it Works</a>
        <a href="<?php echo esc_url(home_url('/pricing/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2 md:py-0">Pricing</a>

        <div class="h-6 w-px bg-slate-700 mx-2"></div>

        <?php if ($is_logged_in): ?>
          <a href="<?php echo esc_url($dashboard_url); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2 md:py-0">
            <?php echo esc_html($dashboard_label); ?>
          </a>

          <div class="relative gt-nav-group">
            <a href="<?php echo esc_url(home_url('/notifications/')); ?>" class="relative text-slate-300 hover:text-white p-2" aria-label="Notifications">
              <?php
                // Optional notifications bell from GigTune-Core if it exists.
                if (shortcode_exists('gigtune_notifications_bell')) {
                  echo do_shortcode('[gigtune_notifications_bell]');
                } else {
                  // Fallback bell icon (SVG) to keep styling consistent
                  echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2c0 .53-.21 1.04-.6 1.4L4 17h5m6 0a3 3 0 0 1-6 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                }
              ?>
            </a>
            <div class="gt-nav-dropdown">
              <a href="<?php echo esc_url(home_url('/open-posts/')); ?>" class="block px-4 py-2 text-sm text-slate-200 hover:text-white">Open Posts</a>
            </div>
          </div>

          <a href="<?php echo esc_url($logout_url); ?>" class="text-slate-400 hover:text-white p-2" aria-label="Logout">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M10 17l5-5-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              <path d="M21 21V3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </a>
        <?php else: ?>
          <a href="<?php echo esc_url($signin_url); ?>" class="text-slate-300 hover:text-white font-medium">Sign In</a>
          <a href="<?php echo esc_url($join_url); ?>" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 transition shadow">
            Get Started
          </a>
        <?php endif; ?>
      </div>

      <!-- Mobile Menu Button -->
      <div class="md:hidden flex items-center gap-1">
        <button type="button" class="text-slate-300 p-2 focus:outline-none" aria-controls="gt-mobile-nav" aria-expanded="false" id="gtMobileNavBtn">
          <span class="sr-only">Toggle menu</span>
          <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
        </button>
      </div>
    </div>
  </div>

  <!-- Mobile menu -->
  <div class="md:hidden hidden bg-slate-900 border-b border-slate-800" id="gt-mobile-nav">
    <div class="px-4 pt-4 pb-6 space-y-4 flex flex-col">
      <a href="<?php echo esc_url(home_url('/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">Home</a>
      <a href="<?php echo esc_url(home_url('/artists/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">Discover Artists</a>
      <a href="<?php echo esc_url(home_url('/how-it-works/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">How it Works</a>
      <a href="<?php echo esc_url(home_url('/pricing/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">Pricing</a>

      <div class="border-t border-slate-800 pt-4 mt-2 flex flex-col gap-3">
        <?php if ($is_logged_in): ?>
          <a href="<?php echo esc_url($dashboard_url); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">
            <?php echo esc_html($dashboard_label); ?>
          </a>
          <a href="<?php echo esc_url(home_url('/notifications/')); ?>" class="text-sm font-medium text-slate-300 hover:text-white transition-colors py-2">Notifications</a>
          <a href="<?php echo esc_url($logout_url); ?>" class="text-left text-red-400 py-3 font-medium border-t border-slate-800 mt-2">Sign Out</a>
        <?php else: ?>
          <a href="<?php echo esc_url($signin_url); ?>" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 transition w-full">Sign In</a>
          <a href="<?php echo esc_url($join_url); ?>" class="inline-flex items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold text-white bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 transition shadow w-full">Get Started</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<script>
(function(){
  var btn = document.getElementById('gtMobileNavBtn');
  var menu = document.getElementById('gt-mobile-nav');
  if(!btn || !menu) return;
  btn.addEventListener('click', function(){
    var isHidden = menu.classList.contains('hidden');
    menu.classList.toggle('hidden', !isHidden);
    btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
  });
})();
</script>
