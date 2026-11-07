/** Faculty portfolio analytics charts. Tabular values remain available without Chart.js. */
(function() {
    'use strict';

    const chartUrl = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
    let chartPromise;

    function loadChartJs() {
        if (window.Chart) return Promise.resolve(window.Chart);
        if (!chartPromise) {
            chartPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = chartUrl;
                script.async = true;
                script.onload = () => window.Chart ? resolve(window.Chart) : reject(new Error('Chart.js did not initialize'));
                script.onerror = () => reject(new Error('Chart.js could not be loaded'));
                document.head.appendChild(script);
            });
        }
        return chartPromise;
    }

    function colors() {
        const styles = getComputedStyle(document.documentElement);
        return {
            primary: styles.getPropertyValue('--faculty-accent').trim() || '#1d4ed8',
            success: styles.getPropertyValue('--faculty-success').trim() || '#15803d',
            warning: styles.getPropertyValue('--faculty-warning').trim() || '#b45309',
            muted: '#94a3b8',
            text: '#475569',
            grid: 'rgba(148, 163, 184, .18)'
        };
    }

    function parseData(canvas) {
        try {
            const parsed = JSON.parse(canvas.dataset.chartData || '[]');
            if (!Array.isArray(parsed)) return [];
            return parsed.filter(item => item && typeof item.label === 'string' && Number.isFinite(Number(item.value)))
                .map(item => ({ label: item.label, value: Number(item.value) }));
        } catch (error) {
            return [];
        }
    }

    function initCharts(Chart) {
        const palette = colors();
        const paletteRows = [palette.primary, palette.success, palette.warning, palette.muted];
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        document.querySelectorAll('.faculty-analytics canvas[data-chart-data]').forEach(canvas => {
            const points = parseData(canvas);
            if (!points.length) return;

            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: points.map(point => point.label),
                    datasets: [{
                        label: 'Records',
                        data: points.map(point => point.value),
                        backgroundColor: points.map((point, index) => paletteRows[index % paletteRows.length]),
                        borderRadius: 5,
                        borderSkipped: false,
                        maxBarThickness: 30
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: reduceMotion ? 0 : 400 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b',
                            titleColor: '#f8fafc',
                            bodyColor: '#f8fafc',
                            padding: 10,
                            callbacks: { label: context => ` ${context.parsed.x} records` }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { precision: 0, color: palette.text },
                            grid: { color: palette.grid }
                        },
                        y: {
                            ticks: { color: palette.text, font: { size: 11, weight: '500' } },
                            grid: { display: false }
                        }
                    }
                }
            });
        });
    }

    function start() {
        if (!document.querySelector('.faculty-analytics canvas[data-chart-data]')) return;
        loadChartJs().then(initCharts).catch(() => {
            // The accessible data tables are the complete fallback when scripts are unavailable.
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
