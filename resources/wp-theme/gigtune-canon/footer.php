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
</script>

<?php wp_footer(); ?>
</body>
</html>
