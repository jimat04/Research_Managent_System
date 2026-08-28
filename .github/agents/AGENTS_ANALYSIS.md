# RMS Agents Analysis & Recommendations

**Date:** August 28, 2026  
**Location:** `.github/agents/`  
**Total Agents:** 6 specialized agents

---

## 📊 Current Agent Overview

Your RMS project has a well-structured agent system with 6 specialized agents, each with clear responsibilities:

| Agent | Status | Purpose | Tools | User-Invocable |
|-------|--------|---------|-------|----------------|
| **rms-db** | ✅ Active | Database schema & queries | Full
`AGENTS_MEMORY.md` serves as a comprehensive guide showing:
- What each agent does
- What tools they use
- How they interact
- Key conventions

---

## ⚠️ Issues & Gaps Identified

### 1. **Missing Agent: Testing/QA**
**Problem:** No dedicated agent for testing  
**Impact:** Manual testing only, bugs caught late

**Recommendation:** Create `rms-testing.agent.md`
```markdown
# RMS Testing Agent
- Write PHPUnit tests
- Create test data fixtures
- Integration testing
- Security testing
- Load testing
```

### 2. **Missing Agent: Deployment**
**Problem:** No deployment automation  
**Impact:** Manual, error-prone deployments

**Recommendation:** Create `rms-deploy.agent.md`
```markdown
# RMS Deployment Agent
- Production deployment scripts
- Database migrations
- Backup before deploy
- Rollback procedures
- Health checks
```

### 3. **Missing Agent: Performance**
**Problem:** No dedicated performance monitoring/optimization  
**Impact:** Performance issues not proactively addressed

**Recommendation:** Create `rms-performance.agent.md`
```markdown
# RMS Performance Agent
- Database query optimization
- Index recommendations
- Caching strategies
- Asset optimization
- Performance monitoring
```

### 4. **rms-security-auth: Needs Updates**
**Current:** 3,460 bytes (smallest agent)  
**Issue:** Brief compared to importance

**Recommendations:**
- Add more security check patterns
- Include OWASP Top 10 checklist
- Add file upload security details
- Include rate limiting patterns
- Add security testing procedures

### 5. **No API Agent**
**Current:** No API-specific agent  
**Future Need:** When you build REST API

**Recommendation:** Plan for `rms-api.agent.md`
```markdown
# RMS API Agent
- RESTful endpoint design
- JWT authentication
- API documentation
- Rate limiting
- Versioning
```

---

## 📈 Agent Usage Analysis

Based on your project's current state and needs:

### High Usage (Well-Defined):
1. ✅ **rms-page-builder** - Used throughout page development
2. ✅ **rms-db** - Used for all database operations
3. ✅ **rms-ui** - Used for all UI components

### Medium Usage (Important):
4. ⚠️ **rms-security-auth** - Should be used more often
5. ⚠️ **rms-debug** - Used reactively when issues occur

### Lower Usage (But Still Important):
6. ⚠️ **rms-doc** - Should be used more for maintaining docs

---

## 🎯 Recommendations

### Priority 1: Enhance Existing Agents

#### A. Expand `rms-security-auth.agent.md`
**Add these sections:**

```markdown
## Security Checklist (OWASP Top 10)

### 1. Injection
- [ ] All SQL uses prepared statements
- [ ] Input validation on all forms
- [ ] No shell_exec() or eval()

### 2. Broken Authentication
- [ ] Password complexity rules
- [ ] Session timeout configured
- [ ] No credentials in code

### 3. Sensitive Data Exposure
- [ ] HTTPS enforced
- [ ] Passwords hashed (bcrypt)
- [ ] No sensitive data in logs

### 4. XML External Entities (XXE)
- [ ] XML parsing disabled or secured

### 5. Broken Access Control
- [ ] requireRole() on all protected pages
- [ ] User can't access other users' data

### 6. Security Misconfiguration
- [ ] PHP error display off in production
- [ ] .htaccess files in place
- [ ] Directory listing disabled

### 7. Cross-Site Scripting (XSS)
- [ ] All output escaped with htmlspecialchars()
- [ ] Content-Security-Policy headers
- [ ] No inline JavaScript with user data

### 8. Insecure Deserialization
- [ ] No unserialize() on user input

### 9. Using Components with Known Vulnerabilities
- [ ] PHP version up to date
- [ ] Composer dependencies updated

### 10. Insufficient Logging & Monitoring
- [ ] Failed login attempts logged
- [ ] Security events logged
- [ ] Log review process
```

#### B. Update `rms-doc.agent.md`
**Add these responsibilities:**

