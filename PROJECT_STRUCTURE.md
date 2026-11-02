# RMS Project Structure - Updated

**Last Updated:** November 2, 2026
**Status:** Maintained high-level source map; verify volatile counts and paths against the repository

---

## 📂 Directory Structure

```text
rms/
  public/                  Public pages, login, email verification, archive, released records
  pages/
    researcher/            Canonical title, chapter, final manuscript, and adviser request flows
    student/               Student dashboard, documents, and compatibility routes
    faculty/               Advising, analytics, document tools, engagement, publications, research records
    admin/                 Users, research, reports, backups, publications, public releases, EARIST administration
    staff/                 Staff dashboard, contact, document verification, defense, revisions, milestones
    shared/                Messages, activities, typed reviews, outputs, publications/IP, protected downloads
  includes/                Authentication, workflow, archives, activities, documents, shells, shared helpers
  database/
    schema/rms_db.sql      Canonical fresh-install schema and seed data
    migrations/            Numbered upgrade history (currently through 066) and legacy scripts
  scripts/                 Migration, backup, verification, document, and archive utilities
  docs/                    Specification, task references, feature guides, dated verification reports
  css/, js/, uploads/      Application styles, scripts, and protected file storage
  .github/                 Agent and skill guidance
  index.php                Root redirector to public/
  README.md                Setup and product overview
  PROJECT_STRUCTURE.md     This source map
```
---

## Current Architecture

- `pages/researcher/` is canonical for title, chapter, final-manuscript, and adviser-request submissions. The matching student workflow URLs are compatibility redirects; student-specific pages remain in `pages/student/`.
- `pages/faculty/faculty-dashboard.php` routes faculty to `faculty-analytics.php`; both roles use the shared researcher workflow.
- `rmsPermissionModules()` in `includes/auth.php` defines 5 staff modules × 3 sub-pages; `pages/admin/admin-research-module.php` launches them through `moduleMap`.
- `pages/shared/research-submission-review.php` is the typed title/chapter/final-manuscript review queue; `implementation-outputs.php` requires an approved final manuscript for completed research.
- Role shells in active use: admin, staff, faculty, and student, all under `includes/`.
- Standby files: `pages/staff/staff-milestones.php` and `pages/student/submit-milestone.php`.

Page counts change as features are added; use the directory contents as the current inventory. Researcher submissions are canonical under `pages/researcher/`, while role-specific and shared pages remain in their corresponding directories.

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
http://localhost/rms/public/about-us.php → About Us page and contact form
http://localhost/rms/public/about-us.php#research-features → About Us feature summary
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
- **Uppercase:** `README.md`, `PROJECT_STRUCTURE.md`

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

**August 28, 2026 (historical):**
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
3. **Check planning:** `docs/planning/PRIORITY_PLAN.md` - historical priorities; confirm current status in source
4. **Review documentation:** `docs/` folder
5. **Test locally:** Access via `http://localhost/rms/`

---

**Last reviewed:** November 2, 2026 (Asia/Taipei)
**Status:** Maintained source map; historical change notes are retained below
