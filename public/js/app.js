// PARISHHUB client-side behavior
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  }

  // Auto-hide flash alerts
  document.querySelectorAll('.alert').forEach((el) => {
    setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 4500);
  });

  // Note: the chatbot widget (FAB, panel, suggestions) is handled by
  // public/js/chatbot.js, loaded separately in includes/footer.php.

  // Reveal-on-scroll for landing-page cards (.reveal), staggered like the
  // rest of the site's entrance animations. Falls back gracefully — if
  // IntersectionObserver isn't supported, just show everything immediately.
  const revealEls = document.querySelectorAll('.reveal');
  if (revealEls.length) {
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, i) => {
          if (entry.isIntersecting) {
            setTimeout(() => entry.target.classList.add('visible'), i * 80);
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.1 });
      revealEls.forEach((el) => observer.observe(el));
    } else {
      revealEls.forEach((el) => el.classList.add('visible'));
    }
  }
});
