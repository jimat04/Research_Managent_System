/**
 * Faculty Dashboard JavaScript
 *
 * Handles:
 * - Advisee matrix cell interactions (tooltips, clicks)
 * - Review queue item actions
 * - Responsive sidebar drawer
 */

(function() {
    'use strict';

    // ============================================================
    // Advisee Matrix: Cell Tooltips and Click Handling
    // ============================================================
    function initAdviseeMatrix() {
        const matrix = document.querySelector('.faculty-advisee-matrix-table');
        if (!matrix) return;

        const cells = matrix.querySelectorAll('.faculty-advisee-cell');

        cells.forEach(cell => {
            // Tooltip on hover/focus
            const projectTitle = cell.dataset.projectTitle || 'Research project';
            const student = cell.dataset.studentName || 'Student';
            const chapter = cell.closest('th, td')?.cellIndex;
            const chapterNames = ['', 'Chapter 1', 'Chapter 2', 'Chapter 3', 'Chapter 4', 'Chapter 5'];
            const chapterName = chapterNames[chapter] || `Chapter ${chapter}`;
            const status = cell.dataset.statusLabel || (
                cell.classList.contains('faculty-advisee-cell--approved') ? 'Approved' :
                cell.classList.contains('faculty-advisee-cell--in-review') ? 'In Review / Revision' : 'Not Started'
            );

            // Create tooltip
            const tooltip = document.createElement('div');
            tooltip.className = 'faculty-matrix-tooltip';
            tooltip.textContent = `${projectTitle} — ${student} — ${chapterName}: ${status}`;
            tooltip.style.cssText = `
                position: absolute;
                padding: 8px 12px;
                background: #1e293b;
                color: #f8fafc;
                font-size: 12px;
                border-radius: 6px;
                max-width: 320px;
                white-space: normal;
                text-align: center;
                line-height: 1.45;
                pointer-events: none;
                z-index: 100;
                opacity: 0;
                transform: translateY(4px);
                transition: opacity .15s ease, transform .15s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,.15);
            `;
            document.body.appendChild(tooltip);

            function showTooltip(e) {
                const rect = cell.getBoundingClientRect();
                tooltip.style.left = rect.left + window.scrollX + rect.width / 2 + 'px';
                tooltip.style.top = rect.top + window.scrollY - 40 + 'px';
                tooltip.style.transform = 'translateX(-50%) translateY(0)';
                tooltip.style.opacity = '1';
            }

            function hideTooltip() {
                tooltip.style.opacity = '0';
                tooltip.style.transform = 'translateX(-50%) translateY(4px)';
            }

            cell.addEventListener('mouseenter', showTooltip);
            cell.addEventListener('mouseleave', hideTooltip);
            cell.addEventListener('focus', showTooltip);
            cell.addEventListener('blur', hideTooltip);

            // Click handler - navigate to detail/review
            cell.style.cursor = 'pointer';
            cell.addEventListener('click', function() {
                const detailUrl = cell.dataset.detailUrl;
                if (detailUrl) {
                    window.location.href = detailUrl;
                }
            });

            // Keyboard support
            cell.setAttribute('tabindex', '0');
            cell.setAttribute('role', 'button');
            cell.setAttribute('aria-label', `${student}, ${chapterName}, ${status}`);
            cell.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    cell.click();
                }
            });
        });
    }

    // ============================================================
    // Review Queue: Action Button Handling
    // ============================================================
    function initReviewQueue() {
        const queue = document.querySelector('.faculty-review-list');
        if (!queue) return;

        // Delegate click handling for action buttons
        queue.addEventListener('click', function(e) {
            const btn = e.target.closest('.faculty-btn');
            if (!btn) return;

            const item = btn.closest('.faculty-review-item');
            if (!item) return;

            const reviewId = item.dataset.reviewId;
            const type = item.dataset.reviewType; // 'chapter', 'title', 'final', 'engagement'
            const action = btn.dataset.action; // 'review', 'approve', 'revise', 'update', 'view'

            // Track analytics event
            console.log('[FacultyDashboard] Review action:', { reviewId, type, action });

            // If it's a link, let it navigate naturally
            if (btn.tagName === 'A') {
                return; // Allow default navigation
            }

            // For button actions, could trigger AJAX or modal here
            // For now, we rely on anchor tags in the template
        });
    }

    // ============================================================
    // Sidebar Drawer (Mobile)
    // ============================================================
    function initSidebarDrawer() {
        const dashboard = document.querySelector('.faculty-dashboard');
        const sidebar = document.querySelector('.faculty-sidebar');
        const scrim = document.querySelector('.faculty-sidebar-scrim');
        const mobileBtn = document.querySelector('.faculty-mobile-menu-btn');

        if (!dashboard || !sidebar || !scrim || !mobileBtn) return;

        function openDrawer() {
            dashboard.classList.add('drawer-open');
            sidebar.classList.add('is-open');
            scrim.style.pointerEvents = 'auto';
            mobileBtn.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            dashboard.classList.remove('drawer-open');
            sidebar.classList.remove('is-open');
            scrim.style.pointerEvents = 'none';
            mobileBtn.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        mobileBtn.addEventListener('click', function() {
            if (sidebar.classList.contains('is-open')) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });

        scrim.addEventListener('click', closeDrawer);

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                closeDrawer();
            }
        });

        // Close on resize > 768px
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar.classList.contains('is-open')) {
                closeDrawer();
            }
        });
    }

    // ============================================================
    // Stat Card Hover Enhancement
    // ============================================================
    function initStatCards() {
        const cards = document.querySelectorAll('.faculty-stat-card');
        cards.forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px)';
                this.style.boxShadow = '0 20px 40px rgba(30, 64, 175, 0.12)';
            });
            card.addEventListener('mouseleave', function() {
                this.style.transform = '';
                this.style.boxShadow = '';
            });
        });
    }

    // ============================================================
    // Quick Action Buttons: Analytics
    // ============================================================
    function initQuickActions() {
        const quickActions = document.querySelectorAll('.faculty-quick-action-btn');
        quickActions.forEach(btn => {
            btn.addEventListener('click', function(e) {
                const action = this.textContent.trim().toLowerCase().replace(/\s+/g, '-');
                console.log('[FacultyDashboard] Quick action:', action);
                // Could track analytics here
            });
        });
    }

    // ============================================================
    // Initialize All Components
    // ============================================================
    function init() {
        // Wait for DOM to be ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
            return;
        }

        console.log('[FacultyDashboard] Initializing...');

        initSidebarDrawer();
        initAdviseeMatrix();
        initReviewQueue();
        initStatCards();
        initQuickActions();

        console.log('[FacultyDashboard] Initialized');
    }

    // Start initialization
    init();

    // Expose for testing/debugging
    window.FacultyDashboard = {
        initAdviseeMatrix,
        initReviewQueue,
        initSidebarDrawer
    };
})();
