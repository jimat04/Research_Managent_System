# RMS UI conventions

Read [AGENTS.md](../../AGENTS.md) first. Apply this reference when the [task index](README.md) selects it; paths in code spans are relative to the repository root. These conventions were relocated from CLAUDE.md without revalidating historical setup claims; current source wins.

## AI Design System (Required)

## AI Design Constitution — EARIST RMS (MANDATORY)

Every UI, page, component, and feature must follow this design system. This is the source of truth — the live site uses the palette below, NOT the old charcoal/gold palette described in earlier drafts.

---

## Design Identity

- **Style:** Modern academic platform with signature branded moments
- **Feel:** Professional university research system (NOT a generic admin template, NOT a neon SaaS app)
- **Keywords:** Institutional, confident, warm, spacious, accessible
- **North-star page:** `public/login.php` (split-screen with purple gradient divider — study it for color, spacing, animation tone)

## Stack / Tech Constraints (MUST READ)

- **No frameworks** — vanilla PHP/MySQLi/HTML/CSS/JS only
- **No Tailwind / Bootstrap / jQuery / React / icon libraries** (no Lucide, no Font Awesome)
- Use **emoji icons consistently** across the app (🎓 📋 🎒 🔴 📊 📁 📝 etc.)
- Composer packages allowed: phpdotenv, PHPMailer (already in use)
- Use SITE_URL constant for ALL cross-page links (prevents 404s from shared pages)
- All nav links go in the role shell file (admin-shell / staff-shell / faculty-shell / student-shell)

---

## Role Color System (CRITICAL — each role has a branded accent)

Every role's shell uses these exact accents. NEVER swap them.

| Role | Slug | Accent primary | Accent hover | Tint bg | Border tint | Emoji |
|---|---|---|---|---|---|---|
| Admin | `admin` | `#F57C00` (orange) | `#EA580C` (deep orange) | `rgba(245,124,0,0.08)` | `rgba(245,124,0,0.18)` | 🔴 / 🛡️ |
| Research Staff | `research_staff` | `#0d9488` (teal) | `#059669` (emerald) | `rgba(13,148,136,0.08)` | `rgba(13,148,136,0.18)` | 📋 / 🏛️ |
| Faculty | `faculty` | `#1d4ed8` (blue) | `#4338ca` (indigo) | `rgba(29,78,216,0.08)` | `rgba(29,78,216,0.18)` | 🎓 |
| Student | `student` | `#5B1EBC` (RMS purple) | `#7c3aed` (violet) | `rgba(91,30,188,0.08)` | `rgba(91,30,188,0.18)` | 🎒 |

### Brand-wide colors (shared)

- **Sidebar dark surface:** `#111827` (deep charcoal, all 4 role sidebars use this as background)
- **Sidebar brand panel (login left):** dark navy gradient `#0A0833 → #1a0a3a → #0a1a3a`
- **Auth form panel (login right):** lavender gradient `#F8F4FF → #EDE5FA`
- **Page background:** `#F8FAFC` (light cool gray)
- **Card surface:** `#FFFFFF` (white, with subtle role-tinted shadow on hover)
- **Primary text:** `#111827`
- **Secondary text:** `#64748B`
- **Muted text:** `#94A3B8`
- **Border default:** `#E5E7EB`
- **Divider accent gradient (login):** purple `#5B1EBC` → blue `#0F6CBD` → orange `#F57C00` (animated, 3s loop)
- **RMS logo:** `photos/rms-logo.png` (purple profile/gear/arrows mark — use on sidebar 44px rounded tiles, login panel 110px)

### Status colors

| Status | Color | Hex |
|---|---|---|
| Draft / Pending | Slate gray | `#64748B` |
| Submitted / Under Review | Blue | `#2563EB` |
| Title / Chapter Review | Blue | `#3B82F6` |
| Returned for Revision | Orange | `#EA580C` |
| For Revision | Orange | `#EA580C` |
| Approved / Ongoing | Green | `#16A34A` |
| Completed | Emerald | `#059669` |
| Archived | Slate | `#475569` |
| Error / Destructive | Red | `#EF4444` (errors, logout, delete actions only)

---

## Typography

- **Font family:** `Inter`, sans-serif (loaded via Google Fonts at `fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap`)
- **Headings:** bold (600–700), tight line-height (1.2), clear hierarchy
- **Body:** 14–16px, line-height 1.6, comfortable reading
- **Labels:** 13px, 500 weight, muted color `#64748B`