```markdown
## Additional Documentation Tasks

### API Documentation (Future)
- OpenAPI/Swagger specs
- Endpoint examples
- Authentication guide

### User Guides
- Student user manual
- Faculty user manual
- Admin user manual
- Video tutorial scripts

### Developer Docs
- Setup guide
- Architecture overview
- Contributing guidelines
- Code style guide
```

#### C. Enhance `rms-debug.agent.md`
**Add these common issues:**

```markdown
## Additional Debugging Scenarios

### Performance Issues
- Slow page loads → Check queries with EXPLAIN
- Memory exhausted → Check file upload sizes
- Timeout errors → Check long-running queries

### Session Issues
- Cart/data loss → Check session garbage collection
- Logged out randomly → Check session.gc_maxlifetime

### File System Issues
- Upload failures → Check disk space
- Permission denied → Check folder ownership
- Temp file errors → Check sys_temp_dir

### Email Issues
- Emails not sending → Check mail() logs
- Emails in spam → Check SPF/DKIM
```

---

### Priority 2: Create New Agents

#### 1. Create `rms-testing.agent.md`
**Purpose:** Automated testing and quality assurance

```markdown
---
name: rms-testing
description: Write and run automated tests for RMS (PHPUnit, integration tests, security tests)
tools:
  - read
  - search
  - edit
  - execute
  - todo
user_invocable: true
---

# RMS Testing Agent

## Responsibilities
- Write PHPUnit unit tests
- Create integration tests
- Generate test fixtures and seed data
- Run security tests
- Performance/load testing
- Generate test coverage reports

## Testing Strategy
1. Unit tests for all helper functions in `includes/`
2. Integration tests for user workflows
3. Security tests for auth/authorization
4. Database tests for query integrity
5. UI tests for forms and interactions

## Test Structure
```
/tests
  /unit
    /includes
      AuthTest.php
      ConfigTest.php
  /integration
    LoginFlowTest.php
    ResearchSubmissionTest.php
  /fixtures
    users.php
    research_projects.php
```

## Test Naming Conventions
- Test files: `[Class]Test.php`
- Test methods: `test_[method]_[scenario]`
- Fixtures: `[table]_fixture.php`
```

#### 2. Create `rms-performance.agent.md`
**Purpose:** Monitor and optimize performance

```markdown
---
name: rms-performance
description: Optimize database queries, add indexes, implement caching, monitor performance
tools:
  - read
  - search
  - edit
  - execute
  - todo
user_invocable: true
---

# RMS Performance Agent

## Responsibilities
- Analyze slow queries with EXPLAIN
- Recommend and add database indexes
- Implement query caching
- Optimize assets (minify CSS/JS)
- Monitor page load times
- Implement pagination
- Add Redis caching where beneficial

## Performance Targets
- Page load: < 2 seconds
- Database queries: < 100ms
- API responses: < 500ms
- Time to first byte: < 200ms

## Monitoring
- Track slow query log
- Monitor MySQL performance schema
- Check Apache access logs
- Profile PHP execution time
```

#### 3. Create `rms-backup.agent.md`
**Purpose:** Backup and disaster recovery

```markdown
---
name: rms-backup
description: Implement automated backups, test recovery procedures, maintain backup schedules
tools:
  - read
  - edit
  - execute
  - todo
user_invocable: true
---

# RMS Backup & Recovery Agent

## Responsibilities
- Create automated backup scripts
- Database dumps (mysqldump)
- File system backups (/uploads)
- Test restore procedures
- Manage backup retention
- Off-site backup sync

## Backup Schedule
- Database: Daily at 2 AM
- Files: Daily at 3 AM
- Full backup: Weekly (Sunday)
- Retention: 30 days

## Recovery Procedures
1. Database restore from .sql dump
2. File restore from tar.gz
3. Verify data integrity
4. Test critical functions
```

---

### Priority 3: Improve Agent Documentation

#### Update `AGENTS_MEMORY.md`
**Add these sections:**

```markdown
## Agent Invocation Guide

### When to Use Each Agent

**Use `rms-db` when:**
- Creating new tables or columns
- Writing complex queries
- Adding indexes
- Creating seed data
- Database migrations

**Use `rms-security-auth` when:**
- Auditing login/registration
- Adding CSRF protection
- Fixing SQL injection
- Reviewing authorization
- Adding rate limiting

**Use `rms-ui` when:**
- Creating new components
- Styling forms
- Adding animations
- Improving responsiveness
- Implementing dark mode

**Use `rms-debug` when:**
- Pages showing errors
- Login not working
- Files not uploading
- CSS not loading
- Database errors

**Use `rms-doc` when:**
- Updating README
- Completing TODO items
- Adding code comments
- Creating user guides

**Use `rms-page-builder` when:**
- Creating new dashboard pages
- Adding new features
- Building forms
- Creating data tables

## Agent Decision Tree

```
Issue with...
├─ Database schema/queries? → rms-db
├─ Page not working/errors? → rms-debug
│  └─ Security issue? → rms-security-auth
├─ Need new page/feature? → rms-page-builder
│  ├─ Need styling? → rms-ui
│  └─ Need queries? → rms-db
├─ Docs out of date? → rms-doc
└─ Styling/UI issue? → rms-ui
```
```

