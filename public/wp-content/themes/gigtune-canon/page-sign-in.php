<?php
/**
 * Template Name: GigTune - Sign In
 *
 * Slug: sign-in
 *
 * Renders the Gemini-style Sign In UI without WooCommerce.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
get_template_part('template-parts/navbar');

// If already logged in, send user to the correct dashboard.
if (is_user_logged_in()) {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    if (in_array('gigtune_artist', $roles, true)) {
        wp_safe_redirect(site_url('/artist-dashboard'));
        exit;
    }

    if (in_array('gigtune_client', $roles, true)) {
        wp_safe_redirect(site_url('/client-dashboard'));
        exit;
    }

    // Fallback
    wp_safe_redirect(site_url('/'));
    exit;
}


$redirect_to = get_permalink();
$join_url = site_url("/join");
?>


<main class="min-h-screen pt-20 pb-20 flex items-center justify-center px-4">
  <div class="w-full max-w-md">

    <div class="text-center mb-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-2">GigTune Sign In</h1>
      <p class="text-slate-400">Sign in to access your dashboard.</p>
    </div>

    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 md:p-8 border-t-4 border-t-purple-500">

      <?php if (!empty($_GET['login']) && $_GET['login'] === 'failed'): ?>
        <div class="mb-4 p-3 rounded-lg border border-red-500/20 bg-red-500/10 text-red-300 text-sm">
          Sign in failed. Please check your email and password.
        </div>
      <?php endif; ?>

      <form class="space-y-4" name="loginform" id="gigtune-login" action="<?php echo esc_url(wp_login_url()); ?>" method="post">
        <div>
          <label for="user_login" class="block text-sm font-medium text-slate-300 mb-1">Email Address</label>
          <input type="email" name="log" id="user_login" autocomplete="username" required
            class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white focus:border-purple-500 outline-none" />
        </div>
        <div>
          <label for="user_pass" class="block text-sm font-medium text-slate-300 mb-1">Password</label>
          <div class="relative">
            <input type="password" name="pwd" id="user_pass" autocomplete="current-password" required
              class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 pr-16 text-white focus:border-purple-500 outline-none" />
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md border border-slate-700 bg-slate-800 px-2 py-1 text-xs text-slate-200 hover:bg-slate-700" data-password-toggle="user_pass">Show</button>
          </div>
        </div>
        <div class="flex items-center justify-between">
          <label class="inline-flex items-center gap-2 text-sm text-slate-400">
            <input type="checkbox" name="rememberme" value="forever" class="rounded border-slate-600 bg-slate-900" />
            Remember me
          </label>
          <a class="text-sm text-slate-400 hover:text-white" href="<?php echo esc_url(wp_lostpassword_url()); ?>">Forgot?</a>
        </div>
        <input type="hidden" name="redirect_to" value="<?php echo esc_url($redirect_to); ?>" />
        <button type="submit" class="w-full mt-2 px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20">
          Sign In
        </button>
      </form>


      <div class="mt-6 text-center text-sm text-slate-400">
        Don&apos;t have an account?
        <a class="text-purple-400 hover:text-purple-300 font-medium" href="<?php echo esc_url(site_url('/join')); ?>">Get Started</a>
      </div>

    </div>

  </div>
</main>

<script>
(function(){
  var toggleButtons = document.querySelectorAll('[data-password-toggle]');
  toggleButtons.forEach(function(btn){
    btn.addEventListener('click', function(){
      var targetId = btn.getAttribute('data-password-toggle');
      var field = targetId ? document.getElementById(targetId) : null;
      if (!field) return;
      var isPassword = field.type === 'password';
      field.type = isPassword ? 'text' : 'password';
      btn.textContent = isPassword ? 'Hide' : 'Show';
    });
  });
})();
</script>

<?php get_footer(); ?>