Never use decorative or serif fonts.

## Spacing System (8-point grid)

| Token | Value | Use |
|---|---|---|
| xs | 4px | icon-to-text gap |
| sm | 8px | tight padding |
| md | 16px | card internal padding |
| lg | 24px | standard card padding, section gaps |
| xl | 32px | large section spacing |
| xxl | 48px | hero/header spacing |

- Minimum card padding: 24px
- Section spacing: 32–48px
- Whitespace is a feature — never crowd interfaces

---

## Layout Architecture

### Shell pattern (role-based sidebars)
Each role has a reusable shell in `includes/{role}-shell.php` with matching `css/{role}-shell.css`:
- `admin-shell.php` + `admin-shell.css` (orange)
- `staff-shell.php` + `staff-shell.css` (teal)
- `faculty-shell.php` + `faculty-shell.css` (blue)
- `student-shell.php` + `student-shell.css` (purple)

Shells output: `<!DOCTYPE><head> (with css/style.css + css/{role}-shell.css links) + dark sidebar + topbar + .{role}-page-content`
Pages call `render{Role}Shell($user, $current_page, $title, $subtitle)` at top and `render{Role}ShellClose()` at bottom.
Shared pages include and render the appropriate active role shell directly.

**When building a new page**, do NOT write a new sidebar/topbar from scratch — call the existing shell for that role.

### Nav link rules
- All sidebar/topbar links MUST use `SITE_URL . 'pages/...'` — NEVER relative `../` paths (causes 404s from shared pages)
- Logo in sidebar links to role dashboard
- Active nav item highlighted with role-tinted background + 3px role-colored left border
- Badge counts (unread messages/notifications) only rendered when count > 0

### Cards
- Border-radius: 16–20px
- Background: white
- Border: `1px solid #E5E7EB` (or role-tinted for active/feature cards)
- Shadow: subtle `0 1px 3px rgba(0,0,0,0.05)`
- Hover: soft role-tinted shadow lift
- Padding: 24px

### Tables
- Rounded 16px container with white bg and border
- Sticky header with light gray bg
- Row hover: `#F8FAFC`
- Status badges: rounded-full (9999px) pills, 12px 12px padding, 12px 500 weight font

### Buttons
- **Primary:** role-colored background, white text, rounded 10px, padding 10px 20px, 500 weight, hover darkens
- **Secondary:** white bg, gray border, dark text
- **Danger:** red bg only for delete/logout/destructive actions
- Focus ring: 2px role-color outline, 2px offset

### Forms
- Labels: 13px 500, dark color
- Inputs: rounded 10px, light gray border `#E5E7EB`, padding 10px 14px, white bg, focus ring in role color
- Error states: red border + red text below field
- All state-changing forms MUST include `<?= csrfField() ?>` and validate POST with `isCsrfTokenValid()`

---

## Responsive & Motion

- Sidebar collapses under 900px (stacked layout)
- Login split-screen stacks under 900px (brand panel on top, form below, divider hidden)
- Animations: subtle (200–300ms ease), used for hover states, page entrance, divider flow
- Respect `prefers-reduced-motion` — disable animations/transitions when user prefers reduced motion
- Page-load entrance: staggered fade-in (logo → title → subtitle → divider → form), 400–600ms total
- Never use jarring bounces, neon glows, or excessive rotation

---

## Component Library (reuse, don't reinvent)

- **File Uploader** (`includes/file-uploader.php`, `css/file-uploader.css`, `js/file-uploader.js`) — reusable drag-and-drop uploader with 7 visual states, progress bar, client+server validation, CSRF, finfo MIME checking, folder whitelist. Use via `renderFileUploader($options)` for any upload field (proposals, chapters, defense, manuscripts). Never build a second uploader component.
- **Status badges** — use the `statusBadge()` helper pattern defined in each dashboard
- **Stat cards** — icon + label + primary metric + optional trend
- **Empty state** — centered icon + message + primary action (never blank tables)
- **Shell helpers** — use render{Role}Shell; never duplicate sidebar markup

---

## Design Goal

Every page should feel like part of a **funded, modern university research platform** — consistent role-colored accents, generous whitespace, clean typography, purposeful animation, and clear hierarchy. Not a student CRUD project, not a generic SaaS template.
