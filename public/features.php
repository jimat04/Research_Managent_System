<?php
function features_escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$links = [
    'home' => 'index.php',
    'about' => 'about.php',
    'features' => 'features.php',
    'archive' => 'research-archive.php',
    'contact' => 'contact.php',
    'login' => 'login.php',
    'register' => 'login.php#register'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features | Research Management System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* ═════════════════════════════════════════════════════════════
           MODERN REDESIGN STYLES FOR FEATURES.PHP
           ═════════════════════════════════════════════════════════════ */
        :root {
            --feature-student-grad: linear-gradient(135deg, #4f46e5, #06b6d4);
            --feature-faculty-grad: linear-gradient(135deg, #7c3aed, #ec4899);
            --feature-admin-grad: linear-gradient(135deg, #0284c7, #10b981);
            --feature-shared-grad: linear-gradient(135deg, #f59e0b, #ea580c);
        }

        /* Hero Section */
        .features-hero-custom {
            position: relative;
            min-height: 80vh;
            display: flex;
            align-items: center;
            padding: 130px 40px 80px;
            background: radial-gradient(circle at 80% 20%, rgba(123, 63, 228, 0.25) 0%, transparent 50%),
                        radial-gradient(circle at 10% 80%, rgba(15, 108, 189, 0.22) 0%, transparent 45%),
                        linear-gradient(160deg, #070624 0%, #0A0833 55%, #100C45 100%);
            overflow: hidden;
        }

        .features-hero-custom::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px);
            background-size: 32px 32px;
            pointer-events: none;
            opacity: 0.6;
        }

        .hero-glow-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 18px;
            background: rgba(123, 63, 228, 0.25);
            border: 1px solid rgba(196, 169, 255, 0.35);
            border-radius: 50px;
            color: #c4a9ff;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 24px;
            box-shadow: 0 0 20px rgba(123, 63, 228, 0.3);
            backdrop-filter: blur(8px);
        }

        .hero-title-main {
            font-size: clamp(2.4rem, 4.5vw, 3.8rem);
            font-weight: 800;
            color: #ffffff;
            line-height: 1.15;
            margin-bottom: 20px;
            letter-spacing: -0.02em;
        }

        .hero-title-gradient {
            background: linear-gradient(135deg, #c4a9ff 10%, #70a6ff 60%, #38ef7d 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-desc-main {
            color: #b4b9d6;
            font-size: 1.1rem;
            line-height: 1.7;
            max-width: 620px;
            margin-bottom: 36px;
        }

        /* Hero Floating Preview Glass Card */
        .hero-mockup-card {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 24px;
            padding: 24px;
            backdrop-filter: blur(20px);
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.4), inset 0 1px 0 rgba(255, 255, 255, 0.15);
            position: relative;
            animation: floatSlow 6s ease-in-out infinite alternate;
        }

        @keyframes floatSlow {
            0% { transform: translateY(0px); }
            100% { transform: translateY(-12px); }
        }

        .mockup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            padding-bottom: 14px;
            margin-bottom: 18px;
        }

        .mockup-dots {
            display: flex;
            gap: 6px;
        }

        .mockup-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red { background: #ff5f56; }
        .dot-yellow { background: #ffbd2e; }
        .dot-green { background: #27c93f; }

        .mockup-badge {
            font-size: 0.72rem;
            font-weight: 600;
            color: #a5b4fc;
            background: rgba(99, 102, 241, 0.15);
            padding: 3px 10px;
            border-radius: 20px;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .mockup-preview-label {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mockup-sample-badge {
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #cbd5e1;
            background: rgba(255, 255, 255, 0.06);
            padding: 3px 8px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 20px;
        }

        .mockup-item {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s ease;
        }

        .mockup-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(4px);
        }

        .mockup-item-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .mockup-item-text h4 {
            color: #ffffff;
            font-size: 0.88rem;
            margin-bottom: 2px;
        }

        .mockup-item-text p {
            color: #94a3b8;
            font-size: 0.74rem;
            margin: 0;
        }

        .mockup-status-pill {
            margin-left: auto;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .pill-done { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
        .pill-active { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
        .pill-review { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }

        /* Quick Stat Highlights Bar */
        .features-stat-bar {
            background: #ffffff;
            border-bottom: 1px solid var(--border);
            padding: 30px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        }

        .stat-bar-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }

        .stat-bar-item {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 10px 16px;
            border-right: 1px solid #f1f5f9;
        }

        .stat-bar-item:last-child {
            border-right: none;
        }

        .stat-bar-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #f0ebff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .stat-bar-data h3 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 2px 0;
        }

        .stat-bar-data p {
            font-size: 0.8rem;
            color: var(--text-light);
            margin: 0;
        }

        /* Modern Feature Cards Grid */
        .modern-feature-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 30px;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        }

        .modern-feature-card:hover {
            transform: translateY(-8px);
            border-color: var(--primary-light);
            box-shadow: 0 20px 40px rgba(91, 30, 188, 0.1);
        }

        .modern-feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: var(--card-gradient, linear-gradient(90deg, var(--primary), var(--secondary)));
            opacity: 0;
            transition: opacity 0.3s;
        }

        .modern-feature-card:hover::before {
            opacity: 1;
        }

        .card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .feature-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: #f4f0ff;
            color: var(--primary);
            box-shadow: 0 4px 10px rgba(91, 30, 188, 0.08);
            transition: transform 0.3s ease;
        }

        .modern-feature-card:hover .feature-icon-box {
            transform: scale(1.1) rotate(3deg);
        }

        .role-tag-badge {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 4px 10px;
            border-radius: 50px;
        }

        .badge-student { background: #EEF2FF; color: #4F46E5; }
        .badge-faculty { background: #FAF5FF; color: #7C3AED; }
        .badge-admin { background: #F0FDF4; color: #16A34A; }
        .badge-shared { background: #FFFBEB; color: #D97706; }

        .modern-feature-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 12px;
            line-height: 1.3;
        }

        .modern-feature-card p {
            color: var(--text-light);
            font-size: 0.92rem;
            line-height: 1.6;
            margin-bottom: 24px;
            flex-grow: 1;
        }

        .feature-points-list {
            list-style: none;
            margin-bottom: 20px;
            padding: 0;
        }

        .feature-points-list li {
            font-size: 0.82rem;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .feature-points-list li span {
            color: var(--success);
            font-weight: bold;
        }

        .feature-card-footer {
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-link-btn {
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: gap 0.2s ease;
        }

        .card-link-btn:hover {
            color: var(--primary-dark);
            gap: 10px;
        }

        .feature-detail-trigger {
            padding: 0;
            border: 0;
            background: transparent;
            font: inherit;
            cursor: pointer;
        }

        body.feature-modal-open {
            overflow: hidden;
        }

        .feature-detail-modal {
            width: min(720px, calc(100% - 32px));
            max-height: min(86vh, 780px);
            margin: auto;
            padding: 0;
            overflow: hidden;
            border: 0;
            border-radius: 24px;
            background: #ffffff;
            color: var(--text-dark);
            box-shadow: 0 32px 90px rgba(15, 10, 45, 0.32);
        }

        .feature-detail-modal[open] {
            animation: featureModalIn 0.24s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .feature-detail-modal::backdrop {
            background: rgba(10, 8, 35, 0.62);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        @keyframes featureModalIn {
            from { opacity: 0; transform: translateY(16px) scale(0.98); }
            to { opacity: 1; transform: none; }
        }

        .feature-detail-modal__scroll {
            max-height: min(86vh, 780px);
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .feature-detail-modal__hero {
            position: relative;
            padding: 34px 72px 30px 34px;
            overflow: hidden;
            background: linear-gradient(135deg, #17113b 0%, #362071 58%, #174f83 100%);
            color: #ffffff;
        }

        .feature-detail-modal__hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            right: -75px;
            bottom: -135px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
        }

        .feature-detail-modal__identity {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .feature-detail-modal__icon {
            display: grid;
            place-items: center;
            width: 58px;
            height: 58px;
            flex: 0 0 58px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 17px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 1.65rem;
        }

        .feature-detail-modal__role {
            display: inline-flex;
            margin-bottom: 7px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #e8e3ff;
            font-size: 0.68rem;
            font-weight: 750;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .feature-detail-modal__title {
            margin: 0;
            color: #ffffff;
            font-size: clamp(1.55rem, 3vw, 2.1rem);
            line-height: 1.15;
        }

        .feature-detail-modal__close {
            position: absolute;
            z-index: 2;
            top: 20px;
            right: 20px;
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            font-size: 1.35rem;
            cursor: pointer;
        }

        .feature-detail-modal__close:hover,
        .feature-detail-modal__close:focus-visible {
            background: rgba(255, 255, 255, 0.2);
            outline: none;
        }

        .feature-detail-modal__body {
            padding: 30px 34px 34px;
        }

        .feature-detail-modal__eyebrow {
            margin-bottom: 8px;
            color: var(--primary);
            font-size: 0.72rem;
            font-weight: 750;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .feature-detail-modal__description {
            margin: 0 0 26px;
            color: var(--text-light);
            font-size: 1rem;
            line-height: 1.75;
        }

        .feature-detail-modal__capabilities {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 0 0 26px;
            padding: 0;
            list-style: none;
        }

        .feature-detail-modal__capabilities li {
            min-height: 92px;
            padding: 16px;
            border: 1px solid #e8e6f2;
            border-radius: 14px;
            background: #faf9ff;
            color: #3c3654;
            font-size: 0.86rem;
            font-weight: 650;
            line-height: 1.45;
        }

        .feature-detail-modal__capabilities li::before {
            content: '✓';
            display: block;
            margin-bottom: 9px;
            color: var(--success);
            font-size: 1rem;
            font-weight: 800;
        }

        .feature-detail-modal__use-case {
            margin-bottom: 28px;
            padding: 17px 18px;
            border-left: 3px solid var(--primary);
            border-radius: 0 12px 12px 0;
            background: #f5f2ff;
            color: #514a68;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .feature-detail-modal__actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding-top: 22px;
            border-top: 1px solid #eceaf3;
        }

        .feature-detail-modal__workflow {
            color: var(--primary);
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
        }

        .feature-detail-modal__workflow:hover {
            text-decoration: underline;
        }

        /* Section Headings */
        .features-section-group {
            margin-bottom: 70px;
        }

        /* Five-card suites use a balanced 3 + 2 composition instead of
           leaving a single card stranded at the lower-left of a four-column row. */
        .features-grid--five {
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .features-grid--five > .modern-feature-card {
            grid-column: span 2;
        }

        .features-grid--five > .modern-feature-card:nth-last-child(2) {
            grid-column: 2 / span 2;
        }

        .features-grid--five > .modern-feature-card:last-child {
            grid-column: 4 / span 2;
        }

        .group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 2px solid #edf2f7;
        }

        .group-title-left h3 {
            font-size: 1.45rem;
            color: var(--primary-dark);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-title-left p {
            color: var(--text-light);
            font-size: 0.88rem;
            margin: 0;
        }

        .group-count-pill {
            background: #f1f5f9;
            color: var(--text-light);
            font-size: 0.78rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 20px;
        }

        /* Deep Dive Interactive Showcase Section */
        .deep-dive-section {
            background: linear-gradient(180deg, #F8F9FE 0%, #FFFFFF 100%);
            padding: 90px 20px;
            border-top: 1px solid var(--border);
        }

        .deep-dive-container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .deep-dive-context-note {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 8px 13px;
            border: 1px solid #ded7f5;
            border-radius: 10px;
            background: #faf8ff;
            color: #625a78;
            font-size: 0.8rem;
            line-height: 1.45;
        }

        .deep-dive-anchor-alias {
            display: block;
            scroll-margin-top: 96px;
        }

        .deep-dive-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 48px;
            margin-bottom: 48px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 48px;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
        }

        .deep-dive-card:nth-child(even) {
            grid-template-columns: 1fr 1fr;
            direction: rtl;
        }

        .deep-dive-card:nth-child(even) .deep-dive-info,
        .deep-dive-card:nth-child(even) .deep-dive-visual {
            direction: ltr;
        }

        .deep-dive-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 30px;
            background: #f0ebff;
            color: var(--primary);
            margin-bottom: 16px;
        }

        .deep-dive-info h2 {
            font-size: 1.85rem;
            color: var(--primary-dark);
            margin-bottom: 16px;
            line-height: 1.25;
        }

        .deep-dive-info p {
            color: var(--text-light);
            font-size: 0.96rem;
            line-height: 1.8;
            margin-bottom: 24px;
        }

        .deep-feature-specs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 20px;
        }

        .spec-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .spec-item i {
            font-style: normal;
            font-size: 1.1rem;
        }

        /* Modern UI Visual Box */
        .interactive-preview-box {
            background: linear-gradient(135deg, #1e1b4b 0%, #0f172a 100%);
            border-radius: 18px;
            padding: 24px;
            color: #ffffff;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
        }

        .preview-box-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .preview-box-header span {
            font-size: 0.8rem;
            font-weight: 600;
            color: #cbd5e1;
        }

        .interactive-mock-item {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .interactive-mock-item:last-child {
            margin-bottom: 0;
        }

        .mock-progress-track {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            margin-top: 10px;
            overflow: hidden;
        }

        .mock-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #38ef7d, #11998e);
            border-radius: 4px;
        }

        /* Alignment Banner with EARIST Manual */
        .earist-alignment-banner {
            background: linear-gradient(135deg, #0A0833 0%, #1e1040 100%);
            color: white;
            padding: 80px 20px;
            position: relative;
            overflow: hidden;
        }

        .earist-alignment-banner::after {
            content: '🏛️';
            position: absolute;
            right: 5%;
            bottom: -20px;
            font-size: 15rem;
            opacity: 0.05;
            pointer-events: none;
        }

        .earist-container {
            max-width: 1100px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        .alignment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-top: 40px;
        }

        .alignment-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 24px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .alignment-card:hover {
            background: rgba(255, 255, 255, 0.09);
            transform: translateY(-4px);
            border-color: rgba(196, 169, 255, 0.3);
        }

        .alignment-icon {
            font-size: 1.8rem;
            margin-bottom: 12px;
        }

        .alignment-card h4 {
            color: #ffffff;
            font-size: 1.05rem;
            margin-bottom: 8px;
        }

        .alignment-card p {
            color: #b4b9d6;
            font-size: 0.85rem;
            line-height: 1.6;
            margin: 0;
        }

        /* Modern CTA Banner */
        .modern-cta-section {
            background: linear-gradient(135deg, var(--primary-dark), #5B1EBC, #0F6CBD);
            padding: 90px 20px;
            text-align: center;
            color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .modern-cta-section::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: pulseGlow 10s ease-in-out infinite;
        }

        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }

        .cta-content-wrapper {
            position: relative;
            z-index: 2;
            max-width: 750px;
            margin: 0 auto;
        }

        .cta-content-wrapper h2 {
            font-size: clamp(2rem, 3.5vw, 2.8rem);
            font-weight: 800;
            margin-bottom: 16px;
            line-height: 1.2;
        }

        .cta-content-wrapper p {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.85);
            margin-bottom: 36px;
            line-height: 1.6;
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .stat-bar-container {
                grid-template-columns: repeat(2, 1fr);
            }
            .features-grid--five {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .features-grid--five > .modern-feature-card,
            .features-grid--five > .modern-feature-card:nth-last-child(2) {
                grid-column: auto;
            }
            .features-grid--five > .modern-feature-card:last-child {
                grid-column: 1 / -1;
                width: calc((100% - 24px) / 2);
                justify-self: center;
            }
            .deep-dive-card, .deep-dive-card:nth-child(even) {
                grid-template-columns: 1fr;
                gap: 32px;
                padding: 32px 24px;
            }
        }

        @media (max-width: 768px) {
            .features-hero-custom {
                padding: 110px 20px 60px;
                text-align: center;
            }
            .hero-actions {
                justify-content: center;
            }
            .hero-desc-main {
                margin-left: auto;
                margin-right: auto;
            }
            .stat-bar-container {
                grid-template-columns: 1fr;
            }
            .features-grid--five {
                grid-template-columns: 1fr;
            }
            .features-grid--five > .modern-feature-card:last-child {
                grid-column: auto;
                width: auto;
                justify-self: stretch;
            }
            .stat-bar-item {
                border-right: none;
                border-bottom: 1px solid #f1f5f9;
                padding-bottom: 14px;
            }
            .stat-bar-item:last-child {
                border-bottom: none;
            }
            .deep-feature-specs {
                grid-template-columns: 1fr;
            }
            .feature-detail-modal__capabilities {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .feature-detail-modal {
                width: 100%;
                max-width: none;
                max-height: 92dvh;
                margin: auto 0 0;
                border-radius: 24px 24px 0 0;
            }
            .feature-detail-modal__scroll {
                max-height: 92dvh;
            }
            .feature-detail-modal__hero {
                padding: 28px 58px 25px 22px;
            }
            .feature-detail-modal__identity {
                align-items: flex-start;
            }
            .feature-detail-modal__body {
                padding: 24px 22px 28px;
            }
            .feature-detail-modal__actions {
                align-items: stretch;
                flex-direction: column-reverse;
            }
            .feature-detail-modal__actions .btn,
            .feature-detail-modal__workflow {
                width: 100%;
                justify-content: center;
                text-align: center;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .feature-detail-modal[open] {
                animation: none;
            }
        }
    </style>
</head>
<body>
    <!-- TOP NAVIGATION -->
    <nav class="navbar">
        <div class="nav-brand">
            <div class="nav-logo nav-logo-image">
                <img src="../photos/rms-logo.png" alt="" width="42" height="42" aria-hidden="true">
            </div>
            <div class="nav-brand-text">
                <span class="brand-main">Research<br>Management</span>
                <span class="brand-sub">System</span>
            </div>
        </div>
        <ul class="nav-links">
            <li><a href="<?php echo features_escape($links['home']); ?>">Home</a></li>
            <li><a href="<?php echo features_escape($links['about']); ?>">About</a></li>
            <li><a href="<?php echo features_escape($links['features']); ?>" class="active">Features</a></li>
            <li><a href="<?php echo features_escape($links['archive']); ?>">Research Archive</a></li>
            <li><a href="<?php echo features_escape($links['contact']); ?>">Contact</a></li>
        </ul>
        <div class="nav-actions">
            <a href="<?php echo features_escape($links['login']); ?>" class="btn btn-secondary btn-sm">Login</a>
            <a href="<?php echo features_escape($links['register']); ?>" class="btn btn-primary btn-sm">Register</a>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="features-hero-custom">
        <div class="hero-container" style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr); gap: 48px; align-items: center; width: 100%;">
            <div class="hero-content hero-entrance">
                <div class="hero-glow-badge">
                    <span>✨</span> Next-Generation Academic Suite
                </div>
                <h1 class="hero-title-main">
                    Engineered for <br>
                    <span class="hero-title-gradient">Effortless Research</span>
                </h1>
                <p class="hero-desc-main">
                    Explore one student/faculty research workspace with locked versioned submissions, a typed admin/staff review queue, sequential chapters, computed completion, and research outputs.
                </p>
                <div class="hero-actions" style="display: flex; gap: 14px; flex-wrap: wrap;">
                    <a href="<?php echo features_escape($links['login']); ?>" class="btn btn-primary btn-lg" style="box-shadow: 0 10px 24px rgba(91,30,188,0.4);">
                        Get Started Free ⚡
                    </a>
                    <a href="#feature-catalog" class="btn btn-secondary btn-lg">
                        Explore Modules 🧭
                    </a>
                </div>
            </div>

            <!-- RMS Workflow Preview -->
            <div class="hero-mockup-card">
                <div class="mockup-header">
                    <div class="mockup-dots">
                        <div class="mockup-dot dot-red"></div>
                        <div class="mockup-dot dot-yellow"></div>
                        <div class="mockup-dot dot-green"></div>
                    </div>
                    <div class="mockup-preview-label">
                        <div class="mockup-badge">RMS Workflow Preview</div>
                        <span class="mockup-sample-badge">Sample data</span>
                    </div>
                </div>
                
                <div class="mockup-item">
                    <div class="mockup-item-icon" style="background: rgba(79, 70, 229, 0.2); color: #818cf8;">📄</div>
                    <div class="mockup-item-text">
                        <h4>Ch. 1 - 3 Methodology</h4>
                        <p>AI IoT Smart Grid Study</p>
                    </div>
                    <span class="mockup-status-pill pill-done">Approved</span>
                </div>

                <div class="mockup-item">
                    <div class="mockup-item-icon" style="background: rgba(236, 72, 153, 0.2); color: #f472b6;">💬</div>
                    <div class="mockup-item-text">
                        <h4>Review Comments</h4>
                        <p>Return with comments, revise, and resubmit</p>
                    </div>
                    <span class="mockup-status-pill pill-review">Action Req.</span>
                </div>

                <div class="mockup-item">
                    <div class="mockup-item-icon" style="background: rgba(16, 185, 129, 0.2); color: #34d399;">🗓️</div>
                    <div class="mockup-item-text">
                        <h4>Chapter 2 Unlocked</h4>
                        <p>Chapter 1 approved • version history saved</p>
                    </div>
                    <span class="mockup-status-pill pill-active">Confirmed</span>
                </div>
            </div>
        </div>
    </section>

    <!-- KEY STATS / HIGHLIGHTS BAR -->
    <div class="features-stat-bar">
        <div class="stat-bar-container">
            <div class="stat-bar-item">
                <div class="stat-bar-icon">⚡</div>
                <div class="stat-bar-data">
                    <h3>100% Digital</h3>
                    <p>Paperless submission flow</p>
                </div>
            </div>
            <div class="stat-bar-item">
                <div class="stat-bar-icon">🛡️</div>
                <div class="stat-bar-data">
                    <h3>Role-Secured</h3>
                    <p>Admin-granted view/manage access</p>
                </div>
            </div>
            <div class="stat-bar-item">
                <div class="stat-bar-icon">📊</div>
                <div class="stat-bar-data">
                    <h3>Real-time Logs</h3>
                    <p>Transparent review history</p>
                </div>
            </div>
            <div class="stat-bar-item">
                <div class="stat-bar-icon">🏛️</div>
                <div class="stat-bar-data">
                    <h3>Manual Aligned</h3>
                    <p>EARIST 2015 institutional rule</p>
                </div>
            </div>
        </div>
    </div>

    <!-- MAIN ROLE-BASED FEATURE SHOWCASE -->
    <section class="section features-bg" id="feature-catalog" style="padding: 70px 20px;" data-reveal>
        <div class="section-header" style="text-align: center; max-width: 760px; margin: 0 auto 40px;">
            <div class="section-tag" style="background: rgba(91,30,188,0.1); color: var(--primary); font-weight: 700; display: inline-block; padding: 4px 16px; border-radius: 50px; font-size: 0.8rem; margin-bottom: 12px;">
                🧩 Comprehensive Capabilities
            </div>
            <h2 class="section-title" style="font-size: 2.2rem; margin-bottom: 12px;">Tailored for Every Academic Role</h2>
            <p class="section-desc" style="color: var(--text-light); font-size: 1rem;">
                Explore dedicated toolsets built purposefully to accelerate research delivery for students, evaluators, and department heads.
            </p>
        </div>

        <div class="feature-groups" style="max-width: 1200px; margin: 0 auto;">
            
            <!-- STUDENT FEATURES GROUP -->
            <div class="features-section-group" id="student-suite">
                <div class="group-header">
                    <div class="group-title-left">
                        <h3><span>🎓</span> Student Research Suite</h3>
                        <p>Write, organize, and submit manuscripts with peace of mind</p>
                    </div>
                    <span class="group-count-pill">5 Core Tools</span>
                </div>
                <div class="features-grid features-grid--five">
                    <?php
                    $student_features = [
                        ['icon' => '📄', 'title' => 'Unified Title Workspace', 'desc' => 'Draft a research title, save changes, and submit it through the same shared workflow used by faculty.', 'points' => ['Draft saving', 'Shared student/faculty flow', 'Team author attribution'], 'anchor' => 'feature-submission', 'badge_class' => 'badge-student', 'role_name' => 'Student'],
                        ['icon' => '🔒', 'title' => 'Locked Versioned Submissions', 'desc' => 'Submission locks the title and saves a version snapshot; revisions create a full history rather than replacing prior work.', 'points' => ['Locked submitted version', 'Version snapshots', 'Full revision history'], 'anchor' => 'feature-submission', 'badge_class' => 'badge-student', 'role_name' => 'Student'],
                        ['icon' => '📝', 'title' => 'Sequential Chapter Submissions', 'desc' => 'Submit Chapters 1-5 in order; the next chapter becomes available after the current one is approved.', 'points' => ['Five chapter stages', 'Approval-gated unlocks', 'Same return-and-revise loop'], 'anchor' => 'feature-submission', 'badge_class' => 'badge-student', 'role_name' => 'Student'],
                        ['icon' => '📊', 'title' => 'Computed Research Progress', 'desc' => 'Follow title approval and chapter progress, with completion calculated automatically after all five approvals.', 'points' => ['Title approval stage', 'Chapter status history', 'Computed completion'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-student', 'role_name' => 'Student'],
                        ['icon' => '🔔', 'title' => 'Review & Revision Alerts', 'desc' => 'Receive updates when a version is approved or returned with comments, and when action is needed.', 'points' => ['In-app alerts', 'Revision comments', 'Resubmission updates'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-student', 'role_name' => 'Student']
                    ];
                    foreach ($student_features as $feature): ?>
                        <div class="modern-feature-card" style="--card-gradient: var(--feature-student-grad);">
                            <div class="card-header-row">
                                <div class="feature-icon-box"><?php echo features_escape($feature['icon']); ?></div>
                                <span class="role-tag-badge <?php echo features_escape($feature['badge_class']); ?>"><?php echo features_escape($feature['role_name']); ?></span>
                            </div>
                            <h3><?php echo features_escape($feature['title']); ?></h3>
                            <p><?php echo features_escape($feature['desc']); ?></p>
                            <ul class="feature-points-list">
                                <?php foreach ($feature['points'] as $point): ?>
                                    <li><span>✓</span> <?php echo features_escape($point); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="feature-card-footer">
                                <a class="card-link-btn" href="#<?php echo features_escape($feature['anchor']); ?>">Deep Dive Details →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- FACULTY WORKSPACE GROUP -->
            <div class="features-section-group" id="faculty-suite">
                <div class="group-header">
                    <div class="group-title-left">
                        <h3><span>👨‍🏫</span> Faculty Workspace</h3>
                        <p>The same research submission workflow as students, with optional admin-granted review access</p>
                    </div>
                    <span class="group-count-pill">5 Core Tools</span>
                </div>
                <div class="features-grid features-grid--five">
                    <?php
                    $review_features = [
                        ['icon' => '📄', 'title' => 'Shared Research Workspace', 'desc' => 'Draft and submit titles and chapters through the same workflow available to students.', 'points' => ['Title drafts', 'Chapter submissions', 'Project collaboration'], 'anchor' => 'feature-submission', 'badge_class' => 'badge-faculty', 'role_name' => 'Faculty'],
                        ['icon' => '🔒', 'title' => 'Locked Version History', 'desc' => 'Submitted work stays locked as a snapshot while later revisions preserve earlier versions.', 'points' => ['Submission locks', 'Version snapshots', 'Unlimited revision cycles'], 'anchor' => 'feature-submission', 'badge_class' => 'badge-faculty', 'role_name' => 'Faculty'],
                        ['icon' => '💬', 'title' => 'Return with Review Comments', 'desc' => 'When an administrator grants review permissions, faculty can review assigned pages and return work with comments.', 'points' => ['Admin-granted access', 'View/manage permission', 'Comments on returned work'], 'anchor' => 'feature-review', 'badge_class' => 'badge-faculty', 'role_name' => 'Faculty'],
                        ['icon' => '👥', 'title' => 'Collaborative Research', 'desc' => 'Manage research teams and follow shared title and chapter progress with co-researchers.', 'points' => ['Co-researcher linking', 'Shared project context', 'Progress visibility'], 'anchor' => 'feature-review', 'badge_class' => 'badge-faculty', 'role_name' => 'Faculty'],
                        ['icon' => '📈', 'title' => 'Computed Progression', 'desc' => 'Track title approval and sequential chapter progress through automatically computed completion.', 'points' => ['Title stage', 'Five chapter stages', 'Ongoing/completed views'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-faculty', 'role_name' => 'Faculty']
                    ];
                    foreach ($review_features as $feature): ?>
                        <div class="modern-feature-card" style="--card-gradient: var(--feature-faculty-grad);">
                            <div class="card-header-row">
                                <div class="feature-icon-box"><?php echo features_escape($feature['icon']); ?></div>
                                <span class="role-tag-badge <?php echo features_escape($feature['badge_class']); ?>"><?php echo features_escape($feature['role_name']); ?></span>
                            </div>
                            <h3><?php echo features_escape($feature['title']); ?></h3>
                            <p><?php echo features_escape($feature['desc']); ?></p>
                            <ul class="feature-points-list">
                                <?php foreach ($feature['points'] as $point): ?>
                                    <li><span>✓</span> <?php echo features_escape($point); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="feature-card-footer">
                                <a class="card-link-btn" href="#<?php echo features_escape($feature['anchor']); ?>">Deep Dive Details →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ADMIN & RESEARCH OFFICE GROUP -->
            <div class="features-section-group" id="admin-suite">
                <div class="group-header">
                    <div class="group-title-left">
                        <h3><span>🏛️</span> Admin &amp; Research Office</h3>
                        <p>Typed review, per-page staff permissions, computed progression, and research outputs</p>
                    </div>
                    <span class="group-count-pill">5 Core Tools</span>
                </div>
                <div class="features-grid">
                    <?php
                    $admin_features = [
                        ['icon' => '🧑‍💼', 'title' => 'Typed Review Queue', 'desc' => 'Review title and chapter submissions, approve them, or return them with comments for revision.', 'points' => ['Separate title/chapter review', 'Approve or return', 'Version history'], 'anchor' => 'feature-review', 'badge_class' => 'badge-admin', 'role_name' => 'Admin'],
                        ['icon' => '🔐', 'title' => '5 × 3 Permission Matrix', 'desc' => 'Grant research staff view or manage access per sub-page across five modules.', 'points' => ['Capacity Building', 'Research Development', 'Implementation & Outputs', 'Publication & Dissemination', 'IP & Utilization'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-admin', 'role_name' => 'Admin'],
                        ['icon' => '📊', 'title' => 'Computed Progression', 'desc' => 'Track ongoing and completed research from approved titles and chapter progress.', 'points' => ['Ongoing view', 'Completed view', 'Completion from chapter approvals'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-admin', 'role_name' => 'Admin'],
                        ['icon' => '🗂️', 'title' => 'Publications & Repository', 'desc' => 'Track journal and conference publications and curate research repository records.', 'points' => ['Journal publications', 'Conference presentations', 'Repository records'], 'anchor' => 'feature-archive', 'badge_class' => 'badge-admin', 'role_name' => 'Admin'],
                        ['icon' => '©️', 'title' => 'Copyright & IP Records', 'desc' => 'Register and track copyrights and other intellectual-property outputs tied to research.', 'points' => ['Copyright registrations', 'Research relationships', 'IP utilization'], 'anchor' => 'feature-archive', 'badge_class' => 'badge-admin', 'role_name' => 'Admin']
                    ];
                    foreach ($admin_features as $feature): ?>
                        <div class="modern-feature-card" style="--card-gradient: var(--feature-admin-grad);">
                            <div class="card-header-row">
                                <div class="feature-icon-box"><?php echo features_escape($feature['icon']); ?></div>
                                <span class="role-tag-badge <?php echo features_escape($feature['badge_class']); ?>"><?php echo features_escape($feature['role_name']); ?></span>
                            </div>
                            <h3><?php echo features_escape($feature['title']); ?></h3>
                            <p><?php echo features_escape($feature['desc']); ?></p>
                            <ul class="feature-points-list">
                                <?php foreach ($feature['points'] as $point): ?>
                                    <li><span>✓</span> <?php echo features_escape($point); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="feature-card-footer">
                                <a class="card-link-btn" href="#<?php echo features_escape($feature['anchor']); ?>">Deep Dive Details →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- SHARED / PLATFORM FEATURES GROUP -->
            <div class="features-section-group" id="shared-suite">
                <div class="group-header">
                    <div class="group-title-left">
                        <h3><span>🌐</span> Shared Platform Features</h3>
                        <p>High-performance fundamentals engineered for modern browsers</p>
                    </div>
                    <span class="group-count-pill">5 Core Tools</span>
                </div>
                <div class="features-grid features-grid--five">
                    <?php
                    $shared_features = [
                        ['icon' => '🔐', 'title' => 'Secure Authentication', 'desc' => 'Role-based access control with encrypted sessions for students, faculty, and administrators.', 'points' => ['Encrypted sessions', 'Role-based gates', 'Audit logging'], 'anchor' => 'feature-archive', 'badge_class' => 'badge-shared', 'role_name' => 'Platform'],
                        ['icon' => '🔔', 'title' => 'Smart Notification Engine', 'desc' => 'Context-aware alerts for title and chapter decisions, returned comments, resubmissions, and system updates.', 'points' => ['Priority filtering', 'In-app + email', 'Read/unread tracking'], 'anchor' => 'feature-analytics', 'badge_class' => 'badge-shared', 'role_name' => 'Platform'],
                        ['icon' => '💬', 'title' => 'Real-Time Direct Messaging', 'desc' => 'Built-in messaging for researchers and authorized staff working across research records.', 'points' => ['Threaded conversations', 'Document attachments', 'Unread badges'], 'anchor' => 'feature-review', 'badge_class' => 'badge-shared', 'role_name' => 'Platform'],
                        ['icon' => '📅', 'title' => 'Universal Academic Calendar', 'desc' => 'Keep submission and review deadlines visible alongside shared academic dates.', 'points' => ['Interactive calendar view', 'Export to iCal', 'Custom deadline filters'], 'anchor' => 'feature-review', 'badge_class' => 'badge-shared', 'role_name' => 'Platform'],
                        ['icon' => '📱', 'title' => 'Adaptive Responsive UI', 'desc' => 'Pixel-perfect experience across desktop monitors, laptops, iPads, and mobile smartphones.', 'points' => ['Touch-friendly UI', 'Fluid viewport scaling', 'Accessible typography'], 'anchor' => 'feature-archive', 'badge_class' => 'badge-shared', 'role_name' => 'Platform']
                    ];
                    foreach ($shared_features as $feature): ?>
                        <div class="modern-feature-card" style="--card-gradient: var(--feature-shared-grad);">
                            <div class="card-header-row">
                                <div class="feature-icon-box"><?php echo features_escape($feature['icon']); ?></div>
                                <span class="role-tag-badge <?php echo features_escape($feature['badge_class']); ?>"><?php echo features_escape($feature['role_name']); ?></span>
                            </div>
                            <h3><?php echo features_escape($feature['title']); ?></h3>
                            <p><?php echo features_escape($feature['desc']); ?></p>
                            <ul class="feature-points-list">
                                <?php foreach ($feature['points'] as $point): ?>
                                    <li><span>✓</span> <?php echo features_escape($point); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="feature-card-footer">
                                <a class="card-link-btn" href="#<?php echo features_escape($feature['anchor']); ?>">Deep Dive Details →</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </section>

    <dialog class="feature-detail-modal" id="featureDetailModal" aria-labelledby="featureDetailTitle" aria-describedby="featureDetailDescription">
        <div class="feature-detail-modal__scroll">
            <header class="feature-detail-modal__hero">
                <button class="feature-detail-modal__close" type="button" aria-label="Close feature details">&times;</button>
                <div class="feature-detail-modal__identity">
                    <div class="feature-detail-modal__icon" id="featureDetailIcon" aria-hidden="true"></div>
                    <div>
                        <span class="feature-detail-modal__role" id="featureDetailRole"></span>
                        <h2 class="feature-detail-modal__title" id="featureDetailTitle"></h2>
                    </div>
                </div>
            </header>
            <div class="feature-detail-modal__body">
                <div class="feature-detail-modal__eyebrow">What this capability does</div>
                <p class="feature-detail-modal__description" id="featureDetailDescription"></p>

                <div class="feature-detail-modal__eyebrow">Core capabilities</div>
                <ul class="feature-detail-modal__capabilities" id="featureDetailCapabilities"></ul>

                <div class="feature-detail-modal__eyebrow">Where it fits</div>
                <div class="feature-detail-modal__use-case" id="featureDetailUseCase"></div>

                <div class="feature-detail-modal__actions">
                    <a class="feature-detail-modal__workflow" id="featureDetailWorkflow" href="#">View related workflow &rarr;</a>
                    <a class="btn btn-primary" href="<?php echo features_escape($links['login']); ?>">Access RMS Portal</a>
                </div>
            </div>
        </div>
    </dialog>

    <!-- EARIST RESEARCH MANUAL ALIGNMENT -->
    <section class="earist-alignment-banner" data-reveal>
        <div class="earist-container">
            <div style="text-align: center; max-width: 800px; margin: 0 auto 30px;">
                <span style="background: rgba(255,255,255,0.12); color: #c4a9ff; font-weight: 600; padding: 4px 16px; border-radius: 50px; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 1px;">
                    🏛️ Institutional Alignment
                </span>
                <h2 style="font-size: 2.3rem; margin-top: 14px; font-weight: 800;">Built Directly for EARIST Research Protocol</h2>
                <p style="color: #b4b9d6; font-size: 1rem; margin-top: 10px;">The 2015 Research Manual is an institutional source reference; RMS presents its current v3 submission, review, chapter, and output workflows.</p>
            </div>
            <div class="alignment-grid">
                <div class="alignment-card">
                    <div class="alignment-icon">👥</div>
                    <h4>Structured Review Queue</h4>
                    <p>Admin and staff review locked title and chapter versions, approving work or returning it with comments.</p>
                </div>
                <div class="alignment-card">
                    <div class="alignment-icon">📋</div>
                    <h4>Chapter Sequencing</h4>
                    <p>Chapter 1 follows title approval; every next chapter unlocks after approval of the current one.</p>
                </div>
                <div class="alignment-card">
                    <div class="alignment-icon">📊</div>
                    <h4>Computed Progression</h4>
                    <p>Ongoing and completed views reflect title decisions and chapter approvals; completion is computed from all five approvals.</p>
                </div>
                <div class="alignment-card">
                    <div class="alignment-icon">🎓</div>
                    <h4>Publication &amp; IP Outputs</h4>
                    <p>Track journal and conference publications, repository records, and copyright or IP registrations.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- DEEP DIVE INTERACTIVE FEATURE SPOTLIGHTS -->
    <section class="deep-dive-section" data-reveal>
        <div class="deep-dive-container">

            <div class="section-header" style="text-align: center; max-width: 700px; margin: 0 auto 60px;">
                <div class="section-tag" style="background: #F0EBFF; color: var(--primary); font-weight: 700; display: inline-block; padding: 4px 16px; border-radius: 50px; font-size: 0.8rem; margin-bottom: 12px;">
                    🔍 Detailed Spotlights
                </div>
                <h2 class="section-title" style="font-size: 2.2rem;">How RMS Powers Your Workflow</h2>
                <p class="section-desc">Take a closer look at the key modules that keep academic teams focused and aligned.</p>
                <p class="deep-dive-context-note"><span aria-hidden="true">ℹ️</span> Selected highlights from the unified title, chapter, review, and output workflow.</p>
            </div>

            <!-- Spotlight 1: Submission Flow -->
            <div class="deep-dive-card" id="feature-submission">
                <div class="deep-dive-info">
                    <span class="deep-dive-pill">Spotlight 01 • Submission</span>
                    <h2>Frictionless Research Submission & Versioning</h2>
                    <p>Students and faculty share one workspace to draft a title, save changes, and submit it as a locked version snapshot. Revisions can be returned with comments and resubmitted through unlimited cycles, preserving full history.</p>
                    <div class="deep-feature-specs">
                        <div class="spec-item"><i>💾</i> Auto-draft recovery</div>
                        <div class="spec-item"><i>📑</i> PDF/Word compatibility</div>
                        <div class="spec-item"><i>🏷️</i> Keyword classification</div>
                        <div class="spec-item"><i>🕒</i> Historical chapter logs</div>
                    </div>
                </div>
                <div class="deep-dive-visual">
                    <div class="interactive-preview-box">
                        <div class="preview-box-header">
                            <span>📄 Shared Research Workspace</span>
                            <span style="color: #4ade80;">● Auto-saved</span>
                        </div>
                        <div class="interactive-mock-item">
                            <div style="font-size: 0.82rem; font-weight: 600; color: #e2e8f0; margin-bottom: 4px;">Title: Smart Agritech Soil Sensor Network</div>
                            <div style="font-size: 0.72rem; color: #94a3b8;">Authors: J. Dela Cruz, M. Santos (BSCS)</div>
                            <div class="mock-progress-track">
                                <div class="mock-progress-fill" style="width: 75%;"></div>
                            </div>
                        </div>
                        <div class="interactive-mock-item" style="background: rgba(255,255,255,0.02); display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 0.75rem; color: #cbd5e1;">📁 Chapter_1_to_3_Final.docx</span>
                            <span style="font-size: 0.7rem; background: rgba(59,130,246,0.2); color: #93c5fd; padding: 2px 8px; border-radius: 4px;">Uploaded</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Spotlight 2: Typed Review Queue -->
            <div class="deep-dive-card" id="feature-review">
                <div class="deep-dive-info">
                    <span class="deep-dive-pill">Spotlight 02 • Typed Review Queue</span>
                    <h2>Comments, Decisions & Version History</h2>
                    <p>Admin and staff review title and chapter versions in the typed queue. Approve a submission or return it with comments; researchers revise and resubmit while each version remains in the history.</p>
                    <div class="deep-feature-specs">
                        <div class="spec-item"><i>💬</i> Categorized remarks</div>
                        <div class="spec-item"><i>⚡</i> Instant status triggers</div>
                        <div class="spec-item"><i>🔒</i> Locked submission snapshots</div>
                        <div class="spec-item"><i>♻️</i> Unlimited revision cycles</div>
                    </div>
                </div>
                <div class="deep-dive-visual">
                    <div class="interactive-preview-box">
                        <div class="preview-box-header">
                            <span>✅ Admin/Staff Review Queue</span>
                            <span style="color: #fbbf24;">● 2 Pending Reviews</span>
                        </div>
                        <div class="interactive-mock-item">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <strong style="font-size: 0.82rem; color: #f8fafc;">Chapter Review Returned</strong>
                                <span style="font-size: 0.68rem; background: #374151; padding: 2px 6px; border-radius: 4px;">Dr. Ramos</span>
                            </div>
                            <p style="font-size: 0.75rem; color: #94a3b8; margin: 0;">"Expand literature matrix in Chapter 2 to include recent 2025 IEEE publications."</p>
                        </div>
                        <div class="interactive-mock-item" style="background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.3); text-align: center;">
                            <span style="font-size: 0.78rem; font-weight: 700; color: #4ade80;">✓ Approve to unlock the next chapter</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Spotlight 3: Institutional Archive & Analytics -->
            <span class="deep-dive-anchor-alias" id="feature-analytics" aria-hidden="true"></span>
            <div class="deep-dive-card" id="feature-archive">
                <div class="deep-dive-info">
                    <span class="deep-dive-pill">Spotlight 03 • Archival & Analytics</span>
                    <h2>Public Discovery & Institutional Intelligence</h2>
                    <p>Preserve completed, defended studies in the public university archive. Enable global researchers to cite your campus's discoveries while administrators analyze annual performance trends in real time.</p>
                    <div class="deep-feature-specs">
                        <div class="spec-item"><i>🔍</i> Instant search filters</div>
                        <div class="spec-item"><i>📊</i> Departmental graphs</div>
                        <div class="spec-item"><i>📥</i> One-click data exports</div>
                        <div class="spec-item"><i>🌐</i> Open access curation</div>
                    </div>
                </div>
                <div class="deep-dive-visual">
                    <div class="interactive-preview-box">
                        <div class="preview-box-header">
                            <span>📊 Institutional Analytics</span>
                            <span style="color: #60a5fa;">Analytics Preview</span>
                        </div>
                        <div class="interactive-mock-item" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; text-align: center;">
                            <div style="background: rgba(255,255,255,0.04); padding: 10px; border-radius: 8px;">
                                <div style="font-size: 1.2rem; font-weight: 700; color: #38ef7d;">95.4%</div>
                                <div style="font-size: 0.68rem; color: #94a3b8;">Completion Rate</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.04); padding: 10px; border-radius: 8px;">
                                <div style="font-size: 1.2rem; font-weight: 700; color: #60a5fa;">1.4k+</div>
                                <div style="font-size: 0.68rem; color: #94a3b8;">Archived Studies</div>
                            </div>
                        </div>
                        <div class="interactive-mock-item" style="font-size: 0.74rem; color: #cbd5e1; display: flex; align-items: center; justify-content: space-between;">
                            <span>🔎 Top Tag: Artificial Intelligence</span>
                            <span style="color: #a78bfa;">+34% YoY</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- CALL TO ACTION BANNER -->
    <section class="modern-cta-section" data-reveal>
        <div class="cta-content-wrapper">
            <span style="background: rgba(255,255,255,0.15); padding: 6px 18px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 20px; display: inline-block;">
                🚀 Transform Your Research Journey
            </span>
            <h2>Ready to Experience Modern Research Management?</h2>
            <p>Join students and faculty using one research workflow, with admin and staff decisions, chapter progress, and outputs managed in one place.</p>
            <div style="display: flex; justify-content: center; gap: 16px; flex-wrap: wrap;">
                <a href="<?php echo features_escape($links['login']); ?>" class="btn btn-primary btn-lg" style="background: #ffffff; color: var(--primary-dark); font-weight: 700; box-shadow: 0 10px 30px rgba(0,0,0,0.25);">
                    Access RMS Portal →
                </a>
                <a href="<?php echo features_escape($links['contact']); ?>" class="btn btn-secondary btn-lg" style="border: 1px solid rgba(255,255,255,0.3); color: #ffffff;">
                    Contact Research Office
                </a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4 style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                    <img class="footer-brand-logo" src="../photos/rms-logo.png" alt="" width="30" height="30" aria-hidden="true">
                    <span style="font-family: 'Poppins', sans-serif;">RMS</span>
                </h4>
                <p style="color: #8B8FAD; font-size: 0.85rem; line-height: 1.6;">
                    Empowering academic institutions to streamline their research management processes with modern, intelligent technology.
                </p>
            </div>
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="<?php echo features_escape($links['home']); ?>">Home</a></li>
                    <li><a href="<?php echo features_escape($links['about']); ?>">About</a></li>
                    <li><a href="<?php echo features_escape($links['features']); ?>">Features</a></li>
                    <li><a href="<?php echo features_escape($links['archive']); ?>">Research Archive</a></li>
                    <li><a href="<?php echo features_escape($links['contact']); ?>">Contact</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Resources</h4>
                <ul>
                    <li><a href="#">Guidelines</a></li>
                    <li><a href="#">FAQ</a></li>
                    <li><a href="#">Documentation</a></li>
                    <li><a href="#">Support</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Contact</h4>
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px;"><span>📧</span> research@earist.edu.ph</p>
                <p style="font-size: 0.85rem; color: #8B8FAD; display: flex; gap: 8px; margin-top: 8px;"><span>📞</span> (02) 1234-5678</p>
            </div>
        </div>
        <div class="footer-bottom">© 2024 Research Management System. All rights reserved.</div>
    </footer>
<script>
(function () {
    'use strict';

    var modal = document.getElementById('featureDetailModal');
    if (!modal || typeof modal.showModal !== 'function') return;

    var triggers = Array.prototype.slice.call(document.querySelectorAll('.modern-feature-card .card-link-btn'));
    var closeButton = modal.querySelector('.feature-detail-modal__close');
    var icon = document.getElementById('featureDetailIcon');
    var role = document.getElementById('featureDetailRole');
    var title = document.getElementById('featureDetailTitle');
    var description = document.getElementById('featureDetailDescription');
    var capabilities = document.getElementById('featureDetailCapabilities');
    var useCase = document.getElementById('featureDetailUseCase');
    var workflowLink = document.getElementById('featureDetailWorkflow');
    var hashPrefix = '#feature-detail-';
    var activeHash = '';
    var lastTrigger = null;
    var pushedModalState = false;

    var roleUseCases = {
        Student: 'Available from the student workspace to keep submissions, milestones, and required actions connected to the active research project.',
        Faculty: 'Uses the same title and chapter submission workflow as students; administrators may additionally grant review permissions.',
        Admin: 'Used by authorized administrators and research offices to maintain governance, visibility, and institutional control.',
        Platform: 'Works across role-based workspaces as a shared service supporting secure, coordinated research activity.'
    };

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function triggerForHash(hash) {
        if (!hash || hash.indexOf(hashPrefix) !== 0) return null;
        var slug = hash.slice(hashPrefix.length);
        return triggers.find(function (trigger) {
            return trigger.getAttribute('data-feature-slug') === slug;
        }) || null;
    }

    function populateModal(trigger) {
        var card = trigger.closest('.modern-feature-card');
        var cardTitle = card.querySelector('h3');
        var cardDescription = card.querySelector('p');
        var cardIcon = card.querySelector('.feature-icon-box');
        var cardRole = card.querySelector('.role-tag-badge');
        var cardPoints = card.querySelectorAll('.feature-points-list li');
        var roleName = cardRole ? cardRole.textContent.trim() : 'Platform';

        icon.textContent = cardIcon ? cardIcon.textContent.trim() : '✦';
        role.textContent = roleName;
        title.textContent = cardTitle ? cardTitle.textContent.trim() : 'Feature details';
        description.textContent = cardDescription ? cardDescription.textContent.trim() : '';
        useCase.textContent = roleUseCases[roleName] || roleUseCases.Platform;
        workflowLink.setAttribute('href', trigger.getAttribute('href') || '#feature-catalog');

        capabilities.textContent = '';
        Array.prototype.forEach.call(cardPoints, function (point) {
            var item = document.createElement('li');
            item.textContent = point.textContent.replace(/^\s*✓\s*/, '').trim();
            capabilities.appendChild(item);
        });
    }

    function showFeature(trigger, pushHistory) {
        var slug = trigger.getAttribute('data-feature-slug');
        activeHash = hashPrefix + slug;
        lastTrigger = trigger;
        populateModal(trigger);

        if (!modal.open) modal.showModal();
        document.body.classList.add('feature-modal-open');

        if (pushHistory && window.location.hash !== activeHash) {
            history.pushState({ featureModal: slug }, '', activeHash);
            pushedModalState = true;
        } else {
            pushedModalState = false;
        }

        closeButton.focus();
    }

    function hideFeature(restoreFocus) {
        if (modal.open) modal.close();
        document.body.classList.remove('feature-modal-open');
        if (restoreFocus !== false && lastTrigger) lastTrigger.focus();
    }

    function requestClose() {
        if (pushedModalState && window.location.hash === activeHash) {
            pushedModalState = false;
            history.back();
            return;
        }

        if (window.location.hash === activeHash) {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
        hideFeature(true);
    }

    triggers.forEach(function (trigger) {
        var cardTitle = trigger.closest('.modern-feature-card').querySelector('h3');
        var slug = slugify(cardTitle ? cardTitle.textContent : 'feature');
        trigger.setAttribute('data-feature-slug', slug);
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-controls', 'featureDetailModal');

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            showFeature(trigger, true);
        });
    });

    closeButton.addEventListener('click', requestClose);

    modal.addEventListener('cancel', function (event) {
        event.preventDefault();
        requestClose();
    });

    modal.addEventListener('click', function (event) {
        if (event.target !== modal) return;
        var rect = modal.getBoundingClientRect();
        var outside = event.clientX < rect.left || event.clientX > rect.right ||
            event.clientY < rect.top || event.clientY > rect.bottom;
        if (outside) requestClose();
    });

    workflowLink.addEventListener('click', function (event) {
        var target = workflowLink.getAttribute('href');
        if (!target || target.charAt(0) !== '#') return;
        event.preventDefault();
        pushedModalState = false;
        history.replaceState(null, '', target);
        hideFeature(false);
        var section = document.querySelector(target);
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    window.addEventListener('popstate', function () {
        var trigger = triggerForHash(window.location.hash);
        if (trigger) {
            showFeature(trigger, false);
        } else {
            hideFeature(true);
        }
    });

    var initialTrigger = triggerForHash(window.location.hash);
    if (initialTrigger) showFeature(initialTrigger, false);
}());
</script>
<script src="../js/public-motion.js" defer></script>
</body>
</html>
