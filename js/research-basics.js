/* Progressive enhancement only: all saved progress uses the existing PHP forms. */
(() => {
  'use strict';

  const guide = document.querySelector('.research-basics');
  if (!guide) return;

  const shell = guide.closest('.student-dashboard');
  const header = shell.querySelector('.student-topbar');
  const tracker = guide.querySelector('.research-basics-tracker');
  const sections = Array.from(guide.querySelectorAll('.research-basics-section'));
  const tabs = Array.from(guide.querySelectorAll('.research-basics-tab'));
  const tabList = guide.querySelector('.research-basics-tabs');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const currentLesson = guide.querySelector('#research-basics-current-section');
  const count = guide.querySelector('#research-basics-done-count');
  const fill = guide.querySelector('#research-basics-tracker-fill');
  const progressbar = tracker.querySelector('[role="progressbar"]');
  const progressAvailable = guide.dataset.lessonProgressAvailable === 'true';
  const existingCompletion = guide.dataset.existingCompleted === 'true';
  const lessonTransitionTarget = new URLSearchParams(window.location.search).get('lesson-transition');
  const lessonTransitionFrom = new URLSearchParams(window.location.search).get('lesson-transition-from');
  const done = sections.map((section) => section.dataset.lessonComplete === 'true');
  const doneCount = done.filter(Boolean).length;
  const firstIncomplete = done.indexOf(false);
  const lastAvailable = !existingCompletion && firstIncomplete !== -1
    ? firstIncomplete : sections.length - 1;
  let selectedLesson = -1;
  const zoomStates = new WeakMap();

  function getLessonBounds(section) {
    const bounds = section.getBoundingClientRect();
    const height = section.offsetHeight;
    const state = zoomStates.get(section);
    // Remove our visual transform when measuring the underlying layout. This
    // prevents the animation from feeding back into scroll/lesson tracking.
    const shift = state && !reducedMotion.matches
      ? height * (1 - state.scale) * state.origin : 0;
    return { top: bounds.top - shift, height };
  }

  function updateScrollZoom() {
    if (reducedMotion.matches) return;
    const viewTop = Math.max(0, tracker.getBoundingClientRect().bottom) + 16;
    const viewHeight = Math.max(1, window.innerHeight - viewTop);
    // Read all layout measurements before writing any animation properties.
    const frames = sections.filter((section) => !section.hidden).map((section) => {
      const { top, height } = getLessonBounds(section);
      const distance = Math.max(1, Math.min(320, viewHeight * .45, height * .45));
      const entering = Math.max(0, Math.min(1, (window.innerHeight - top) / distance));
      const leaving = Math.max(0, Math.min(1, (top + height - viewTop) / distance));
      const presence = Math.min(entering, leaving);
      const eased = presence * presence * (3 - 2 * presence);
      return {
        section,
        scale: .85 + .15 * eased,
        // Keep the entering top edge and outgoing bottom edge in place.
        // The origin switches only while the card is at full size.
        origin: entering < leaving ? 0 : 1
      };
    });
    frames.forEach(({ section, scale, origin }) => {
      zoomStates.set(section, { scale, origin });
      section.style.setProperty('--lesson-zoom', String(scale));
      section.style.setProperty('--lesson-zoom-origin', `${origin * 100}%`);
    });
  }

  function syncScrollZoom() {
    guide.classList.toggle('has-scroll-zoom', !reducedMotion.matches);
    sections.forEach((section) => {
      section.style.removeProperty('--lesson-zoom');
      section.style.removeProperty('--lesson-zoom-origin');
      zoomStates.delete(section);
    });
    updateScrollZoom();
  }
  reducedMotion.addEventListener('change', syncScrollZoom);

  function selectLesson(index) {
    const next = Math.max(0, Math.min(lastAvailable, index));
    if (selectedLesson === next) return;
    selectedLesson = next;
    tabs.forEach((tab, tabIndex) => {
      if (tabIndex === next) tab.setAttribute('aria-current', 'true');
      else tab.removeAttribute('aria-current');
    });
    sections.forEach((section, sectionIndex) => {
      section.classList.toggle('is-current', sectionIndex === next);
    });
    currentLesson.textContent = `Lesson ${next + 1} of ${sections.length}: ${sections[next].dataset.lessonTitle}`;
    keepCurrentTabVisible();
  }

  function keepCurrentTabVisible() {
    if (selectedLesson < 0) return;
    // Scroll only the horizontal tab strip, keeping the document's position.
    const tabBounds = tabs[selectedLesson].getBoundingClientRect();
    const listBounds = tabList.getBoundingClientRect();
    if (tabBounds.left < listBounds.left || tabBounds.right > listBounds.right) {
      tabList.scrollBy({
        left: tabBounds.left < listBounds.left
          ? tabBounds.left - listBounds.left - 8
          : tabBounds.right - listBounds.right + 8,
        behavior: reducedMotion.matches ? 'auto' : 'smooth'
      });
    }
  }

  if (progressAvailable) {
    count.textContent = `${doneCount} of ${sections.length} lessons done`;
    progressbar.setAttribute('aria-valuenow', String(doneCount));
    progressbar.setAttribute('aria-valuetext', count.textContent);
    fill.style.width = `${doneCount / sections.length * 100}%`;
  }

  tabs.forEach((tab, index) => {
    const locked = index > lastAvailable;
    const title = sections[index].dataset.lessonTitle;
    tab.setAttribute('aria-label', `${index + 1}. ${title}${locked ? ', locked' : done[index] ? ', complete' : ''}`);
  });

  // Measure both sticky elements so wrapping, zoom, fonts and mobile headers
  // cannot leave the tracker or anchor destinations underneath the shell header.
  function measureStickyOffsets() {
    const headerHeight = getComputedStyle(header).position === 'sticky'
      ? Math.ceil(header.getBoundingClientRect().height) : 0;
    shell.style.setProperty('--basics-header-height', `${headerHeight}px`);
    const anchorOffset = headerHeight + Math.ceil(tracker.getBoundingClientRect().height) + 16;
    shell.style.setProperty('--basics-scroll-offset', `${anchorOffset}px`);
    keepCurrentTabVisible();
    if (guide.classList.contains('has-scroll-zoom')) updateScrollZoom();
  }

  function updateLessonFromScroll() {
    const line = tracker.getBoundingClientRect().bottom + 24;
    let index = 0;
    sections.slice(0, lastAvailable + 1).forEach((section, sectionIndex) => {
      if (getLessonBounds(section).top <= line) index = sectionIndex;
    });
    if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
      index = lastAvailable;
    }
    selectLesson(index);
  }

  let scrollPending = false;
  window.addEventListener('scroll', () => {
    if (scrollPending) return;
    scrollPending = true;
    requestAnimationFrame(() => {
      updateLessonFromScroll();
      updateScrollZoom();
      scrollPending = false;
    });
  }, { passive: true });
  window.addEventListener('resize', measureStickyOffsets);
  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver(measureStickyOffsets);
    observer.observe(header);
    observer.observe(tracker);
  }
  measureStickyOffsets();

  const roadmap = guide.querySelector('#research-roadmap-widget');
  let selectRoadmapFromHash = () => {};
  if (roadmap) {
    const stages = Array.from(roadmap.querySelectorAll('.research-roadmap-stage'));
    const panels = Array.from(roadmap.querySelectorAll('.research-roadmap-detail'));
    const previous = roadmap.querySelector('#roadmap-previous');
    const next = roadmap.querySelector('#roadmap-next');
    const stageCount = roadmap.querySelector('#roadmap-progress-count');
    const status = roadmap.querySelector('#roadmap-selection-status');
    const stageList = roadmap.querySelector('.research-roadmap-stages');
    const stageProgress = roadmap.querySelector('#roadmap-progress');
    const returnStageInputs = Array.from(roadmap.querySelectorAll('input[name="return_stage"]'));
    let selectedStage = 0;

    if (stages.length && stages.length === panels.length) {
      const selectStage = (index, focus) => {
        selectedStage = Math.max(0, Math.min(stages.length - 1, index));
        stages.forEach((button, buttonIndex) => {
          if (buttonIndex === selectedStage) button.setAttribute('aria-current', 'step');
          else button.removeAttribute('aria-current');
        });
        panels.forEach((panel, panelIndex) => {
          panel.hidden = panelIndex !== selectedStage;
        });
        stageCount.textContent = `Stage ${selectedStage + 1} of ${stages.length}`;
        status.textContent = `${stageCount.textContent}: ${panels[selectedStage].querySelector('h3').textContent}`;
        previous.disabled = selectedStage === 0;
        next.disabled = selectedStage === stages.length - 1;
        returnStageInputs.forEach((input) => {
          input.value = String(selectedStage + 1);
        });
        if (focus) stages[selectedStage].focus();
      };
      selectRoadmapFromHash = () => {
        const match = window.location.hash.match(/^#roadmap-stage-(\d+)$/);
        if (match) selectStage(Number(match[1]) - 1, false);
      };
      stages.forEach((button, index) => {
        button.addEventListener('click', () => selectStage(index, false));
        button.addEventListener('keydown', (event) => {
          let target = index;
          if (event.key === 'ArrowDown' || event.key === 'ArrowRight') target = (index + 1) % stages.length;
          else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') target = (index + stages.length - 1) % stages.length;
          else if (event.key === 'Home') target = 0;
          else if (event.key === 'End') target = stages.length - 1;
          else return;
          event.preventDefault();
          selectStage(target, true);
        });
      });
      previous.addEventListener('click', () => selectStage(selectedStage - 1, false));
      next.addEventListener('click', () => selectStage(selectedStage + 1, false));
      selectStage(0, false);
      selectRoadmapFromHash();
      stageList.hidden = false;
      roadmap.querySelector('.research-roadmap-progress').hidden = false;
      roadmap.querySelector('.research-roadmap-controls').hidden = false;
      if (stageProgress) stageProgress.hidden = false;
      roadmap.classList.add('is-interactive');
    }
  }

  function followHash(scroll, behavior = 'auto', moveFocus = false) {
    selectRoadmapFromHash();
    const target = document.getElementById(window.location.hash.slice(1));
    if (!target || !guide.contains(target)) return false;
    const section = target.closest('.research-basics-section');
    const index = section ? sections.indexOf(section) : -1;
    const destination = index > lastAvailable ? sections[lastAvailable] : target;
    if (section) selectLesson(Math.min(index, lastAvailable));
    if (scroll) {
      if (destination.matches('.research-basics-section')) {
        const margin = parseFloat(getComputedStyle(destination).scrollMarginTop) || 0;
        window.scrollTo({ top: window.scrollY + getLessonBounds(destination).top - margin, behavior });
      } else {
        destination.scrollIntoView({ block: 'start', behavior });
      }
    }
    if (moveFocus) {
      const focusTarget = destination.matches('.research-basics-section')
        ? destination.querySelector('h2') : destination;
      focusTarget.setAttribute('tabindex', '-1');
      focusTarget.focus({ preventScroll: true });
    }
    return true;
  }

  guide.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href^="#"]');
    if (!link || !guide.contains(link)) return;
    if (link.getAttribute('aria-disabled') === 'true') {
      event.preventDefault();
      return;
    }
    const target = document.getElementById(link.hash.slice(1));
    if (!target || !guide.contains(target)) return;
    const section = target.closest('.research-basics-section');
    event.preventDefault();
    if (section && sections.indexOf(section) > lastAvailable) return;
    if (window.location.hash !== link.hash) history.pushState(null, '', link.hash);
    followHash(true, reducedMotion.matches ? 'auto' : 'smooth', true);
  });
  window.addEventListener('hashchange', () => followHash(true));
  if (lessonTransitionTarget) {
    const destination = document.getElementById(lessonTransitionTarget);
    const destinationSection = destination && destination.closest('.research-basics-section');
    const destinationIndex = destinationSection ? sections.indexOf(destinationSection) : -1;
    const origin = lessonTransitionFrom ? document.getElementById(lessonTransitionFrom) : null;
    const originSection = origin && origin.closest('.research-basics-section');
    const originIndex = originSection ? sections.indexOf(originSection) : -1;
    const isFinalPrompt = destination && destination.id === 'research-basics-cta';
    if (destination && guide.contains(destination) && (destinationIndex >= 0 || isFinalPrompt)) {
      history.replaceState(null, '', window.location.pathname);
      if (destinationIndex >= 0) selectLesson(Math.min(destinationIndex, lastAvailable));
      requestAnimationFrame(() => {
        const scrollToTarget = (target, behavior) => {
          const margin = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;
          window.scrollTo({
            top: window.scrollY + getLessonBounds(target).top - margin,
            behavior
          });
        };
        if (!reducedMotion.matches && originSection && guide.contains(originSection)
          && originIndex > destinationIndex && destinationIndex >= 0) {
          scrollToTarget(originSection, 'instant');
          requestAnimationFrame(() => scrollToTarget(destination, 'smooth'));
        } else {
          scrollToTarget(destination, reducedMotion.matches ? 'auto' : 'smooth');
        }
      });
    } else if (!followHash(false)) {
      selectLesson(firstIncomplete === -1 ? 0 : firstIncomplete);
    }
  } else if (!followHash(false)) {
    selectLesson(firstIncomplete === -1 ? 0 : firstIncomplete);
  }
  syncScrollZoom();
  requestAnimationFrame(() => {
    measureStickyOffsets();
    if (window.location.hash) followHash(true, reducedMotion.matches ? 'auto' : 'smooth');
    else if (!lessonTransitionTarget) updateLessonFromScroll();
  });
})();
