// PARISHHUB client-side behavior
document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  }

  // Auto-hide flash alerts only — not persistent info boxes that just reuse
  // the same `.alert` styling (e.g. book.php's policy box, appointment
  // status notes), which JS keeps referencing throughout the page's life.
  document.querySelectorAll('.alert-success, .alert-error').forEach((el) => {
    setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 8000);
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

  // Handle zero-friendly numeric fields (e.g. sponsor counts)
  document.querySelectorAll('input[type="number"].numeric-zero-friendly').forEach((input) => {
    input.addEventListener('focus', function() {
      if (this.value === '0') {
        this.value = '';
      }
    });
    input.addEventListener('blur', function() {
      if (this.value === '') {
        this.value = '0';
      }
    });
  });

  // Enforce numeric-only rejection on NAME fields dynamically across all forms
  const namePattern = "^(?!\\s*$)(?![0-9\\s.,-]+$)[\\s\\S]+$";
  
  const personNameFields = [
    'bride_name', 'child_name', 'father_name', 'ginikanan_anak', 'groom_name',
    'guest_firstname', 'guest_lastname', 'guest_middlename', 'intention_for',
    'kaslonon_name', 'mother_maiden_name', 'mother_name', 'ngalan_sa_ilubong',
    'offerer_name', 'recipient_name', 'responde', 'sponsor_1', 'sponsor_2',
    'sponsor_name', 'spouse_name', 'asawa_bana'
  ];

  document.querySelectorAll('input[type="text"]').forEach((input) => {
    const name = (input.name || '').toLowerCase();
    
    if (personNameFields.includes(name)) {
      if (!input.hasAttribute('pattern')) {
        input.setAttribute('pattern', namePattern);
        if (!input.hasAttribute('title')) {
          input.setAttribute('title', 'Must contain letters; cannot be purely numeric.');
        }
      }
    }
  });
});
