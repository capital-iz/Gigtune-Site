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

<?php wp_footer(); ?>
</body>
</html>
