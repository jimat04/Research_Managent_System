(function () {
  'use strict';

  var header = document.getElementById('site-header');
  var navigation = document.getElementById('primary-navigation');
  var toggle = document.querySelector('.menu-toggle');

  if (header) {
    var updateHeader = function () {
      header.classList.toggle('is-scrolled', window.scrollY > 4);
    };

    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
  }

  if (!navigation || !toggle) return;

  var closeNavigation = function () {
    navigation.classList.remove('is-open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Open navigation');
  };

  toggle.addEventListener('click', function () {
    var isOpen = toggle.getAttribute('aria-expanded') === 'true';
    navigation.classList.toggle('is-open', !isOpen);
    toggle.setAttribute('aria-expanded', String(!isOpen));
    toggle.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
  });

  navigation.addEventListener('click', function (event) {
    if (event.target.closest('a')) closeNavigation();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeNavigation();
  });

  window.addEventListener('resize', function () {
    if (window.innerWidth > 960) closeNavigation();
  });
}());