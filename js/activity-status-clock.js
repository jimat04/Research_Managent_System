(function () {
  const statusLabels = {
    upcoming: {label: 'Upcoming', tone: 'info'},
    ongoing: {label: 'Ongoing', tone: 'warning'},
    past: {label: 'Past', tone: 'neutral'},
    conducted: {label: 'Conducted', tone: 'success'},
    cancelled: {label: 'Cancelled', tone: 'danger'},
  };
  const toneClasses = ['tone-info', 'tone-warning', 'tone-neutral', 'tone-success', 'tone-danger'];

  function parsePayload(value) {
    try {
      return JSON.parse(value || '{}');
    } catch (error) {
      return {};
    }
  }

  function storedActivityStatus(status) {
    if (status === 'cancelled') return 'cancelled';
    if (status === 'conducted' || status === 'completed') return 'conducted';
    return status === 'ongoing' ? 'ongoing' : 'upcoming';
  }

  function sessionStatus(session, now) {
    if (session.status === 'cancelled' || session.activityStatus === 'cancelled') return 'cancelled';
    if (session.activityStatus === 'conducted' || session.activityStatus === 'completed') return 'conducted';
    const start = Number(session.start) || 0;
    const end = Number(session.end) || 0;
    if (!start || !end) return 'upcoming';
    if (now < start) return 'upcoming';
    return now <= end ? 'ongoing' : 'past';
  }

  function activityStatus(storedStatus, sessions, now) {
    const stored = storedActivityStatus(storedStatus);
    if (stored === 'cancelled' || stored === 'conducted') return stored;
    const activeSessions = sessions.filter((session) => session.status !== 'cancelled');
    if (!activeSessions.length) return stored;
    const states = activeSessions.map((session) => sessionStatus(session, now));
    if (states.includes('ongoing')) return 'ongoing';
    if (states.includes('upcoming')) return 'upcoming';
    if (states.includes('past')) return 'past';
    return stored;
  }

  function setBadge(badge, status) {
    const display = statusLabels[status] || statusLabels.upcoming;
    if (badge.textContent !== display.label) badge.textContent = display.label;
    badge.classList.remove(...toneClasses);
    badge.classList.add(`tone-${display.tone}`);
  }

  function refreshStatuses() {
    const now = Date.now();
    document.querySelectorAll('[data-live-activity-status]').forEach((badge) => {
      const sessions = parsePayload(badge.dataset.clockSessions);
      const items = Array.isArray(sessions) ? sessions : [];
      setBadge(badge, activityStatus(badge.dataset.storedStatus, items, now));
    });
    document.querySelectorAll('[data-live-schedule-status]').forEach((badge) => {
      setBadge(badge, sessionStatus(parsePayload(badge.dataset.sessionClock), now));
    });
  }

  refreshStatuses();
  window.setInterval(refreshStatuses, 30000);
})();
