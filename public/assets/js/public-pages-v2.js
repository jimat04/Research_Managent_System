(function () {
  'use strict';

  var navigation = document.querySelector('.navbar');
  var toggle = document.querySelector('.public-menu-toggle');

  if (!navigation || !toggle) return;

  var updateHeader = function () {
    navigation.classList.toggle('s', window.scrollY > 8);
  };
  updateHeader();
  window.addEventListener('scroll', updateHeader, { passive: true });

  function closeMenu() {
    navigation.classList.remove('menu-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation');
  }

  toggle.addEventListener('click', function () {
    var isOpen = toggle.getAttribute('aria-expanded') === 'true';
    navigation.classList.toggle('menu-open', !isOpen);
    toggle.setAttribute('aria-expanded', String(!isOpen));
    toggle.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
  });

  navigation.addEventListener('click', function (event) {
    if (event.target.closest('.nav-links a, .nav-actions a')) closeMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeMenu();
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 980) closeMenu();
  });
}());
