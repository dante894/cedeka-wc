// =============================================
// CEDEKA WORLD CUP — App JS
// =============================================

document.addEventListener('DOMContentLoaded', () => {
  // Auto-dismiss alerts after 5s
  document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => el.style.transition = 'opacity 0.6s', 4400);
    setTimeout(() => el.style.opacity = '0', 5000);
    setTimeout(() => el.remove(), 5600);
  });

  // Highlight active nav link
  const path = window.location.pathname + window.location.search;
  document.querySelectorAll('.nav-links a').forEach(a => {
    if (path.includes(a.getAttribute('href'))) a.classList.add('active');
  });

  // Format number inputs on blur
  document.querySelectorAll('input[type="number"]').forEach(inp => {
    inp.addEventListener('wheel', e => e.preventDefault(), { passive: false });
  });
});
