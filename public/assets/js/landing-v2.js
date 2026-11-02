(function () {
  'use strict';

  var header = document.querySelector('header');
  var navigation = document.getElementById('primary-navigation');
  var menuToggle = document.querySelector('.menu-toggle');

  if (header) {
    var updateHeader = function () {
      header.classList.toggle('s', window.scrollY > 8);
    };
    updateHeader();
    window.addEventListener('scroll', updateHeader, { passive: true });
  }

  if (navigation && menuToggle) {
    var closeNavigation = function () {
      navigation.classList.remove('is-open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuToggle.setAttribute('aria-label', 'Open navigation');
    };

    menuToggle.addEventListener('click', function () {
      var isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
      navigation.classList.toggle('is-open', !isOpen);
      menuToggle.setAttribute('aria-expanded', String(!isOpen));
      menuToggle.setAttribute('aria-label', isOpen ? 'Open navigation' : 'Close navigation');
    });

    navigation.addEventListener('click', function (event) {
      if (event.target.closest('a')) closeNavigation();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') closeNavigation();
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 980) closeNavigation();
    });
  }

  document.documentElement.classList.add('js');
  var revealElements = document.querySelectorAll('.rv');
  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.1 });

    revealElements.forEach(function (element, index) {
      element.style.transitionDelay = (index % 5) * 70 + 'ms';
      observer.observe(element);
    });
  } else {
    revealElements.forEach(function (element) {
      element.classList.add('in');
    });
  }
}());
