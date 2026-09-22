(function () { if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { document.documentElement.classList.add('motion-off'); return; }
  'use strict';

  try {
    document.documentElement.classList.add('motion-ready');

    var revealElements = Array.prototype.slice.call(document.querySelectorAll('[data-reveal]'));
    var countupElements = Array.prototype.slice.call(document.querySelectorAll('[data-countup]'));
    var countedElements = new WeakSet();

    function isInViewport(element) {
      var rect = element.getBoundingClientRect();
      return rect.bottom > 0 && rect.top < window.innerHeight;
    }

    function reveal(element) {
      element.classList.add('is-visible');
    }

    function numericTextNode(element) {
      for (var i = 0; i < element.childNodes.length; i += 1) {
        var node = element.childNodes[i];
        if (node.nodeType === Node.TEXT_NODE && /^-?[\d,]+(?:\.\d+)?$/.test(node.nodeValue.trim())) {
          return node;
        }
      }
      return null;
    }

    function animateCount(element) {
      if (countedElements.has(element)) return;

      var node = numericTextNode(element);
      if (!node) return;

      var original = node.nodeValue;
      var match = original.match(/^(\s*)(-?[\d,]+(?:\.(\d+))?)(\s*)$/);
      if (!match) return;

      var target = Number(match[2].replace(/,/g, ''));
      if (!Number.isFinite(target)) return;

      countedElements.add(element);
      var decimals = match[3] ? match[3].length : 0;
      var useGrouping = match[2].indexOf(',') !== -1;
      var formatter = new Intl.NumberFormat(undefined, {
        useGrouping: useGrouping,
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
      var startedAt = performance.now();
      var duration = 900;

      function frame(now) {
        var progress = Math.min((now - startedAt) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        var current = target * eased;
        node.nodeValue = match[1] + formatter.format(decimals ? current : Math.round(current)) + match[4];
        if (progress < 1) requestAnimationFrame(frame);
      }

      requestAnimationFrame(frame);
    }

    revealElements.forEach(function (element) {
      if (isInViewport(element)) reveal(element);
    });

    countupElements.forEach(function (element) {
      if (isInViewport(element)) animateCount(element);
    });

    if ('IntersectionObserver' in window) {
      var revealObserver = new IntersectionObserver(function (entries, observer) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting && entry.intersectionRatio >= 0.15) {
            reveal(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.15 });

      revealElements.forEach(function (element) {
        if (!element.classList.contains('is-visible')) revealObserver.observe(element);
      });

      var countObserver = new IntersectionObserver(function (entries, observer) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting && entry.intersectionRatio >= 0.15) {
            animateCount(entry.target);
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.15 });

      countupElements.forEach(function (element) {
        if (!countedElements.has(element)) countObserver.observe(element);
      });
    } else {
      revealElements.forEach(reveal);
      countupElements.forEach(animateCount);
    }

    window.addEventListener('load', function () {
      revealElements.forEach(function (element) {
        if (isInViewport(element)) reveal(element);
      });
    }, { once: true });

    document.addEventListener('click', function (event) {
      try {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        var link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.getAttribute('target') || '').toLowerCase() === '_blank') return;

        var url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

        event.preventDefault();
        document.body.classList.add('page-leaving');
        window.setTimeout(function () {
          try { window.location.assign(url.href); } catch (error) { document.body.classList.remove('page-leaving'); }
        }, 150);
      } catch (error) {
        return;
      }
    });
  } catch (error) {
    document.documentElement.classList.remove('motion-ready');
  }
}());
