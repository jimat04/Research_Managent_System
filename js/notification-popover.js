(() => {
  const popovers = Array.from(document.querySelectorAll('[data-notification-popover]'));
  if (!popovers.length) return;

  const displayCount = (count) => count > 99 ? '99+' : String(count);

  const closePopover = (popover, restoreFocus = false) => {
    const toggle = popover.querySelector('[data-notification-toggle]');
    if (!toggle || !popover.open) return;
    popover.open = false;
    toggle.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle.focus();
  };

  const updateBellBadge = (popover, count) => {
    const toggle = popover.querySelector('[data-notification-toggle]');
    if (!toggle) return;

    const accessibleLabel = count > 0 ? `Notifications, ${count} unread` : 'Notifications';
    toggle.setAttribute('aria-label', accessibleLabel);
    toggle.setAttribute('title', accessibleLabel);

    let badge = toggle.querySelector('[data-notification-count-badge]');
    if (count <= 0) {
      if (badge) badge.remove();
      return;
    }

    if (!badge) {
      badge = document.createElement('span');
      badge.className = toggle.dataset.notificationBadgeClass || '';
      badge.dataset.notificationCountBadge = '';
      badge.setAttribute('aria-hidden', 'true');
      toggle.appendChild(badge);
    }
    badge.textContent = displayCount(count);
  };

  const updateAreaBadges = (areas) => {
    document.querySelectorAll('[data-notification-area-summary]').forEach((summary) => {
      const key = summary.dataset.notificationAreaSummary;
      const label = summary.dataset.notificationAreaLabel || 'this section';
      const count = Math.max(0, Number(areas[key]) || 0);
      let badge = summary.querySelector('[data-notification-area-badge]');

      if (count <= 0) {
        if (badge) badge.remove();
        return;
      }

      if (!badge) {
        badge = document.createElement('span');
        badge.className = summary.dataset.notificationAreaBadgeClass || '';
        badge.dataset.notificationAreaBadge = '';
        const chevron = summary.querySelector('.student-nav-chevron, .faculty-nav-chevron');
        summary.insertBefore(badge, chevron || null);
      }

      const readableCount = displayCount(count);
      badge.textContent = readableCount;
      badge.setAttribute('title', `${count} unread notifications in ${label}`);
      badge.setAttribute('aria-label', `${count} unread notifications in ${label}`);
    });
  };

  const makeElement = (tagName, className, text) => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };

  const makeNotificationItem = (row, view, actionUrl, csrfToken) => {
    const isUnread = row.is_read !== true;
    const hasLink = row.has_link === true;
    const item = makeElement('li', `rms-notification-item${isUnread ? ' is-unread' : ''}`);
    item.dataset.notificationItem = '';
    item.dataset.notificationView = view;
    item.dataset.notificationId = String(row.notification_id || '');
    item.hidden = view === 'unread';

    let content;
    if (hasLink || isUnread) {
      const form = makeElement('form', 'rms-notification-item-form');
      form.method = 'post';
      form.action = actionUrl;

      const csrf = makeElement('input');
      csrf.type = 'hidden';
      csrf.name = 'csrf_token';
      csrf.value = csrfToken;
      form.appendChild(csrf);

      const action = makeElement('input');
      action.type = 'hidden';
      action.name = 'action';
      action.value = hasLink ? 'open_record' : 'mark_read';
      form.appendChild(action);

      const id = makeElement('input');
      id.type = 'hidden';
      id.name = 'notification_id';
      id.value = String(row.notification_id || '');
      form.appendChild(id);

      const button = makeElement('button', 'rms-notification-item-button');
      button.type = 'submit';
      button.setAttribute('aria-label', `${hasLink ? 'Open ' : 'Mark as read: '}${row.title || 'Notification'}`);
      content = button;
      form.appendChild(button);
      item.appendChild(form);
    } else {
      content = makeElement('div', 'rms-notification-item-static');
      item.appendChild(content);
    }

    content.appendChild(makeElement('span', 'rms-notification-dot'));
    const copy = makeElement('span', 'rms-notification-copy');
    copy.appendChild(makeElement('span', 'rms-notification-title', row.title || 'Notification'));
    copy.appendChild(makeElement('span', 'rms-notification-message', row.message || ''));
    content.appendChild(copy);

    if (row.time_label) {
      const time = makeElement('time', 'rms-notification-time', row.time_label);
      if (row.created_at) time.dateTime = row.created_at;
      content.appendChild(time);
    }

    return item;
  };

  const renderPopoverRows = (panel, data) => {
    const list = panel.querySelector('[data-notification-list]');
    const csrfInput = panel.querySelector('input[name="csrf_token"]');
    const actionForm = panel.querySelector('.rms-notification-menu-form');
    if (!list || !csrfInput || !actionForm) return;

    const unreadCount = Math.max(0, Number(data.unread_count) || 0);
    const unreadCountLabel = panel.querySelector('[data-notification-unread-count]');
    if (unreadCountLabel) {
      unreadCountLabel.textContent = unreadCount > 0 ? displayCount(unreadCount) : '';
      unreadCountLabel.hidden = unreadCount <= 0;
    }

    const rows = data.notifications || {};
    const allRows = Array.isArray(rows.all) ? rows.all : [];
    const unreadRows = Array.isArray(rows.unread) ? rows.unread : [];
    const activeFilter = panel.querySelector('[data-notification-filter].is-active');
    const showUnreadOnly = activeFilter?.dataset.notificationFilter === 'unread';
    const focusedItem = panel.ownerDocument.activeElement?.closest('[data-notification-item]');
    const focusedNotificationId = focusedItem?.dataset.notificationId;
    const focusedView = focusedItem?.dataset.notificationView;
    const csrfToken = csrfInput.value;
    // Avoid form.action: the hidden input named "action" can shadow that property.
    const actionUrl = actionForm.getAttribute('action') || window.location.href;
    const fragment = document.createDocumentFragment();

    allRows.forEach((row) => fragment.appendChild(makeNotificationItem(row, 'all', actionUrl, csrfToken)));
    unreadRows.forEach((row) => fragment.appendChild(makeNotificationItem(row, 'unread', actionUrl, csrfToken)));

    const allEmpty = makeElement('li', 'rms-notification-empty', 'No notifications yet.');
    allEmpty.dataset.notificationEmpty = '';
    allEmpty.hidden = allRows.length > 0 || showUnreadOnly;
    fragment.appendChild(allEmpty);

    const unreadEmpty = makeElement('li', 'rms-notification-empty', 'No unread notifications.');
    unreadEmpty.dataset.notificationNoUnread = '';
    unreadEmpty.hidden = !showUnreadOnly || unreadRows.length > 0;
    fragment.appendChild(unreadEmpty);

    list.replaceChildren(fragment);
    list.querySelectorAll('[data-notification-item]').forEach((item) => {
      const isVisible = item.dataset.notificationView === (showUnreadOnly ? 'unread' : 'all');
      item.hidden = !isVisible;
    });

    if (focusedNotificationId) {
      const replacement = Array.from(list.querySelectorAll('[data-notification-item]')).find((item) => (
        item.dataset.notificationId === focusedNotificationId && item.dataset.notificationView === focusedView
      ));
      replacement?.querySelector('button')?.focus({ preventScroll: true });
    }
  };

  popovers.forEach((popover) => {
    const toggle = popover.querySelector('[data-notification-toggle]');
    const panel = popover.querySelector('[data-notification-panel]');
    if (!toggle || !panel) return;

    toggle.setAttribute('aria-expanded', String(popover.open));
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const shouldOpen = !popover.open;
      popovers.forEach((other) => closePopover(other));
      popover.open = shouldOpen;
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
    const openPopover = popovers.find((popover) => popover.open);
    if (openPopover) {
      event.preventDefault();
      closePopover(openPopover, true);
    }
  });

  let refreshInProgress = false;
  const renderedNotificationState = new WeakMap();
  const refreshNotifications = async () => {
    if (refreshInProgress || document.visibilityState === 'hidden') return;
    refreshInProgress = true;

    try {
      await Promise.all(popovers.map(async (popover) => {
        const endpoint = popover.dataset.notificationStatusUrl;
        if (!endpoint) return;

        const response = await fetch(endpoint, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error('Notification status request failed.');

        const data = await response.json();
        if (!Number.isFinite(Number(data.unread_count)) || !data.areas || !data.notifications) {
          throw new Error('Notification status response was invalid.');
        }

        const unreadCount = Math.max(0, Number(data.unread_count));
        updateBellBadge(popover, unreadCount);
        updateAreaBadges(data.areas);

        const panel = popover.querySelector('[data-notification-panel]');
        if (panel) {
          const notificationState = JSON.stringify({
            unread_count: unreadCount,
            notifications: data.notifications,
          });
          if (renderedNotificationState.get(popover) !== notificationState) {
            renderPopoverRows(panel, data);
            renderedNotificationState.set(popover, notificationState);
          }
        }
      }));
    } catch (error) {
      // Keep the last rendered state when the endpoint is temporarily unavailable.
    } finally {
      refreshInProgress = false;
    }
  };

  refreshNotifications();
  window.setInterval(refreshNotifications, 30000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refreshNotifications();
  });
})();
