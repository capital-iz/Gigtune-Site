<?php
/**
 * Template Name: GigTune - Join
 *
 * Slug: join
 *
 * Renders the Gemini-style Join UI without WooCommerce.
 * Uses GigTune-Core registration handler (gigtune_handle_registration).
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

    wp_safe_redirect(site_url('/'));
    exit;
}

$redirect_login = site_url('/sign-in');
$register_error = isset($_GET['register_error']) ? sanitize_key((string) $_GET['register_error']) : '';
$register_error_msg = isset($_GET['register_error_msg']) ? sanitize_text_field((string) wp_unslash($_GET['register_error_msg'])) : '';
$default_error_msg = 'Please review the highlighted fields and try again.';
$flash_token = isset($_GET['gtf']) ? sanitize_key((string) $_GET['gtf']) : '';
$flash = function_exists('gigtune_form_flash_get') ? gigtune_form_flash_get('join_register', $flash_token) : [];
$flash_values = (isset($flash['values']) && is_array($flash['values'])) ? $flash['values'] : [];
$flash_errors = (isset($flash['errors']) && is_array($flash['errors'])) ? $flash['errors'] : [];
$first_error_field = isset($flash['first_error_field']) ? sanitize_key((string) $flash['first_error_field']) : '';
$prefill_role = isset($flash_values['gigtune_role']) ? sanitize_key((string) $flash_values['gigtune_role']) : 'gigtune_client';
if (!in_array($prefill_role, ['gigtune_client', 'gigtune_artist'], true)) {
    $prefill_role = 'gigtune_client';
}
$prefill_name = isset($flash_values['gigtune_full_name']) ? sanitize_text_field((string) $flash_values['gigtune_full_name']) : '';
$prefill_email = isset($flash_values['gigtune_email']) ? sanitize_email((string) $flash_values['gigtune_email']) : '';
$prefill_terms = !empty($flash_values['gigtune_terms_acceptance']) || !empty($flash_values['gigtune_accept_terms']);
$render_error_msg = $register_error_msg !== '' ? $register_error_msg : (!empty($flash_errors) ? sanitize_text_field((string) reset($flash_errors)) : $default_error_msg);
?>

<main class="min-h-screen pt-20 pb-20 flex items-center justify-center px-4">
  <div class="w-full max-w-md">

    <div class="text-center mb-8">
      <h1 class="text-3xl md:text-4xl font-bold text-white mb-2">Join GigTune</h1>
      <p class="text-slate-400">The professional operating system for live entertainment.</p>
    </div>

    <div class="bg-slate-800/50 backdrop-blur-sm border border-slate-700/50 rounded-xl p-6 md:p-8 border-t-4 border-t-purple-500">

      <form class="space-y-4" method="post" action="">
        <?php if ($register_error === '1'): ?>
          <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
            <?php echo esc_html($render_error_msg); ?>
          </div>
        <?php endif; ?>
        <div id="gtJoinInlineError" class="hidden rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200"></div>

        <div class="grid grid-cols-2 gap-2 mb-2 p-1 bg-slate-900 rounded-lg">
          <button type="button" id="gtRoleClient" class="py-2 text-sm font-medium rounded-md transition-all bg-slate-700 text-white shadow">I'm a Client</button>
          <button type="button" id="gtRoleArtist" class="py-2 text-sm font-medium rounded-md transition-all text-slate-500 hover:text-slate-300">I'm an Artist</button>
        </div>

        <input type="hidden" name="gigtune_role" id="gigtune_role" value="<?php echo esc_attr($prefill_role); ?>" />

        <div>
          <label for="gigtune_full_name" class="block text-sm font-medium text-slate-300 mb-1">Full Name (Name &amp; Surname)</label>
          <input type="text" name="gigtune_full_name" id="gigtune_full_name" required
            value="<?php echo esc_attr($prefill_name); ?>"
            class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white focus:border-purple-500 outline-none" />
        </div>

        <div>
          <label for="gigtune_email" class="block text-sm font-medium text-slate-300 mb-1">Email Address</label>
          <input type="email" name="gigtune_email" id="gigtune_email" autocomplete="email" required
            value="<?php echo esc_attr($prefill_email); ?>"
            class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 text-white focus:border-purple-500 outline-none" />
        </div>

        <div>
          <label for="gigtune_password" class="block text-sm font-medium text-slate-300 mb-1">Password</label>
          <div class="relative">
            <input type="password" name="gigtune_password" id="gigtune_password" autocomplete="new-password" required
              class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 pr-16 text-white focus:border-purple-500 outline-none" />
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md border border-slate-700 bg-slate-800 px-2 py-1 text-xs text-slate-200 hover:bg-slate-700" data-password-toggle="gigtune_password">Show</button>
          </div>
        </div>

        <div>
          <label for="gigtune_password_confirm" class="block text-sm font-medium text-slate-300 mb-1">Confirm Password</label>
          <div class="relative">
            <input type="password" name="gigtune_password_confirm" id="gigtune_password_confirm" autocomplete="new-password" required
              class="w-full bg-slate-900 border border-slate-700 rounded-lg p-3 pr-16 text-white focus:border-purple-500 outline-none" />
            <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md border border-slate-700 bg-slate-800 px-2 py-1 text-xs text-slate-200 hover:bg-slate-700" data-password-toggle="gigtune_password_confirm">Show</button>
          </div>
        </div>

        <label class="flex items-start gap-2 text-sm text-slate-300">
          <input type="checkbox" name="gigtune_terms_acceptance" value="1" required <?php checked($prefill_terms); ?> class="mt-0.5 rounded border-slate-600 bg-slate-900 text-purple-500 focus:ring-purple-500" />
          <span>I agree to the <a class="text-purple-300 hover:text-purple-200 underline" href="<?php echo esc_url(home_url('/terms-and-conditions/')); ?>" target="_blank" rel="noopener">Terms</a>, <a class="text-purple-300 hover:text-purple-200 underline" href="<?php echo esc_url(home_url('/acceptable-use-policy/')); ?>" target="_blank" rel="noopener">Acceptable Use Policy</a>, <a class="text-purple-300 hover:text-purple-200 underline" href="<?php echo esc_url(home_url('/privacy-policy/')); ?>" target="_blank" rel="noopener">Privacy Policy</a>, and <a class="text-purple-300 hover:text-purple-200 underline" href="<?php echo esc_url(home_url('/return-policy/')); ?>" target="_blank" rel="noopener">Return Policy</a>.</span>
        </label>

        <?php wp_nonce_field('gigtune_register_action', 'gigtune_register_nonce'); ?>

        <button type="submit" name="gigtune_register_submit" value="1" id="gtJoinButton"
          class="w-full mt-2 px-6 py-3 rounded-lg font-medium transition-all duration-200 flex items-center justify-center gap-2 active:scale-95 touch-manipulation bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white shadow-lg shadow-purple-900/20">
          Join as Client
        </button>

      </form>

      <div class="mt-6 text-center text-sm text-slate-400">
        Already have an account?
        <a class="text-purple-400 hover:text-purple-300 font-medium" href="<?php echo esc_url($redirect_login); ?>">Log In</a>
      </div>

    </div>
  </div>
</main>

<script>
(function(){
  var btnClient = document.getElementById('gtRoleClient');
  var btnArtist = document.getElementById('gtRoleArtist');
  var roleInput = document.getElementById('gigtune_role');
  var joinBtn = document.getElementById('gtJoinButton');
  var joinForm = document.querySelector('form[method="post"]');
  var fullNameInput = document.getElementById('gigtune_full_name');
  var emailInput = document.getElementById('gigtune_email');
  var passwordInput = document.getElementById('gigtune_password');
  var confirmInput = document.getElementById('gigtune_password_confirm');
  var inlineError = document.getElementById('gtJoinInlineError');
  var toggleButtons = document.querySelectorAll('[data-password-toggle]');

  if(!btnClient || !btnArtist || !roleInput || !joinBtn) return;

  function setRole(role){
    var isClient = role === 'gigtune_client';
    roleInput.value = role;

    btnClient.className = 'py-2 text-sm font-medium rounded-md transition-all ' + (isClient ? 'bg-slate-700 text-white shadow' : 'text-slate-500 hover:text-slate-300');
    btnArtist.className = 'py-2 text-sm font-medium rounded-md transition-all ' + (!isClient ? 'bg-slate-700 text-white shadow' : 'text-slate-500 hover:text-slate-300');

    joinBtn.textContent = isClient ? 'Join as Client' : 'Join as Artist';
  }

  btnClient.addEventListener('click', function(){ setRole('gigtune_client'); });
  btnArtist.addEventListener('click', function(){ setRole('gigtune_artist'); });
  setRole('<?php echo esc_js($prefill_role); ?>');

  function setInlineError(message) {
    if (!inlineError) return;
    if (!message) {
      inlineError.classList.add('hidden');
      inlineError.textContent = '';
      return;
    }
    inlineError.textContent = message;
    inlineError.classList.remove('hidden');
  }

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

  if (joinForm && fullNameInput && emailInput && passwordInput && confirmInput) {
    joinForm.addEventListener('submit', function(event){
      setInlineError('');
      var fullName = String(fullNameInput.value || '').trim();
      var email = String(emailInput.value || '').trim();
      var pass = String(passwordInput.value || '');
      var confirm = String(confirmInput.value || '');
      var words = fullName.split(/\s+/).filter(function(part){ return part.length > 0; });
      var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

      if (emailPattern.test(fullName)) {
        event.preventDefault();
        setInlineError('Full name cannot be an email address.');
        return;
      }
      if (words.length < 2) {
        event.preventDefault();
        setInlineError('Please enter your name and surname.');
        return;
      }
      if (pass !== confirm) {
        event.preventDefault();
        setInlineError('Password and confirm password must match.');
        return;
      }
      if (email === '') {
        event.preventDefault();
        setInlineError('Email address is required.');
      }
    });
  }

  var firstErrorField = '<?php echo esc_js($first_error_field); ?>';
  if (firstErrorField) {
    var target = document.getElementById(firstErrorField) || document.querySelector('[name="' + firstErrorField + '"]');
    if (target) {
      try { target.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
      try { target.focus(); } catch (e2) {}
    }
  }
})();
</script>

<?php get_footer(); ?>
