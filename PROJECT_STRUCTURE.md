# RMS Project Structure - Updated

**Last Updated:** August 28, 2026 at 16:20 (UTC+8)  
**Status:** ✅ Fully Organized and Clean

---

## 📂 Directory Structure

```
rms/
│
├── 📁 public/                           [Public-Facing Pages - 10 files]
│   ├── index.php                        [Homepage with statistics]
│   ├── login.php                        [Authentication page]
│   ├── logout.php                       [Session termination]
│   ├── about.php                        [About EARIST RMS]
│   ├── contact.php                      [Contact form]
│   ├── features.php                     [Features showcase]
│   ├── research-archive.php             [Public research archive]
│   ├── 403.php                          [Access Denied error]
│   ├── 404.php                          [Not Found error]
│   └── 500.php                          [Server Error page]
│
├── 📁 pages/                            [Authenticated Internal Pages]
│   │
│   ├── 📁 student/                      [Student Role - 6 files]
│   │   ├── student-dashboard.php        [Student dashboard]
│   │   ├── my-research.php              [View my research projects]
│   │   ├── submit-research.php          [Submit new research]
│   │   ├── submit-chapter.php           [Submit chapter]
│   │   ├── my-documents.php             [My documents]
│   │   └── progress-tracking.php        [Track research progress]
│   │
│   ├── 📁 faculty/                      [Faculty Role - 6 files]
│   │   ├── faculty-dashboard.php        [Faculty dashboard]
│   │   ├── faculty-submissions.php      [View submissions]
│   │   ├── faculty-review.php           [Review queue]
│   │   ├── faculty-review-detail.php    [Detailed review]
│   │   ├── faculty-students.php         [My students]
│   │   └── faculty-reports.php          [Faculty reports]
│   │
│   ├── 📁 admin/                        [Admin Role - 7 files]
│   │   ├── admin-dashboard.php          [Admin dashboard]
│   │   ├── admin-users.php              [User management]
│   │   ├── admin-research.php           [Research management]
│   │   ├── admin-archive.php            [Archive management]
│   │   ├── admin-reports.php            [Reports & analytics]
│   │   ├── admin-logs.php               [System logs]
│   │   └── admin-backup.php             [Backup system]
│   │
│   ├── 📁 staff/                        [Research Staff Role - 2 files]
│   │   ├── staff-dashboard.php          [Staff dashboard]
│   │   └── contact-messages.php         [Manage contact messages]
│   │
│   └── 📁 shared/                       [Shared Across Roles - 10 files]
│       ├── module-page.php              [Module wrapper system]
│       ├── messages.php                 [Internal messaging]
│       ├── notifications.php            [Notifications]
│       ├── profile.php                  [User profile]
│       ├── settings.php                 [Account settings]
│       ├── calendar.php                 [Calendar view]
│       ├── research-archive.php         [Authenticated archive]
│       ├── research-detail.php          [Research details]
│       ├── view-research.php            [View research]
│       └── placeholder-page.php         [Placeholder template]
│
├── 📁 includes/                         [Backend PHP Logic - 4 files]
│   ├── config.php                       [Database & app configuration]
│   ├── auth.php                         [Authentication & authorization]
│   ├── module-pages.php                 [Module rendering system]
│   └── contact-handler.php              [Contact form handler]
│
├── 📁 config/                           [Configuration Files - 2 files]
│   ├── settings.json                    [Application settings]
│   └── skills-lock.json                 [Skills configuration]
│
├── 📁 database/                         [Database Files]
│   ├── 📁 schema/
│   │   └── rms_db.sql                   [Main database schema]
│   └── 📁 migrations/
│       ├── rms_db_migration.sql         [Migration script]
│       └── add_contact_messages_table.sql
│
├── 📁 docs/                             [Documentation]
│   ├── 📁 planning/
│   │   ├── PRIORITY_PLAN.md             [Development roadmap]
│   │   └── RECOMMENDATIONS.md           [Improvement recommendations]
│   ├── 📁 progress/
│   │   ├── PROGRESS_AUDIT.md            [Progress tracking]
│   │   └── SECURITY_HTACCESS_COMPLETE.md [Security completion]
│   ├── rms-spec.md                      [RMS specifications]
│   └── research-manual-2015.md          [Research manual]
│
├── 📁 scripts/                          [Utility Scripts - 3 files]
│   ├── update-paths.php                 [Path updating utility]
│   ├── verify-connections.sh            [Connection verification]
│   └── verify-htaccess.sh               [Security verification]
│
├── 📁 css/                              [Stylesheets - 4 files]
│   ├── style.css                        [Main stylesheet]
│   ├── about.css                        [About page styles]
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

## 📊 File Statistics

### By Category:
- **Public Pages:** 10 files
- **Student Pages:** 6 files
- **Faculty Pages:** 6 files
- **Admin Pages:** 7 files
- **Staff Pages:** 2 files
- **Shared Pages:** 10 files
- **Include Files:** 4 files
- **Configuration:** 2 files
- **Database Files:** 3 files
- **Documentation:** 6 files
- **Scripts:** 3 files
- **Stylesheets:** 4 files

**Total PHP Files:** 52 files  
**Total Project Files:** 70+ files

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
http://localhost/rms/pages/student/my-research.php
http://localhost/rms/pages/student/submit-research.php
```

**Faculty Pages:**
```
http://localhost/rms/pages/faculty/faculty-dashboard.php
http://localhost/rms/pages/faculty/faculty-review.php
http://localhost/rms/pages/faculty/faculty-students.php
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
- **Role prefix:** `student-`, `faculty-`, `admin-`, `staff-`
- **Dashboard:** `{role}-dashboard.php`
- **Shared pages:** No prefix (e.g., `messages.php`, `profile.php`)

### Includes:
- **Configuration:** `config.php`
- **Authentication:** `auth.php`
- **Module system:** `module-pages.php`
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
