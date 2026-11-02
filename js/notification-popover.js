(() => {
  const popovers = Array.from(document.querySelectorAll('[data-notification-popover]'));
  if (!popovers.length) return;

  const closePopover = (popover, restoreFocus = false) => {
    const toggle = popover.querySelector('[data-notification-toggle]');
    const panel = popover.querySelector('[data-notification-panel]');
    if (!toggle || !panel || panel.hidden) return;
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle.focus();
  };

  popovers.forEach((popover) => {
    const toggle = popover.querySelector('[data-notification-toggle]');
    const panel = popover.querySelector('[data-notification-panel]');
    if (!toggle || !panel) return;

    toggle.addEventListener('click', () => {
      const shouldOpen = panel.hidden;
      popovers.forEach((other) => closePopover(other));
      panel.hidden = !shouldOpen;
      toggle.setAttribute('aria-expanded', String(shouldOpen));
      if (shouldOpen) {
        const firstControl = panel.querySelector('[data-notification-filter]');
        if (firstControl) firstControl.focus({ preventScroll: true });
      }
    });

    panel.querySelectorAll('[data-notification-filter]').forEach((filter) => {
      filter.addEventListener('click', () => {
        const showUnreadOnly = filter.dataset.notificationFilter === 'unread';
        panel.querySelectorAll('[data-notification-filter]').forEach((other) => {
          const selected = other === filter;
          other.classList.toggle('is-active', selected);
          other.setAttribute('aria-pressed', String(selected));
        });

        let visibleItems = 0;
        panel.querySelectorAll('[data-notification-item]').forEach((item) => {
          const visible = item.dataset.notificationView === (showUnreadOnly ? 'unread' : 'all');
          item.hidden = !visible;
          if (visible) visibleItems += 1;
        });

        const empty = panel.querySelector('[data-notification-empty]');
        const noUnread = panel.querySelector('[data-notification-no-unread]');
        if (empty) empty.hidden = visibleItems > 0 || showUnreadOnly;
        if (noUnread) noUnread.hidden = !showUnreadOnly || visibleItems > 0;
      });
    });
  });

  document.addEventListener('click', (event) => {
    popovers.forEach((popover) => {
      if (!popover.contains(event.target)) closePopover(popover);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const openPopover = popovers.find((popover) => !popover.querySelector('[data-notification-panel]')?.hidden);
    if (openPopover) {
      event.preventDefault();
      closePopover(openPopover, true);
    }
  });
})();
