# RMS Project Structure - Updated

**Last Updated:** October 11, 2026
**Status:** Current source map

---

## 📂 Directory Structure

```
rms/
│
├── 📁 public/                           [Public-Facing Pages - 12 files]
│   ├── index.php                        [Homepage with statistics]
│   ├── login.php                        [Authentication page]
│   ├── logout.php                       [Session termination]
│   ├── about.php                        [About EARIST RMS]
│   ├── contact.php                      [Contact form]
│   ├── features.php                     [Features showcase]
│   ├── research-archive.php             [Public research archive]
│   ├── verify-email.php                 [Email verification]
│   ├── resend-verification.php          [Resend verification]
│   ├── 403.php                          [Access Denied error]
│   ├── 404.php                          [Not Found error]
│   └── 500.php                          [Server Error page]
│
├── 📁 pages/                            [Authenticated Internal Pages]
│   │
│   ├── 📁 researcher/                   [Shared Student/Faculty Research Workflow]
│   │   ├── my-research.php              [View research projects]
│   │   ├── submit-research.php          [Draft and submit research titles]
│   │   ├── edit-research.php            [Edit drafts and returned titles]
│   │   └── submit-chapter.php           [Draft and submit chapters]
│   │
│   ├── 📁 student/                      [Student Role]
│   │   ├── student-dashboard.php        [Shared student/faculty dashboard]
│   │   ├── my-research.php              [Legacy redirect to researcher workflow]
│   │   ├── submit-research.php          [Legacy redirect to researcher workflow]
│   │   ├── edit-research.php            [Legacy redirect to researcher workflow]
│   │   ├── submit-chapter.php           [Legacy redirect to researcher workflow]
│   │   ├── my-documents.php             [Student documents]
│   │   ├── research-detail.php          [Student research detail]
│   │   ├── progress-tracking.php        [Track research progress]
│   │   └── submit-milestone.php         [Standby]
│   │
│   ├── 📁 faculty/                      [Faculty Role]
│   │   └── faculty-dashboard.php        [Delegates to student-dashboard.php]
│   │
│   ├── 📁 admin/                        [Admin Role - 13 files]
│   │   ├── admin-dashboard.php          [Admin dashboard]
│   │   ├── admin-users.php              [User management]
│   │   ├── admin-research.php           [Research management]
│   │   ├── admin-research-module.php    [Research module launcher map]
│   │   ├── admin-researchers.php        [Researcher management]
│   │   ├── admin-archive.php            [Archive management]
│   │   ├── admin-reports.php            [Reports & analytics]
│   │   ├── admin-logs.php               [System logs]
│   │   ├── admin-backup.php             [Backup system]
│   │   ├── admin-contact.php            [Contact messages]
│   │   ├── admin-departments.php        [Department management]
│   │   ├── admin-programs.php           [Program management]
│   │   └── admin-settings.php           [System settings]
│   │
│   ├── 📁 staff/                        [Research Staff Role - 6 files]
│   │   ├── staff-dashboard.php          [Staff dashboard]
│   │   ├── contact-messages.php         [Manage contact messages]
│   │   ├── document-verification.php    [Verify research documents]
│   │   ├── staff-defense.php            [Defense scheduling]
│   │   ├── staff-revisions.php          [Manage revisions]
│   │   └── staff-milestones.php         [Standby]
│   │
│   └── 📁 shared/                       [Shared Across Roles - 20 files]
│       ├── messages.php                 [Internal messaging]
│       ├── notifications.php            [Notifications]
│       ├── profile.php                  [User profile]
│       ├── settings.php                 [Account settings]
│       ├── calendar.php                 [Calendar view]
│       ├── research-archive.php         [Authenticated archive]
│       ├── research-detail.php          [Research details]
│       ├── view-research.php            [View research]
│       ├── research-submission-review.php [Typed title/chapter review queue]
│       ├── implementation-outputs.php   [Computed ongoing/completed/outputs views]
│       ├── activities.php                [Research activities]
│       ├── activity-detail.php           [Activity details]
│       ├── activity-form.php              [Activity form]
│       ├── publications.php               [Publication records]
│       ├── publication-detail.php         [Publication details]
│       ├── publication-form.php           [Publication form]
│       ├── copyrights.php                 [Intellectual-property records]
│       ├── copyright-detail.php           [Copyright details]
│       ├── copyright-form.php             [Copyright form]
│       └── download-research-document.php [Authorized research downloads]
│
├── 📁 includes/                         [Backend PHP Logic]
│   ├── config.php                       [Database & app configuration]
│   ├── auth.php                         [Authentication & authorization]
│   ├── research-workflow.php            [Title/chapter workflow helpers]
│   ├── contact-handler.php              [Contact form handler]
│   ├── email.php                         [Email wrapper]
│   ├── file-uploader.php                [Validated upload helper]
│   ├── logout-transition.php            [Logout transition]
│   ├── admin-shell.php                  [Admin shell]
│   ├── staff-shell.php                  [Staff shell]
│   ├── faculty-shell.php                [Faculty shell]
│   ├── student-shell.php                [Student shell]
│   └── .htaccess                        [Protect includes]
│
├── 📁 config/                           [Configuration Files - 2 files]
│   ├── settings.json                    [Application settings]
│   └── skills-lock.json                 [Skills configuration]
│
├── 📁 database/                         [Database Files]
│   ├── 📁 schema/
│   │   └── rms_db.sql                   [Main database schema]
│   └── 📁 migrations/                   [21 existing migration scripts]
│
├── 📁 docs/                             [Documentation]
│   ├── 📁 planning/
│   │   ├── PRIORITY_PLAN.md             [Development roadmap]
│   │   └── RECOMMENDATIONS.md           [Improvement recommendations]
│   ├── 📁 progress/
│   │   ├── PROGRESS_AUDIT.md            [Progress tracking]
│   │   └── SECURITY_HTACCESS_COMPLETE.md [Security completion]
│   ├── 📁 reference/
│   │   └── Research-Manual_2015.md      [Source manual]
│   ├── rms-spec.md                      [RMS specifications]
│   └── testing/                          [Test documentation]
│
├── 📁 scripts/                          [Utility Scripts - 6 files]
│   ├── backup-database.sh               [Database backup]
│   ├── migrate.php                      [Migration runner]
│   ├── update-paths.php                 [Path updating utility]
│   ├── verify-connections.sh            [Connection verification]
│   ├── verify-htaccess.sh               [Security verification]
│   └── verify-messages-navigation.php   [Navigation verification]
│
├── 📁 css/                              [Stylesheets and tokens - 9 files]
│   ├── style.css                        [Main stylesheet]
│   ├── about.css                        [About page styles]
│   ├── admin-shell.css                  [Admin shell styles]
│   ├── staff-shell.css                  [Staff shell styles]
│   ├── faculty-shell.css                [Faculty shell styles]
│   ├── student-shell.css                [Student shell styles]
│   ├── file-uploader.css                [Uploader styles]
│   ├── tokens.css                       [CSS design tokens]
│   └── tokens.php                       [Dynamic CSS tokens]
│
├── 📁 uploads/                          [File Storage]
│   ├── proposals/                       [Research proposals]
│   ├── chapters/                        [Chapter submissions]
│   ├── manuscripts/                     [Manuscript files]
│   └── defense/                         [Defense materials]
│
├── 📁 .github/                          [GitHub Configuration]
│   └── agents/                          [AI Agent definitions]
│       ├── rms-db.agent.md
│       ├── rms-debug.agent.md
│       ├── rms-doc.agent.md
│       ├── rms-page-builder.agent.md
│       ├── rms-security-auth.agent.md
│       ├── rms-ui.agent.md
│       ├── rms-testing.agent.md
│       ├── rms-performance.agent.md
│       ├── rms-backup.agent.md
│       ├── AGENTS_ANALYSIS.md
│       └── AGENTS_MEMORY.md
│
├── 📄 index.php                         [Root redirector to public/]
├── 📄 README.md                         [Project overview]
├── 📄 TODO.md                           [Task tracking]
├── 📄 PROJECT_STRUCTURE.md              [This file]
├── 📄 .gitignore                        [Git ignore rules]
├── 📄 .env.example                      [Environment template]
├── 📄 package.json                      [Node.js configuration]
└── 📄 composer.json                     [PHP dependencies]
```

