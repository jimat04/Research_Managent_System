# RMS Agents Analysis & Recommendations

**Original analysis:** August 28, 2026
**Inventory updated:** October 11, 2026
**Location:** `.github/agents/`
**Current RMS Agents:** 9 specialized agents

---

## 📊 Current Agent Overview

The current RMS inventory contains nine specialized Copilot agents:

| Agent | Purpose |
|-------|---------|
| `rms-db` | MySQL schema, migrations, seed data, and prepared-statement queries |
| `rms-debug` | XAMPP troubleshooting and runtime issue diagnosis |
| `rms-doc` | README, TODO, and inline documentation maintenance |
| `rms-backup` | Secure database/upload backups and tested recovery procedures |
| `rms-performance` | Evidence-based PHP, MySQL, and browser performance work |
| `rms-page-builder` | Role-specific procedural PHP pages |
| `rms-security-auth` | RMS authentication, authorization, and security work |
| `rms-testing` | Browser E2E and future unit, integration, and security tests |
| `rms-ui` | RMS styling, components, responsiveness, and accessibility |

Agent instructions are in `.github/agents/`; the project-specific summary and cross-references are in `.github/agents/AGENTS_MEMORY.md`.

## Local Claude Skills

The local Claude profile contains 26 skills under `C:\Users\jimwe\.claude\skills\`, each with its own `SKILL.md`. No Claude agent files were found in `C:\Users\jimwe\.claude\agents\` or the project `.claude\agents\` directory. These are separate from the RMS Copilot agents listed above.

**Design and UI:** `banner-design`, `brand`, `brand-guidelines`, `brandkit`, `canvas-design`, `design`, `design-system`, `design-taste-frontend`, `design-taste-frontend-v1`, `frontend-design`, `gpt-taste`, `high-end-visual-design`, `imagegen-frontend-mobile`, `imagegen-frontend-web`, `image-to-code`, `industrial-brutalist-ui`, `minimalist-ui`, `redesign-existing-projects`, `stitch-design-taste`, `ui-styling`, `ui-ux-pro-max`.

**Other capabilities:** `find-skills`, `full-output-enforcement`, `slides`, `webapp-testing`, `web-artifacts-builder`.

Read a matching skill's `SKILL.md` before applying its guidance. `AGENTS_MEMORY.md` also keeps this local skill index.

---

## ⚠️ Gaps & Follow-up Recommendations

The recommendations below originated in the August 28 analysis. The inventory has since changed; in particular, a testing agent now exists. Treat proposed agents and work as a backlog, not as a description of the current inventory.

### 1. **Testing/QA Agent and Browser Suite Added**
`rms-testing.agent.md` and `tests/e2e-browser/` now exist. The 13-test Playwright suite passed locally on October 10, 2026; it requires the local app/database and `RMS_E2E_*` credentials supplied through the environment. There is still no configured PHPUnit suite, general PHP build/lint command, or CI test workflow.

**Follow-up:** Broaden automated coverage beyond browser E2E and add a repeatable CI or local quality gate when the project is ready; do not describe that broader infrastructure as already configured.

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

### 3. **Performance Agent Added**
`rms-performance.agent.md` now owns evidence-based performance investigation. This adds agent guidance, not an always-on monitoring system or verified performance targets.

### 4. **Backup and Recovery Agent Added**
`rms-backup.agent.md` covers secure backup and restore planning. The existing backup script is not production-ready, and the presence of the agent does not mean a restore procedure has been tested.

### 5. **rms-security-auth: Needs Updates**
**Current:** 3,460 bytes (smallest agent)  
**Issue:** Brief compared to importance

**Recommendations:**
- Add more security check patterns
- Include OWASP Top 10 checklist
- Add file upload security details
- Include rate limiting patterns
- Add security testing procedures

### 6. **No API Agent**
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

#### 1. Testing agent (implemented)
**Purpose:** Automated testing and quality assurance

The original analysis proposed a testing agent; `rms-testing.agent.md` now exists. The sample definition below is historical and is superseded by that agent file.

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

#### 2. Performance agent (implemented)
See `rms-performance.agent.md` for the current evidence-based guidance. The original sample above was removed because it proposed speculative Redis use and unvalidated performance targets.

#### 3. Backup agent (implemented)
See `rms-backup.agent.md` for the current recovery and secret-handling guidance. Schedules and retention periods remain requirements to agree with stakeholders, not defaults to assume.

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
1. ✅ Create `rms-testing.agent.md` (implemented)
2. ✅ Create `rms-performance.agent.md` (implemented)
3. ✅ Create `rms-backup.agent.md` (implemented)
4. ✅ Enhance `rms-doc.agent.md` with user and developer documentation responsibilities

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
- ✅ **Testing agent:** Available (rms-testing)
- ⚠️ **Test infrastructure:** Playwright browser E2E is configured and passed 13 tests locally; PHPUnit and CI are not configured
- ✅ **Performance agent:** Available; no continuous monitoring or agreed targets are configured
- ✅ **Backup agent:** Available; restore process still needs a verified rehearsal
- ❌ **Deployment:** Missing

### Recommended Priority
1. 🔴 **Critical:** Broader automated test coverage and a verified backup/restore procedure
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
1. ✅ Refresh the agent inventory and backlog status
2. ✅ Add the performance and backup/recovery agents
3. ✅ Expand `rms-doc.agent.md` and update the agent memory index

### Next Week:
1. ✅ Create `rms-testing.agent.md` (implemented)
2. ✅ Create `rms-backup.agent.md` (implemented)
3. ⏳ Run security audit using enhanced `rms-security-auth`

### Month 1:
1. ✅ Create `rms-performance.agent.md` (implemented)
2. ⏳ Expand automated testing beyond browser E2E and add CI coverage
3. ⏳ Implement and rehearse automated backup/restore procedures

---

## 📝 Summary

**Your agent system is well-structured but has gaps:**

### Strengths:
✅ Clear separation of concerns  
✅ Good documentation  
✅ Effective cross-referencing  
✅ Consistent conventions

### Gaps:
✅ Testing agent exists (`rms-testing.agent.md`)
⚠️ Playwright E2E is configured and passed locally; PHPUnit and CI coverage are not configured
✅ Performance agent exists; no continuous monitoring is configured
✅ Backup/recovery agent exists; restore procedures still need testing
⚠️ Review security-agent recommendations against current security requirements

### Recommendation:
**Prioritize broader automated coverage and a verified backup/restore procedure.** Consider deployment automation when the project is ready for production, and review `rms-security-auth.agent.md` before deciding whether its guidance needs further expansion.

---

**Original analysis generated:** August 28, 2026 15:37 (UTC+8)
**Inventory refreshed:** October 11, 2026
**Next review:** Reconcile the remaining backlog with actual project priorities
