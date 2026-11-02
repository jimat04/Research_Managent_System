/** Faculty analytics charts; data tables remain available without Chart.js. */
(function() {
    'use strict';

    // ============================================================
    // Chart.js Lazy Loading
    // ============================================================
    let chartJSPromise = null;

    function loadChartJS() {
        if (window.Chart) {
            return Promise.resolve();
        }
        if (!chartJSPromise) {
            chartJSPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';
                script.onload = () => {
                    console.log('[FacultyAnalytics] Chart.js loaded');
                    resolve();
                };
                script.onerror = () => {
                    console.error('[FacultyAnalytics] Failed to load Chart.js');
                    chartJSPromise = null;
                    reject(new Error('Failed to load Chart.js'));
                };
                document.head.appendChild(script);
            });
        }
        return chartJSPromise;
    }

    // ============================================================
    // Color Palette from CSS Variables
    // ============================================================
    function getChartColors() {
        const root = document.documentElement;
        return {
            primary: getComputedStyle(root).getPropertyValue('--faculty-accent').trim() || '#1d4ed8',
            primaryHover: getComputedStyle(root).getPropertyValue('--faculty-accent-hover').trim() || '#4338ca',
            success: getComputedStyle(root).getPropertyValue('--faculty-success').trim() || '#22c55e',
            successSoft: getComputedStyle(root).getPropertyValue('--faculty-success-soft').trim() || 'rgba(34, 197, 94, 0.08)',
            warning: getComputedStyle(root).getPropertyValue('--faculty-warning').trim() || '#f59e0b',
            warningSoft: getComputedStyle(root).getPropertyValue('--faculty-warning-soft').trim() || 'rgba(245, 158, 11, 0.08)',
            danger: getComputedStyle(root).getPropertyValue('--faculty-danger').trim() || '#ef4444',
            dangerSoft: getComputedStyle(root).getPropertyValue('--faculty-danger-soft').trim() || 'rgba(239, 68, 68, 0.08)',
            muted: '#94a3b8',
            grid: 'rgba(148, 163, 184, 0.2)',
            text: '#475569',
            textMuted: '#94a3b8',
            surface: '#ffffff',
            background: '#f8fafc'
        };
    }

    // ============================================================
    // Shared Chart Configuration
    // ============================================================
    function getSharedChartOptions() {
        const colors = getChartColors();
        return {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: true,
                    backgroundColor: '#1e293b',
                    titleColor: '#f8fafc',
                    bodyColor: '#f8fafc',
                    padding: 12,
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed.x;
                            return value + (label ? ' ' + label : '');
                        }
                    }
                }
            },
            scales: {
                x: {
                    display: false,
                    grid: { display: false },
                    ticks: { display: false }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        color: colors.text,
                        font: { size: 12, weight: '500', family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif" },
                        padding: 8
                    }
                }
            },
            animation: {
                duration: 600,
                easing: 'easeOutQuart'
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        };
    }

    async function initCharts() {
        if (!document.querySelector('[data-faculty-analytics]')) return;
        const charts = {};
        try {
            await loadChartJS();
        } catch (e) {
            console.error('[FacultyAnalytics] Chart.js load failed, skipping charts');
            return;
        }

        const colors = getChartColors();
        const sharedOptions = getSharedChartOptions();

        // Projects by Status Chart
        const projectsCanvas = document.getElementById('chart-projects-status');
        let projectsData = [];
        if (projectsCanvas) {
            try {
                projectsData = JSON.parse(projectsCanvas.dataset.chartData || '[]');
            } catch (e) {
                projectsData = [];
            }
        }
        if (projectsData.length > 0) {
            charts.projects = new Chart(projectsCanvas, {
                type: 'bar',
                data: {
                    labels: projectsData.map(d => d.label),
                    datasets: [{
                        label: 'Projects',
                        data: projectsData.map(d => d.value),
                        backgroundColor: [
                            colors.primary,
                            colors.success,
                            colors.warning,
                            colors.muted
                        ].slice(0, projectsData.length),
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: sharedOptions
            });
        }

        // Publications by Year Chart
        const pubsCanvas = document.getElementById('chart-publications-years');
        let pubsData = [];
        if (pubsCanvas) {
            try {
                pubsData = JSON.parse(pubsCanvas.dataset.chartData || '[]');
            } catch (e) {
                pubsData = [];
            }
        }
        if (pubsData.length > 0) {
            charts.publications = new Chart(pubsCanvas, {
                type: 'bar',
                data: {
                    labels: pubsData.map(d => d.label),
                    datasets: [{
                        label: 'Publications',
                        data: pubsData.map(d => d.value),
                        backgroundColor: colors.primary,
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: sharedOptions
            });
        }

        // Engagement by Status Chart
        const engagementCanvas = document.getElementById('chart-engagement-status');
        let engagementData = [];
        if (engagementCanvas) {
            try {
                engagementData = JSON.parse(engagementCanvas.dataset.chartData || '[]');
            } catch (e) {
                engagementData = [];
            }
        }
        if (engagementData.length > 0) {
            charts.engagement = new Chart(engagementCanvas, {
                type: 'bar',
                data: {
                    labels: engagementData.map(d => d.label),
                    datasets: [{
                        label: 'Engagements',
                        data: engagementData.map(d => d.value),
                        backgroundColor: [
                            colors.success,
                            colors.warning,
                            colors.danger
                        ].slice(0, engagementData.length),
                        borderRadius: 6,
                        borderSkipped: false
                    }]
                },
                options: sharedOptions
            });
        }

        document.querySelectorAll('[data-faculty-analytics] .faculty-chart-wrapper').forEach(wrapper => {
            const canvas = wrapper.querySelector('canvas');
            const table = wrapper.querySelector('details');
            if (canvas && table && Chart.getChart(canvas)) table.open = false;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts, { once: true });
    } else {
        initCharts();
    }
})();