---

## V3 Architecture and Counts

- `pages/researcher/` is canonical for `my-research.php`, `submit-research.php`, `edit-research.php`, and `submit-chapter.php`. The matching student workflow URLs are compatibility redirects; student-specific pages remain in `pages/student/`.
- `pages/faculty/faculty-dashboard.php` delegates to the student dashboard.
- `rmsPermissionModules()` in `includes/auth.php` defines 5 staff modules × 3 sub-pages; `pages/admin/admin-research-module.php` launches them through `moduleMap`.
- `pages/shared/research-submission-review.php` is the typed review queue; `implementation-outputs.php` provides computed ongoing, completed, and outputs views.
- Role shells in active use: admin, staff, faculty, and student, all under `includes/`.
- Standby files: `pages/staff/staff-milestones.php` and `pages/student/submit-milestone.php`.

Current page counts: public 12, researcher 4, student 9, faculty 1, admin 13, staff 6, shared 20. `includes/` contains 11 PHP files plus `.htaccess`.

---

## 🎯 Key Features of This Structure

### 1. Clear Role Separation
Each user role has its own directory, making it easy to:
- Find role-specific pages quickly
- Apply role-based security
- Maintain and update features per role

### 2. Public vs. Internal
- `public/` - Pages accessible without login
- `pages/` - Protected pages requiring authentication

### 3. Shared Resources
- `pages/shared/` - Common functionality across roles
- Reduces code duplication
- Consistent user experience

### 4. Organized Backend
- `includes/` - Core PHP logic
- `config/` - Configuration files
- `database/` - Database schemas and migrations

### 5. Professional Documentation
- `docs/planning/` - Project planning documents
- `docs/progress/` - Implementation tracking
- Clear separation of concerns