---

## 🔄 Agent Improvement Roadmap

### Phase 1: Immediate (This Week)
1. ✅ Expand `rms-security-auth.agent.md` with OWASP checklist
2. ✅ Update `AGENTS_MEMORY.md` with invocation guide
3. ✅ Add debugging scenarios to `rms-debug.agent.md`

### Phase 2: Short-term (2-4 Weeks)
1. ⏳ Create `rms-testing.agent.md`
2. ⏳ Create `rms-performance.agent.md`
3. ⏳ Create `rms-backup.agent.md`
4. ⏳ Enhance `rms-doc.agent.md` with more doc types

### Phase 3: Medium-term (1-2 Months)
1. 📋 Create `rms-deploy.agent.md` (when ready for production)
2. 📋 Create `rms-api.agent.md` (when API is built)
3. 📋 Create `rms-analytics.agent.md` (for data insights)

### Phase 4: Long-term (3-6 Months)
1. 🔮 Create `rms-mobile.agent.md` (for mobile app)
2. 🔮 Create `rms-integration.agent.md` (for third-party integrations)

---

## 📊 Agent Effectiveness Metrics

### Current Coverage
- ✅ **Database:** Excellent (rms-db)
- ✅ **UI/UX:** Excellent (rms-ui)
- ✅ **Page Building:** Excellent (rms-page-builder)
- ⚠️ **Security:** Good, needs expansion (rms-security-auth)
- ⚠️ **Debugging:** Good (rms-debug)
- ⚠️ **Documentation:** Good (rms-doc)
- ❌ **Testing:** Missing
- ❌ **Performance:** Missing
- ❌ **Backup:** Missing
- ❌ **Deployment:** Missing

### Recommended Priority
1. 🔴 **Critical:** Testing, Backup
2. 🟡 **Important:** Performance, Enhanced Security
3. 🟢 **Nice to Have:** Deployment, API (future)

---

## 💡 Best Practices for Using Agents

### 1. **One Agent Per Task**
Don't try to invoke multiple agents at once. Each agent should complete its task before moving to the next.

### 2. **Clear Task Scope**
When invoking an agent, be specific about what you need:
- ✅ "rms-db: Add index to speed up research archive queries"
- ❌ "Make the site faster"

### 3. **Let Agents Collaborate**
Agents are designed to work together:
- `rms-page-builder` will consult `rms-db` for queries
- `rms-page-builder` will consult `rms-ui` for styling
- Security issues get escalated to `rms-security-auth`

### 4. **Document Agent Decisions**
When agents make important decisions, document them in:
- `AGENTS_MEMORY.md` - For patterns
- Code comments - For implementation details
- `TODO.md` - For follow-up tasks

### 5. **Regular Agent Reviews**
Review agent effectiveness quarterly:
- Which agents are most used?
- Which agents need updates?
- Are there gaps in coverage?
- Are new agents needed?

---

## 🎯 Immediate Action Items

### This Week:
1. ✅ Review this analysis
2. ⏳ Decide which new agents to create first
3. ⏳ Expand `rms-security-auth.agent.md` with OWASP checklist
4. ⏳ Update `AGENTS_MEMORY.md` with invocation guide

### Next Week:
1. ⏳ Create `rms-testing.agent.md`
2. ⏳ Create `rms-backup.agent.md`
3. ⏳ Run security audit using enhanced `rms-security-auth`

### Month 1:
1. ⏳ Create `rms-performance.agent.md`
2. ⏳ Implement automated testing
3. ⏳ Set up automated backups

---

## 📝 Summary

**Your agent system is well-structured but has gaps:**

### Strengths:
✅ Clear separation of concerns  
✅ Good documentation  
✅ Effective cross-referencing  
✅ Consistent conventions

### Gaps:
❌ No testing agent  
❌ No performance agent  
❌ No backup/recovery agent  
❌ Security agent needs expansion

### Recommendation:
**Start by creating `rms-testing.agent.md` and `rms-backup.agent.md` this week.** These are critical for production readiness. Then expand `rms-security-auth.agent.md` with the OWASP checklist.

---

**Generated:** August 28, 2026 15:37 (UTC+8)  
**Status:** Comprehensive Analysis Complete  
**Next Review:** November 28, 2026
