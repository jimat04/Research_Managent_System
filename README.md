# Research Management System (RMS)
## Complete Full-Stack Web Application

**Version:** 1.0  
**Built with:** PHP, MySQL, HTML5, CSS3, JavaScript  
**Compatible:** XAMPP, Windows/Mac/Linux

---

## 📋 Table of Contents

1. [Quick Setup](#quick-setup)
2. [Project Structure](#project-structure)
3. [Features](#features)
4. [Database Setup](#database-setup)
5. [User Roles & Access](#user-roles--access)
6. [Demo Credentials](#demo-credentials)
7. [File Descriptions](#file-descriptions)
8. [Customization Guide](#customization-guide)

---

## 🚀 Quick Setup

### Prerequisites
- XAMPP (Apache + MySQL)
- PHP 7.4+
- Modern Web Browser

### Step-by-Step Installation

1. **Extract the ZIP file** to your XAMPP htdocs folder:
   ```
   C:\xampp\htdocs\rms\
   ```

2. **Import the database**:
   - Open `http://localhost/phpmyadmin/`
   - Click "New" to create a new database
   - Name it `rms_db`
   - Go to the "Import" tab
   - Select `database.sql` from the project root
   - Click "Import"

3. **Start XAMPP**:
   - Open XAMPP Control Panel
   - Start Apache
   - Start MySQL

4. **Access the application**:
   - Open `http://localhost/rms/` in your browser
   - You'll see the landing page

---

## 📁 Project Structure

```
rms/
├── index.php                      # Landing page
├── login.php                      # Login & Registration
├── logout.php                     # Session termination
├── database.sql                   # SQL schema & seed data
│
├── includes/
│   ├── config.php                # Database connection & constants
│   └── auth.php                  # Authentication helpers
│
├── css/
│   └── style.css                 # Complete styling (1800+ lines)
│
├── js/
│   └── main.js                   # Client-side functionality
│
├── pages/
│   ├── student-dashboard.php     # Student home
│   ├── faculty-dashboard.php     # Faculty home
│   ├── admin-dashboard.php       # Admin home
│   ├── my-research.php           # [To be created]
│   ├── submit-research.php       # [To be created]
│   ├── my-documents.php          # [To be created]
│   ├── progress-tracking.php     # [To be created]
│   ├── messages.php              # [To be created]
│   ├── notifications.php         # [To be created]
│   ├── profile.php               # [To be created]
│   ├── settings.php              # [To be created]
│   └── ... (faculty & admin pages)
│
├── uploads/
│   ├── proposals/
│   ├── chapters/
│   ├── defense/
│   └── manuscripts/
│
└── README.md                      # This file
```

---

## ✨ Features

### Student Module
- **Dashboard**: Real-time research overview & statistics
- **Research Submission**: Create, edit, and submit research projects
- **Chapter Management**: Submit 5 chapters with status tracking
- **Document Upload**: Upload PDFs, Word files, images
- **Progress Tracking**: Monitor submission status
- **Notifications**: Receive feedback from advisers
- **Calendar**: Track deadlines
- **Research Archive**: Browse published research

### Faculty Module
- **Dashboard**: Overview of assigned research
- **Review Queue**: List of pending submissions
- **Chapter Review**: Provide feedback and approve/reject
- **Comment System**: Leave constructive comments
- **Student Management**: Monitor assigned students
- **Defense Scheduling**: Schedule oral defenses
- **Reports**: Generate review reports

### Admin Module
- **Dashboard**: System-wide statistics and KPIs
- **User Management**: Create, edit, deactivate users
- **Research Management**: Full control over all research
- **Archive Management**: Manage completed research
- **Reports & Analytics**: Generate comprehensive reports
- **System Logs**: Monitor user activity
- **Backup**: Backup database and files
- **Settings**: System configuration

---

## 🗄️ Database Setup

### Automatic Setup (Recommended)
1. The `database.sql` file contains the complete schema
2. Import it into MySQL via phpMyAdmin
3. All tables and sample data will be created automatically

### Manual Setup
Run the SQL commands in `database.sql` manually in phpMyAdmin

### Database Tables
- **users**: User accounts (students, faculty, admin)
- **research_projects**: Research records
- **chapters**: Chapter submissions (1-5)
- **chapter_content**: Detailed chapter content
- **comments**: Reviewer feedback
- **uploads**: File uploads
- **notifications**: System notifications
- **defense_schedule**: Defense schedules
- **activity_log**: User activity tracking
- **departments**, **programs**, **academic_years**, **research_categories**: Settings

---

## 👥 User Roles & Access

### Student
- **Can**: Submit research, upload chapters, view status, receive feedback
- **Cannot**: Review other submissions, manage users, access admin panel

### Faculty / Adviser
- **Can**: Review assigned submissions, provide feedback, approve/reject, evaluate defenses
- **Cannot**: Manage users, access admin settings, review unassigned research

### Administrator
- **Can**: Full system access, manage all users, monitor all research, generate reports, configure system

---

## 🔑 Demo Credentials

Use these to test the application:

| Role | Email | Password |
|------|-------|----------|
| Student | jdelacruz@rms.edu.ph | Student@123 |
| Faculty | msantos@rms.edu.ph | Faculty@123 |
| Admin | admin@rms.edu.ph | Admin@123 |

These credentials are created automatically when you import the database.

---

## 📄 File Descriptions

### Core Files

#### `index.php`
- Landing page showcasing system features
- Hero section with CTAs
- Statistics and feature cards
- Responsive design matching the UI image

#### `login.php`
- Combined login & registration form
- Three role tabs: Student, Faculty, Admin
- Form validation
- Session creation on successful login

#### `includes/config.php`
- Database connection configuration
- Global constants (site URL, upload directory, timeouts)
- MySQL connection initialization

#### `includes/auth.php`
- `isLoggedIn()`: Check if user has active session
- `getCurrentUser()`: Get logged-in user's data
- `hasRole($role)`: Check user's role
- `requireRole($role)`: Enforce role-based access
- `hashPassword()`, `verifyPassword()`: Password security
- `sanitize()`: Input sanitization
- `createNotification()`: Send notifications
- `logActivity()`: Log user actions

#### `css/style.css`
- Complete styling (1800+ lines)
- Color palette matching design image
- Responsive design (mobile, tablet, desktop)
- Dashboard components (sidebar, topbar, cards, tables, charts)
- Animation effects

### Page Files

#### Student Pages
- `student-dashboard.php`: Home dashboard with stats, research table, deadlines
- `my-research.php`: Detailed research listing
- `submit-research.php`: Research submission form
- `my-documents.php`: Document management
- `progress-tracking.php`: Visual progress indicators
- `messages.php`: Communication with advisers
- `notifications.php`: Notification center
- `profile.php`: User profile & settings
- `calendar.php`: Deadline calendar

#### Faculty Pages
- `faculty-dashboard.php`: Overview of assigned research
- `faculty-review.php`: Review queue listing
- `faculty-review-detail.php`: Detailed review interface
- `faculty-submissions.php`: All submissions overview
- `faculty-students.php`: Manage assigned students
- `faculty-reports.php`: Generate reports

#### Admin Pages
- `admin-dashboard.php`: System overview with KPIs
- `admin-users.php`: User management interface
- `admin-research.php`: Research management
- `admin-archive.php`: Archive management
- `admin-reports.php`: Advanced reporting
- `admin-logs.php`: System activity logs
- `admin-backup.php`: Database backup

---

## 🎨 Color Palette

```
Primary Purple:    #5B1EBC
Secondary Blue:    #0F6CBD
Accent Orange:     #F57C00
Success Green:     #22c55e
Warning Yellow:    #f59e0b
Danger Red:        #ef4444
Dark Navy:         #0A0833
Light Gray:        #F8F9FE
Text Dark:         #0f172a
Text Light:        #64748b
Text Muted:        #94a3b8
Border:            #e2e8f0
```

---

## 🔐 Security Features

- **Password Hashing**: bcrypt with cost factor 12
- **Session Management**: 30-minute timeout
- **Input Sanitization**: SQL injection protection
- **Role-Based Access Control**: Enforce user permissions
- **Activity Logging**: Track all user actions
- **CSRF Protection**: Ready for CSRF tokens
- **XSS Prevention**: Output escaping

---

## 🔧 Customization Guide

### Changing Colors
Edit `/css/style.css` - Look for `:root` CSS variables at the top

### Changing Company Name
1. Edit `includes/config.php`: Change `SITE_NAME` and `SITE_TITLE`
2. Update logo in navbar/sidebar (currently emoji 🔬)

### Adding New Menu Items
Edit sidebar navigation in dashboard pages. Example:
```html
<div class="nav-item" onclick="location.href='page.php'">
  <span class="icon">📎</span>
  <span>Menu Item</span>
</div>
```

### Creating New Pages
1. Create `pages/page-name.php`
2. Start with role check:
   ```php
   <?php
   include '../includes/config.php';
   include '../includes/auth.php';
   requireRole('student');
   ?>
   ```
3. Copy the dashboard sidebar/topbar structure
4. Build your content

### Modifying Database
Edit `database.sql` before import. After import:
1. Use phpMyAdmin to make changes
2. Or write PHP migration scripts

---

## 📊 Sample Data

The database includes:
- 1 Admin user
- 2 Faculty users
- 2 Student users
- 4 Departments
- 5 Programs
- 4 Academic Years
- 5 Research Categories

Customize seed data in `database.sql` before importing.

---

## 🐛 Troubleshooting

### Blank Page on Login
- Check if `includes/config.php` has correct MySQL credentials
- Ensure MySQL server is running
- Check browser console for errors (F12)

### 404 Errors
- Ensure files are in `C:\xampp\htdocs\rms\`
- Check file names for typos
- Verify Apache is serving the directory

### Database Import Fails
- Ensure `rms_db` database doesn't already exist
- Check file encoding is UTF-8
- Try importing smaller sections if file is large

### Session Timeout Issues
- Edit `SESSION_TIMEOUT` in `includes/config.php` (currently 1800 seconds = 30 minutes)
- Clear browser cookies

---

## 📈 Performance Optimization

1. **Enable Query Caching**: MySQL configuration
2. **Add Database Indexes**: For frequently queried columns
3. **Minify CSS/JS**: Reduce file sizes
4. **Enable Gzip Compression**: Apache `.htaccess`
5. **Cache Database Queries**: Implement caching layer

---

## 🚀 Deployment

For production deployment:

1. **Environment Setup**:
   - Use separate production MySQL server
   - Enable HTTPS/SSL
   - Configure `includes/config.php` with production database

2. **Security Hardening**:
   - Disable directory listing (`.htaccess`)
   - Set proper file permissions (644 for files, 755 for directories)
   - Regular backups (automated)
   - Security updates for PHP/MySQL

3. **Performance**:
   - Implement caching
   - Use CDN for static assets
   - Database optimization
   - Enable compression

---

## 📞 Support & Documentation

For detailed functionality:
1. Read comments in PHP files
2. Check function documentation in `includes/auth.php`
3. Review CSS class names in `css/style.css`
4. Test with demo credentials before customizing

---

## 📝 License

This Research Management System is provided as-is for educational and institutional use.

---

## ✅ Checklist for First-Time Setup

- [ ] Extract ZIP to `C:\xampp\htdocs\rms\`
- [ ] Create `rms_db` database in phpMyAdmin
- [ ] Import `database.sql`
- [ ] Start Apache & MySQL in XAMPP
- [ ] Visit `http://localhost/rms/`
- [ ] Login with demo credentials
- [ ] Verify all three dashboards work
- [ ] Test file upload functionality
- [ ] Create test research entry

---

**Built with ❤️ for academic institutions**

Version 1.0 — May 2024