---

## 🔗 URL Structure

### Public URLs (No Login Required):
```
http://localhost/rms/                    → Redirects to public/index.php
http://localhost/rms/public/index.php    → Homepage
http://localhost/rms/public/login.php    → Login page
http://localhost/rms/public/about.php    → About page
http://localhost/rms/public/contact.php  → Contact form
http://localhost/rms/public/features.php → Features page
```

### Internal URLs (Login Required):

**Student Pages:**
```
http://localhost/rms/pages/student/student-dashboard.php
http://localhost/rms/pages/researcher/my-research.php
http://localhost/rms/pages/researcher/submit-research.php
```

**Faculty Pages:**
```
http://localhost/rms/pages/faculty/faculty-dashboard.php
http://localhost/rms/pages/researcher/my-research.php
http://localhost/rms/pages/researcher/submit-chapter.php
```

**Admin Pages:**
```
http://localhost/rms/pages/admin/admin-dashboard.php
http://localhost/rms/pages/admin/admin-users.php
http://localhost/rms/pages/admin/admin-reports.php
```

**Staff Pages:**
```
http://localhost/rms/pages/staff/staff-dashboard.php
http://localhost/rms/pages/staff/contact-messages.php
```

**Shared Pages:**
```
http://localhost/rms/pages/shared/messages.php
http://localhost/rms/pages/shared/notifications.php
http://localhost/rms/pages/shared/profile.php
```

---

## 🔒 Security Features

### Protected Directories:
- `/database/.htaccess` - Blocks all access
- `/config/.htaccess` - Blocks all access
- `/includes/.htaccess` - Blocks all access
- `/uploads/.htaccess` - Allows only document downloads, blocks PHP execution

### File Path Security:
- All includes use `__DIR__` for reliable relative paths
- No hardcoded paths
- Consistent path structure across all files

---

## 🚀 How to Navigate

### For Developers:

**Working on Student Features:**
```
📁 pages/student/
```

**Working on Faculty Features:**
```
📁 pages/faculty/
```

**Working on Admin Features:**
```
📁 pages/admin/
```

**Working on Shared Features:**
```
📁 pages/shared/
```

**Working on Public Pages:**
```
📁 public/
```

**Backend Logic:**
```
📁 includes/
```

**Database Changes:**
```
📁 database/schema/     (for schema updates)
📁 database/migrations/ (for migration scripts)
```

---

## 📝 File Naming Conventions

### Pages:
- **Role dashboard:** `{role}-dashboard.php`
- **Shared pages:** No prefix (e.g., `messages.php`, `profile.php`)
- **Research workflow:** Canonical title/chapter pages live in `pages/researcher/`

### Includes:
- **Configuration:** `config.php`
- **Authentication:** `auth.php`
- **Staff permission map:** `rmsPermissionModules()` in `auth.php`; admin launchers use `admin-research-module.php`
- **Handlers:** `{name}-handler.php`

### Documentation:
- **Planning docs:** `docs/planning/`
- **Progress docs:** `docs/progress/`
- **Uppercase:** `README.md`, `TODO.md`, `PROJECT_STRUCTURE.md`

---

## ✅ Benefits

1. **Easy to Navigate** - Clear folder structure
2. **Role-Based Security** - Separated by user role
3. **Maintainable** - Logical grouping of related files
4. **Scalable** - Easy to add new features per role
5. **Professional** - Industry-standard organization
6. **Secure** - Public vs. internal separation
7. **Clean** - No scattered files in root directory

---

## 🔄 Recent Changes

**August 28, 2026:**
- ✅ Reorganized into public/ and pages/ structure
- ✅ Created role-based subdirectories (student, faculty, admin, staff, shared)
- ✅ Updated all file paths to use `__DIR__`
- ✅ Fixed include statements across all files
- ✅ Created root index.php redirector
- ✅ Moved documentation to organized folders
- ✅ Consolidated configuration files
- ✅ Organized database files into schema/migrations
- ✅ Created scripts directory for utilities

---

## 📖 Quick Reference

### Finding Files:

**Question:** Where is the student dashboard?  
**Answer:** `pages/student/student-dashboard.php`

**Question:** Where is the login page?  
**Answer:** `public/login.php`

**Question:** Where are shared pages like messages?  
**Answer:** `pages/shared/messages.php`

**Question:** Where is database configuration?  
**Answer:** `includes/config.php`

**Question:** Where is the main stylesheet?  
**Answer:** `css/style.css`

**Question:** Where are database schemas?  
**Answer:** `database/schema/rms_db.sql`

---

## 🎓 For New Developers

1. **Start with:** `README.md` - Project overview
2. **Understand structure:** This file (`PROJECT_STRUCTURE.md`)
3. **Check tasks:** `TODO.md` - What needs to be done
4. **Review documentation:** `docs/` folder
5. **Test locally:** Access via `http://localhost/rms/`

---

**Generated:** August 28, 2026 at 16:20 (UTC+8)  
**Status:** ✅ Complete, Clean, and Organized  
**Next Review:** As needed for new features
