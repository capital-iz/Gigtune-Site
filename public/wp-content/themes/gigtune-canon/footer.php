<?php if (!defined('ABSPATH')) exit; ?>

<?php get_template_part('template-parts/footer'); ?>

<script>
/**
 * GigTune Canon Theme - minimal JS for mobile menu toggle
 * (replaces React state toggle from the Gemini demo)
 */
(function() {
  var btn = document.getElementById('gigtuneMobileMenuBtn');
  var panel = document.getElementById('gigtuneMobileMenuPanel');
  var iconOpen = document.getElementById('gigtuneIconOpen');
  var iconClose = document.getElementById('gigtuneIconClose');

  if (!btn || !panel) return;

  function setOpen(isOpen) {
    if (isOpen) {
      panel.classList.remove('hidden');
      panel.setAttribute('aria-hidden', 'false');
      if (iconOpen) iconOpen.classList.add('hidden');
      if (iconClose) iconClose.classList.remove('hidden');
      btn.setAttribute('aria-expanded', 'true');
    } else {
      panel.classList.add('hidden');
      panel.setAttribute('aria-hidden', 'true');
      if (iconOpen) iconOpen.classList.remove('hidden');
      if (iconClose) iconClose.classList.add('hidden');
      btn.setAttribute('aria-expanded', 'false');
    }
  }

  // default closed
  setOpen(false);

  btn.addEventListener('click', function() {
    var isHidden = panel.classList.contains('hidden');
    setOpen(isHidden);
  });

  // close on any menu link click
  panel.addEventListener('click', function(e) {
    var target = e.target;
    if (target && target.tagName === 'A') {
      setOpen(false);
    }
  });
})();

(function() {
  function openPicker(input) {
    if (!input || input.disabled || input.readOnly) return;
    if (typeof input.showPicker === 'function') {
      try { input.showPicker(); } catch (e) {}
    }
  }

  function bindInput(input) {
    if (!input || input.dataset.gtPickerBound === '1') return;
    input.dataset.gtPickerBound = '1';
    var open = function() { openPicker(input); };
    input.addEventListener('focus', open);
    input.addEventListener('click', open);
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') open();
    });
  }

  function init(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var inputs = scope.querySelectorAll('input[type="date"],input[type="datetime-local"],input[type="time"],input[type="month"]');
    inputs.forEach(bindInput);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { init(document); });
  } else {
    init(document);
  }

  if (typeof MutationObserver === 'function') {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        mutation.addedNodes.forEach(function(node) {
          if (!node || node.nodeType !== 1) return;
          if (node.matches && node.matches('input[type="date"],input[type="datetime-local"],input[type="time"],input[type="month"]')) {
            bindInput(node);
          }
          if (node.querySelectorAll) init(node);
        });
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }
})();
</script>

<?php
$gtLiveUser = wp_get_current_user();
$gtLiveUserId = (int) ($gtLiveUser->ID ?? 0);
$gtLiveRoles = is_array($gtLiveUser->roles ?? null) ? $gtLiveUser->roles : [];
$gtLiveIsAdmin = in_array('administrator', $gtLiveRoles, true);
?>
<script>
window.GigTuneLiveConfig = Object.assign({}, window.GigTuneLiveConfig || {}, {
  appId: 'gigtune-main',
  appName: 'GigTune',
  installEnabled: true,
  installPromptLabel: 'Install GigTune App',
  alertsToggleLabel: 'Enable Instant Alerts',
  notificationsEnabled: <?php echo $gtLiveUserId > 0 ? 'true' : 'false'; ?>,
  userId: <?php echo (int) $gtLiveUserId; ?>,
  isAdmin: <?php echo $gtLiveIsAdmin ? 'true' : 'false'; ?>,
  pushEnabled: <?php echo $gtLiveUserId > 0 ? 'true' : 'false'; ?>,
  pushConfigEndpoint: '/wp-json/gigtune/v1/push/config',
  pushSubscribeEndpoint: '/wp-json/gigtune/v1/push/subscribe',
  pushUnsubscribeEndpoint: '/wp-json/gigtune/v1/push/unsubscribe',
  pollEndpoint: '/wp-json/gigtune/v1/notifications?per_page=12&page=1&only_unread=1&include_archived=0',
  pollIntervalMs: 20000
});
</script>
<script src="/wp-content/themes/gigtune-canon/assets/js/gigtune-live.js?v=20260306c"></script>

<?php wp_footer(); ?>
</body>
</html>
