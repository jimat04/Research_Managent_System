(function () {
  'use strict';

  var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  var animations = new Set();

  function animate(element, frames, options) {
    if (reducedMotion.matches || !element.animate) return;
    var animation = element.animate(frames, options);
    animations.add(animation);
    animation.finished.then(function () {
      animations.delete(animation);
    }, function () {
      animations.delete(animation);
    });
  }

  reducedMotion.addEventListener('change', function () {
    if (reducedMotion.matches) animations.forEach(function (animation) { animation.cancel(); });
  });

  var heroVideo = document.querySelector('.about-hero-video');
  var heroVideoInView = !('IntersectionObserver' in window);
  function syncHeroVideoMotion() {
    if (!heroVideo) return;
    if (reducedMotion.matches || document.hidden || !heroVideoInView) {
      heroVideo.pause();
      return;
    }

    var playback = heroVideo.play();
    if (playback && typeof playback.catch === 'function') playback.catch(function () {});
  }
  syncHeroVideoMotion();
  reducedMotion.addEventListener('change', syncHeroVideoMotion);
  document.addEventListener('visibilitychange', syncHeroVideoMotion);

  if (heroVideo && 'IntersectionObserver' in window) {
    var heroVideoObserver = new IntersectionObserver(function (entries) {
      heroVideoInView = entries.some(function (entry) {
        return entry.target === heroVideo && entry.isIntersecting && entry.intersectionRatio >= 0.1;
      });
      syncHeroVideoMotion();
    }, { threshold: [0, 0.1] });
    heroVideoObserver.observe(heroVideo);
  }

  var featureCards = document.querySelectorAll('#research-features .features-grid .feature-card');
  var featureDialog = document.getElementById('research-feature-dialog');
  var featureDialogMessage = document.getElementById('research-feature-dialog-message');
  var featureDialogTitle = document.getElementById('research-feature-dialog-title');
  var lastFeatureCard = null;

  if (featureDialog && featureDialogMessage && featureDialogTitle && typeof featureDialog.showModal === 'function') {
    featureCards.forEach(function (card) {
      card.setAttribute('aria-haspopup', 'dialog');
      card.setAttribute('aria-controls', featureDialog.id);
      card.addEventListener('click', function (event) {
        if (featureDialog.open) return;

        var title = card.querySelector('h3');
        var description = card.querySelector('p');
        if (!title || !description) return;

        event.preventDefault();
        lastFeatureCard = card;
        featureDialogMessage.textContent = card.getAttribute('data-feature-message') || description.textContent.trim();
        featureDialog.showModal();
        featureDialogTitle.focus();
      });
    });

    featureDialog.addEventListener('click', function (event) {
      if (event.target !== featureDialog) return;
      var bounds = featureDialog.getBoundingClientRect();
      var clickedOutside = event.clientX < bounds.left || event.clientX > bounds.right
        || event.clientY < bounds.top || event.clientY > bounds.bottom;
      if (clickedOutside) featureDialog.close();
    });
    featureDialog.querySelector('[data-about-feature-dismiss]').addEventListener('click', function () {
      featureDialog.close();
    });
    featureDialog.addEventListener('close', function () {
      if (lastFeatureCard && document.contains(lastFeatureCard)) lastFeatureCard.focus();
      lastFeatureCard = null;
    });
  }

  document.querySelectorAll('.about-manual details.manual-card').forEach(function (card) {
    var summary = card.querySelector(':scope > summary');
    var content = card.querySelector(':scope > .manual-card-content');
    if (!summary || !content || typeof content.animate !== 'function') return;

    var isAnimating = false;
    summary.addEventListener('click', function (event) {
      if (reducedMotion.matches) return;

      event.preventDefault();
      if (isAnimating) return;
      isAnimating = true;
      content.style.overflow = 'hidden';

      if (!card.open) {
        card.open = true;
        var openAnimation = content.animate([
          { height: '0px', opacity: 0 },
          { height: content.scrollHeight + 'px', opacity: 1 }
        ], { duration: 280, easing: 'cubic-bezier(.22,1,.36,1)' });
        openAnimation.onfinish = function () {
          content.style.overflow = '';
          isAnimating = false;
        };
      } else {
        var currentHeight = content.getBoundingClientRect().height;
        var closeAnimation = content.animate([
          { height: currentHeight + 'px', opacity: 1 },
          { height: '0px', opacity: 0 }
        ], { duration: 220, easing: 'ease-in' });
        closeAnimation.onfinish = function () {
          card.open = false;
          content.style.overflow = '';
          isAnimating = false;
        };
      }
    });
  });

  if (!('IntersectionObserver' in window)) return;

  // Animate only when content reaches the viewport; content is visible if JS fails.
  var revealSelector = '.about-hero-copy, .about-overview h2, .about-overview p, .about-purpose .feature-card, .section-header, .about-capabilities .feature-card, .about-role-row, .about-benefits .feature-card, .about-stats-grid > div, .about-cta .container';
  var revealObserver = new IntersectionObserver(function (entries) {
    entries.filter(function (entry) { return entry.isIntersecting; }).forEach(function (entry, index) {
      animate(entry.target, [
        { opacity: 0, transform: 'translateY(28px)' },
        { opacity: 1, transform: 'translateY(0)' }
      ], { duration: 650, delay: Math.min(index % 4, 3) * 75, easing: 'cubic-bezier(.22,1,.36,1)', fill: 'backwards' });
      revealObserver.unobserve(entry.target);
    });
  }, { threshold: .12 });
  document.querySelectorAll(revealSelector).forEach(function (element) { revealObserver.observe(element); });

  var links = document.querySelectorAll('.about-section-nav a');
  var sectionNavigation = document.querySelector('.about-section-nav .container');
  var mobileNavigation = window.matchMedia('(max-width: 600px)');
  var reducedMotionNavigation = window.matchMedia('(prefers-reduced-motion: reduce)');

  function revealActiveSectionLink(link) {
    if (!sectionNavigation || !mobileNavigation.matches) return;

    var navBounds = sectionNavigation.getBoundingClientRect();
    var linkBounds = link.getBoundingClientRect();
    var offset = 0;

    if (linkBounds.left < navBounds.left) {
      offset = linkBounds.left - navBounds.left;
    } else if (linkBounds.right > navBounds.right) {
      offset = linkBounds.right - navBounds.right;
    }

    if (offset !== 0) {
      sectionNavigation.scrollTo({
        left: sectionNavigation.scrollLeft + offset,
        behavior: reducedMotionNavigation.matches ? 'instant' : 'smooth'
      });
    }
  }

  var sectionObserver = new IntersectionObserver(function (entries) {
    var visible = entries.filter(function (entry) { return entry.isIntersecting; });
    if (!visible.length) return;
    visible.sort(function (a, b) { return a.boundingClientRect.top - b.boundingClientRect.top; });
    var id = visible[0].target.id;
    var activeLink = null;
    links.forEach(function (link) {
      if (link.hash === '#' + id) {
        link.setAttribute('aria-current', 'location');
        activeLink = link;
      } else {
        link.removeAttribute('aria-current');
      }
    });
    if (activeLink) revealActiveSectionLink(activeLink);
  }, { rootMargin: '-130px 0px -45% 0px', threshold: 0 });
  links.forEach(function (link) {
    var section = document.getElementById(link.hash.slice(1));
    if (section) sectionObserver.observe(section);
  });
}());
