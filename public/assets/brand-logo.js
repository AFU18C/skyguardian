(() => {
  const applyLogo = () => {
    document.querySelectorAll('.brand-mark').forEach(mark => {
      if (mark.querySelector('.brand-logo-image')) return;
      mark.classList.add('brand-logo-mark');
      mark.replaceChildren();
      const image = document.createElement('img');
      image.className = 'brand-logo-image';
      image.src = '/assets/skyguardian-logo.jpg?v=1';
      image.alt = '';
      image.decoding = 'async';
      mark.append(image);
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyLogo, { once: true });
  } else {
    applyLogo();
  }
})();
