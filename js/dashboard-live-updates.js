(() => {
  'use strict';

  const dashboard = document.querySelector('[data-dashboard-live]');
  if (!dashboard) return;

  const status = dashboard.querySelector('[data-dashboard-live-status]');
  const dataUrl = dashboard.dataset.dashboardDataUrl;
  const pollInterval = 30_000;
  const maxRetryDelay = 300_000;
  const chartSeries = [
    { key: 'seminars_workshops', color: '#4F8DF7' },
    { key: 'trainings', color: '#2D72C4' },
    { key: 'colloquia', color: '#7C4DFF' },
    { key: 'conferences', color: '#64748B' },
    { key: 'publications', color: '#19B98A' },
    { key: 'copyrights', color: '#F5AB18' },
  ];
  let timer = null;
  let inFlight = false;
  let retryDelay = pollInterval;

  const updateStatus = (message, state = 'current') => {
    if (!status) return;
    status.textContent = message;
    status.dataset.state = state;
  };

  const formatTime = (value) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) throw new Error('Invalid dashboard update time.');
    return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(date);
  };
  let lastSuccessfulUpdate = status ? formatTime(status.dataset.dashboardInitialUpdatedAt) : '';

  const appendSvgElement = (svg, tag, attributes, text = null) => {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attributes).forEach(([name, value]) => element.setAttribute(name, String(value)));
    if (text !== null) element.textContent = text;
    svg.append(element);
    return element;
  };

  const updateChart = (chartData) => {
    const svg = dashboard.querySelector('[data-dashboard-chart]');
    if (!svg || !chartData || !Array.isArray(chartData.months)) return;

    const width = 540;
    const height = 160;
    const paddingTop = 12;
    const paddingBottom = 18;
    const paddingLeft = 20;
    const paddingRight = 12;
    const innerWidth = width - paddingLeft - paddingRight;
    const innerHeight = height - paddingTop - paddingBottom;
    const groupWidth = innerWidth / Math.max(1, chartData.months.length);
    const barWidth = Math.min(6, Math.max(2, groupWidth / 10));
    let maxValue = 1;

    chartData.months.forEach((month) => chartSeries.forEach((series) => {
      const value = Number(month[series.key]);
      if (Number.isFinite(value)) maxValue = Math.max(maxValue, value);
    }));

    svg.replaceChildren();
    for (let line = 0; line <= 5; line += 1) {
      const y = paddingTop + (innerHeight / 5) * line;
      appendSvgElement(svg, 'line', {
        x1: paddingLeft,
        y1: y,
        x2: width - paddingRight,
        y2: y,
        stroke: '#E5E7EB',
        'stroke-width': 1,
      });
    }

    chartData.months.forEach((month, index) => {
      const monthX = paddingLeft + (index * groupWidth) + (groupWidth / 2);
      appendSvgElement(svg, 'text', {
        x: monthX,
        y: height - 4,
        'text-anchor': 'middle',
        'font-size': 9,
        fill: '#64748B',
      }, String(month.label || ''));

      chartSeries.forEach((series, seriesIndex) => {
        const rawValue = Number(month[series.key]);
        const value = Number.isFinite(rawValue) ? Math.max(0, rawValue) : 0;
        const barHeight = innerHeight * (value / maxValue);
        const groupedSeriesWidth = (chartSeries.length * barWidth) + ((chartSeries.length - 1) * 2);
        const x = monthX - (groupedSeriesWidth / 2) + (seriesIndex * (barWidth + 2));
        const y = height - paddingBottom - barHeight;
        appendSvgElement(svg, 'rect', {
          x,
          y,
          width: barWidth,
          height: barHeight,
          rx: 2,
          fill: series.color,
          opacity: 0.92,
        });
      });
    });
  };

  const createTextCell = (row, text) => {
    const cell = document.createElement('td');
    cell.textContent = String(text ?? '');
    row.append(cell);
    return cell;
  };

  const updateEvents = (events) => {
    const panel = dashboard.querySelector('[data-dashboard-recent-events]');
    const content = panel && panel.querySelector('[data-dashboard-events-content]');
    if (!content || !Array.isArray(events)) return;

    content.replaceChildren();
    if (events.length === 0) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'Module activity will appear here after the first activity, publication, or copyright application is recorded.';
      content.append(empty);
      return;
    }

    const wrap = document.createElement('div');
    wrap.className = 'event-wrap';
    const table = document.createElement('table');
    table.className = 'event-table';
    const head = document.createElement('thead');
    const headingRow = document.createElement('tr');
    ['Date', 'Activity', 'Researcher', 'Category', 'Status'].forEach((label) => {
      const heading = document.createElement('th');
      heading.textContent = label;
      headingRow.append(heading);
    });
    head.append(headingRow);
    const body = document.createElement('tbody');

    events.forEach((event) => {
      const row = document.createElement('tr');
      createTextCell(row, event.date);

      const labelCell = document.createElement('td');
      const link = document.createElement('a');
      link.className = 'event-name';
      link.textContent = String(event.label ?? '');
      try {
        const eventUrl = new URL(String(event.url ?? ''), window.location.href);
        if (eventUrl.origin === window.location.origin) link.href = eventUrl.href;
      } catch (error) {
        // Keep the activity label visible without creating an unsafe link.
      }
      labelCell.append(link);
      row.append(labelCell);

      createTextCell(row, event.actor);
      createTextCell(row, event.category);
      const statusCell = document.createElement('td');
      const badge = document.createElement('span');
      const knownTones = ['warning', 'review', 'info', 'success', 'danger', 'neutral'];
      const tone = knownTones.includes(event.tone) ? event.tone : 'neutral';
      badge.className = `badge tone-${tone}`;
      badge.textContent = String(event.status ?? '');
      statusCell.append(badge);
      row.append(statusCell);
      body.append(row);
    });

    table.append(head, body);
    wrap.append(table);
    content.append(wrap);
  };

  const updateResearchers = (researchers) => {
    const panel = dashboard.querySelector('[data-dashboard-researchers]');
    if (!panel || !researchers) return;

    const existing = panel.querySelector('.researcher-summary, .empty-state');
    const total = Math.max(0, Number(researchers.total) || 0);
    if (total === 0) {
      const empty = document.createElement('div');
      empty.className = 'empty-state';
      empty.textContent = 'No active faculty or student researchers were found.';
      if (existing) existing.replaceWith(empty);
      else panel.append(empty);
      return;
    }

    const faculty = Math.max(0, Number(researchers.faculty) || 0);
    const student = Math.max(0, Number(researchers.student) || 0);
    const facultyShare = total > 0 ? (faculty / total) * 100 : 0;
    const studentShare = total > 0 ? (student / total) * 100 : 0;
    const summary = document.createElement('div');
    summary.className = 'researcher-summary';

    const donut = document.createElement('div');
    donut.className = 'researcher-donut';
    donut.style.setProperty('--faculty-share', `${facultyShare.toFixed(1)}%`);
    const donutCenter = document.createElement('div');
    const totalText = document.createElement('strong');
    totalText.textContent = String(total);
    const totalLabel = document.createElement('span');
    totalLabel.textContent = 'Researchers';
    donutCenter.append(totalText, totalLabel);
    donut.append(donutCenter);

    const legend = document.createElement('div');
    legend.className = 'researcher-legend';
    [
      { label: 'Faculty Researchers', count: faculty, share: facultyShare, key: 'faculty-key' },
      { label: 'Student Researchers', count: student, share: studentShare, key: 'student-key' },
    ].forEach((item) => {
      const row = document.createElement('div');
      row.className = 'researcher-legend-row';
      const key = document.createElement('span');
      key.className = `researcher-key ${item.key}`;
      const label = document.createElement('span');
      label.textContent = item.label;
      const count = document.createElement('strong');
      count.textContent = String(item.count);
      const share = document.createElement('small');
      share.textContent = `${item.share.toFixed(1)}%`;
      row.append(key, label, count, share);
      legend.append(row);
    });

    summary.append(donut, legend);
    if (existing) existing.replaceWith(summary);
    else panel.append(summary);
  };

  const applyPayload = (payload) => {
    if (!payload || payload.success !== true || !payload.modules) throw new Error('Dashboard data response was incomplete.');

    Object.entries(payload.modules).forEach(([moduleKey, module]) => {
      dashboard.querySelectorAll('[data-dashboard-module-total]').forEach((element) => {
        if (element.dataset.dashboardModuleTotal !== moduleKey) return;
        element.textContent = element.closest('.module-summary-card') && !module.has_count ? '—' : String(module.count);
      });

      dashboard.querySelectorAll('[data-dashboard-module]').forEach((element) => {
        if (element.dataset.dashboardModule !== moduleKey) return;
        const unavailable = module.pages.some((page) => page.count === null);
        if (element.hasAttribute('data-count-source-unavailable')) {
          element.dataset.countSourceUnavailable = unavailable ? 'true' : 'false';
        }
        const detail = element.querySelector('[data-dashboard-module-detail]');
        if (detail) {
          detail.textContent = module.has_count
            ? 'records across available pages'
            : (module.source_available ? 'no separate count' : 'record source unavailable');
        }
        if (element.matches('.rms-admin-summary-tile')) {
          const sectionName = element.querySelector('.rms-admin-summary-copy span:last-child')?.textContent || module.label;
          element.setAttribute('aria-label', `${sectionName}, ${module.count} total${unavailable ? ', one or more count sources unavailable' : ''}, jump to section`);
        }
      });

      module.pages.forEach((page) => {
        dashboard.querySelectorAll('[data-dashboard-page-count]').forEach((element) => {
          if (element.dataset.dashboardPageCount !== page.key) return;
          element.textContent = page.count === null ? page.detail : `${page.count} ${page.detail}`;
        });
      });

      if (moduleKey === 'research_capacity_building') {
        dashboard.querySelectorAll('[data-dashboard-note="capacity"]').forEach((element) => {
          if (payload.role === 'admin') return;
          element.textContent = `${module.upcoming} upcoming activities across permitted Capacity Building pages.`;
        });
      }
    });

    const developmentNote = dashboard.querySelector('[data-dashboard-note="development"]');
    if (developmentNote && payload.stats) {
      const parts = [];
      if (payload.stats.review_required !== null) parts.push(`${payload.stats.review_required} requiring review`);
      if (payload.stats.approval_required !== null) parts.push(`${payload.stats.approval_required} approved proposals`);
      developmentNote.textContent = parts.join(' · ');
    }
    const publicationNote = dashboard.querySelector('[data-dashboard-note="publications"]');
    if (publicationNote && payload.stats && payload.stats.pending_publications !== null) {
      publicationNote.textContent = `${payload.stats.pending_publications} legacy publication records retain their historical status.`;
    }

    if (payload.academic_year) {
      const year = dashboard.querySelector('[data-dashboard-academic-year]');
      const semester = dashboard.querySelector('[data-dashboard-semester]');
      const chartYear = dashboard.querySelector('[data-dashboard-chart-academic-year]');
      if (year) year.textContent = String(payload.academic_year.label ?? '');
      if (semester) semester.textContent = String(payload.academic_year.semester ?? '');
      if (chartYear) chartYear.textContent = String(payload.academic_year.label ?? '');
    }

    updateChart(payload.chart);
    updateEvents(payload.events);
    updateResearchers(payload.researchers);
  };

  const schedule = (delay) => {
    if (timer) window.clearTimeout(timer);
    if (document.hidden) return;
    timer = window.setTimeout(refresh, delay);
  };

  async function refresh() {
    if (inFlight || document.hidden) return;
    inFlight = true;
    let nextDelay = retryDelay;
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15_000);

    try {
      const response = await fetch(dataUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`Dashboard update returned ${response.status}.`);
      const payload = await response.json();
      applyPayload(payload);
      lastSuccessfulUpdate = formatTime(payload.updated_at);
      updateStatus(`Updated ${lastSuccessfulUpdate}`);
      retryDelay = pollInterval;
      nextDelay = pollInterval;
    } catch (error) {
      updateStatus(`Live update unavailable. Showing data from ${lastSuccessfulUpdate}; retrying.`, 'stale');
      retryDelay = Math.min(Math.max(pollInterval, retryDelay * 2), maxRetryDelay);
      nextDelay = retryDelay;
    } finally {
      window.clearTimeout(timeout);
      inFlight = false;
      schedule(nextDelay);
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      if (timer) window.clearTimeout(timer);
      timer = null;
      return;
    }
    refresh();
  });

  schedule(pollInterval);
})();
