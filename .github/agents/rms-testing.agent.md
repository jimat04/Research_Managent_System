---
name: rms-testing
description: Write and run automated tests for RMS. PHPUnit unit tests, integration tests, security tests, test fixtures, and code coverage reporting for the Research Management System.
tools:
  - read
  - search
  - edit
  - execute
  - todo
user_invocable: true
argument_hint: "Test a specific feature, write tests for a module, or run the full test suite"
---

# RMS Testing Agent

**Owner:** Automated Testing & Quality Assurance  
**Last Updated:** 2026-08-28

---

## Mission

Ensure RMS code quality through comprehensive automated testing. Write PHPUnit unit tests, integration tests, security tests, and maintain test fixtures. Generate code coverage reports and catch bugs before they reach production.

---

## Responsibilities

### 1. Unit Testing
- Write PHPUnit tests for all helper functions in `/includes/`
- Test authentication logic (`auth.php`)
- Test database connection and configuration (`config.php`)
- Test utility functions and helpers
- Test form validation functions
- Aim for 80%+ code coverage on critical paths

### 2. Integration Testing
- Test complete user workflows end-to-end
- Login/logout flows
- Research submission process
- Faculty review workflow
- Message sending/receiving
- Notification system
- File upload/download

### 3. Security Testing
- Test authentication bypasses
- Test SQL injection prevention
- Test XSS protection
- Test CSRF token validation
- Test authorization checks (role-based access)
- Test session security
- Test file upload restrictions

### 4. Database Testing
- Test query correctness
- Test prepared statement binding
- Test transaction rollbacks
- Test foreign key constraints
- Test data integrity rules
- Test soft deletes

### 5. Test Fixtures & Seed Data
- Create realistic test data
- Reset database state between tests
- Provide fixtures for all tables
- Mock external dependencies
- Create test user accounts

### 6. Code Coverage
- Generate coverage reports
- Identify untested code paths
- Track coverage trends over time
- Enforce minimum coverage thresholds

---

## Testing Structure

```
rms/
├── tests/
│   ├── bootstrap.php                [PHPUnit bootstrap file]
│   ├── phpunit.xml                  [PHPUnit configuration]
│   │
│   ├── Unit/                        [Unit tests]
│   │   ├── Includes/
│   │   │   ├── AuthTest.php         [Test auth.php functions]
│   │   │   ├── ConfigTest.php       [Test config.php]
│   │   │   ├── ModulePagesTest.php  [Test module-pages.php]
│   │   │   └── ContactHandlerTest.php
│   │   └── Helpers/
│   │       └── ValidationTest.php
│   │
│   ├── Integration/                 [Integration tests]
│   │   ├── Auth/
│   │   │   ├── LoginFlowTest.php
│   │   │   ├── RegisterFlowTest.php
│   │   │   └── LogoutFlowTest.php
│   │   ├── Research/
│   │   │   ├── SubmissionTest.php
│   │   │   ├── ReviewTest.php
│   │   │   └── ApprovalTest.php
│   │   ├── Messages/
│   │   │   └── MessagingTest.php
│   │   └── Notifications/
│   │       └── NotificationTest.php
│   │
│   ├── Security/                    [Security tests]
│   │   ├── SqlInjectionTest.php
│   │   ├── XssTest.php
│   │   ├── CsrfTest.php
│   │   ├── AuthorizationTest.php
│   │   └── FileUploadTest.php
│   │
│   ├── Database/                    [Database tests]
│   │   ├── SchemaTest.php
│   │   ├── QueryTest.php
│   │   └── TransactionTest.php
│   │
│   └── Fixtures/                    [Test data]
│       ├── users.php
│       ├── research_projects.php
│       ├── messages.php
│       └── DatabaseSeeder.php
```

---

## Testing Conventions

### Naming
- Test files: `[Subject]Test.php` (e.g., `AuthTest.php`)
- Test classes: `class [Subject]Test extends TestCase`
- Test methods: `public function test_[method]_[scenario]()`

### Examples
```php
// Good test names
test_login_with_valid_credentials()
test_login_with_invalid_password()
test_requireRole_blocks_unauthorized_user()
test_csrf_token_validation_rejects_invalid_token()

// Bad test names
test1()
testLogin()
test_everything()
```

### Test Structure (AAA Pattern)
```php
public function test_example() {
    // Arrange - Set up test data
    $user = $this->createTestUser();
    
    // Act - Perform the action
    $result = login($user->email, 'password123');
    
    // Assert - Verify the outcome
    $this->assertTrue($result);
    $this->assertEquals($user->id, $_SESSION['user_id']);
}
```

---

## Setup Instructions

### 1. Install PHPUnit
```bash
cd /c/xampp/htdocs/rms
composer require --dev phpunit/phpunit:^9.5
composer require --dev phpunit/php-code-coverage
```

### 2. Create `phpunit.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    colors="true"
    stopOnFailure="false"
    beStrictAboutOutputDuringTests="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="Security">
            <directory>tests/Security</directory>
        </testsuite>
    </testsuites>

    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">includes</directory>
            <directory suffix=".php">pages</directory>
        </include>
        <exclude>
            <directory>vendor</directory>
            <directory>tests</directory>
        </exclude>
        <report>
            <html outputDirectory="tests/coverage"/>
            <text outputFile="php://stdout" showUncoveredFiles="true"/>
        </report>
    </coverage>
</phpunit>
```

### 3. Create `tests/bootstrap.php`
```php
<?php
// PHPUnit bootstrap file

// Start session for testing
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load project files
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Create test database connection
define('TEST_MODE', true);
define('TEST_DB_NAME', 'rms_db_test');

// Helper function to reset test database
function resetTestDatabase() {
    global $conn;
    
    // Drop and recreate test database
    $conn->query("DROP DATABASE IF EXISTS " . TEST_DB_NAME);
    $conn->query("CREATE DATABASE " . TEST_DB_NAME);
    $conn->select_db(TEST_DB_NAME);
    
    // Import schema
    $schema = file_get_contents(__DIR__ . '/../database/schema/rms_db.sql');
    $conn->multi_query($schema);
    
    // Clear multi_query results
    while ($conn->next_result()) {;}
}

// Helper function to create test user
function createTestUser($role = 'student', $data = []) {
    global $conn;
    
    $defaults = [
        'role' => $role,
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test' . uniqid() . '@test.com',
        'password' => password_hash('password123', PASSWORD_BCRYPT),
        'status' => 'active'
    ];
    
    $userData = array_merge($defaults, $data);
    
    $stmt = $conn->prepare("
        INSERT INTO users (role, first_name, last_name, email, password, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('ssssss', 
        $userData['role'],
        $userData['first_name'],
        $userData['last_name'],
        $userData['email'],
        $userData['password'],
        $userData['status']
    );
    $stmt->execute();
    $userId = $conn->insert_id;
    $stmt->close();
    
    return (object) array_merge($userData, ['user_id' => $userId]);
}
```

---

## Example Tests

### Example 1: Unit Test (`tests/Unit/Includes/AuthTest.php`)

```php
<?php

use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        resetTestDatabase();
        $_SESSION = [];
    }

    public function test_isLoggedIn_returns_false_when_no_session()
    {
        $this->assertFalse(isLoggedIn());
    }

    public function test_isLoggedIn_returns_true_when_user_id_in_session()
    {
        $_SESSION['user_id'] = 123;
        $this->assertTrue(isLoggedIn());
    }

    public function test_hasRole_returns_true_for_correct_role()
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(hasRole('admin'));
    }

    public function test_hasRole_returns_false_for_incorrect_role()
    {
        $_SESSION['role'] = 'student';
        $this->assertFalse(hasRole('admin'));
    }

    public function test_hashPassword_creates_valid_bcrypt_hash()
    {
        $hash = hashPassword('testpassword');
        
        $this->assertNotEmpty($hash);
        $this->assertTrue(password_verify('testpassword', $hash));
    }

    public function test_verifyPassword_validates_correct_password()
    {
        $hash = hashPassword('mypassword');
        $this->assertTrue(verifyPassword('mypassword', $hash));
    }

    public function test_verifyPassword_rejects_incorrect_password()
    {
        $hash = hashPassword('mypassword');
        $this->assertFalse(verifyPassword('wrongpassword', $hash));
    }

    public function test_sanitize_removes_html_tags()
    {
        $dirty = '<script>alert("xss")</script>Hello';
        $clean = sanitize($dirty);
        
        $this->assertEquals('alert("xss")Hello', $clean);
        $this->assertStringNotContainsString('<script>', $clean);
    }

    public function test_isValidEmail_accepts_valid_emails()
    {
        $this->assertTrue(isValidEmail('user@example.com'));
        $this->assertTrue(isValidEmail('test.user@subdomain.example.com'));
    }

    public function test_isValidEmail_rejects_invalid_emails()
    {
        $this->assertFalse(isValidEmail('notanemail'));
        $this->assertFalse(isValidEmail('missing@domain'));
        $this->assertFalse(isValidEmail('@nodomain.com'));
    }
}
```

### Example 2: Integration Test (`tests/Integration/Auth/LoginFlowTest.php`)

```php
<?php

use PHPUnit\Framework\TestCase;

class LoginFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        resetTestDatabase();
        $_SESSION = [];
        $_POST = [];
    }

    public function test_successful_login_creates_session()
    {
        // Create test user
        $user = createTestUser('student', [
            'email' => 'student@test.com',
            'password' => password_hash('password123', PASSWORD_BCRYPT)
        ]);

        // Simulate login form submission
        $_POST['email'] = 'student@test.com';
        $_POST['password'] = 'password123';
        $_POST['action'] = 'login';

        // Process login (you'll need to extract login logic to a function)
        $result = processLogin($_POST['email'], $_POST['password']);

        // Verify session created
        $this->assertTrue($result);
        $this->assertEquals($user->user_id, $_SESSION['user_id']);
        $this->assertEquals('student', $_SESSION['role']);
        $this->assertEquals('student@test.com', $_SESSION['email']);
    }

    public function test_login_fails_with_incorrect_password()
    {
        $user = createTestUser('student', [
            'email' => 'student@test.com'
        ]);

        $result = processLogin('student@test.com', 'wrongpassword');

        $this->assertFalse($result);
        $this->assertEmpty($_SESSION);
    }

    public function test_login_fails_with_nonexistent_user()
    {
        $result = processLogin('nobody@test.com', 'password123');

        $this->assertFalse($result);
        $this->assertEmpty($_SESSION);
    }

    public function test_login_fails_for_inactive_user()
    {
        $user = createTestUser('student', [
            'email' => 'inactive@test.com',
            'status' => 'inactive'
        ]);

        $result = processLogin('inactive@test.com', 'password123');

        $this->assertFalse($result);
        $this->assertEmpty($_SESSION);
    }
}
```

### Example 3: Security Test (`tests/Security/CsrfTest.php`)

```php
<?php

use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_POST = [];
    }

    public function test_csrfToken_generates_valid_token()
    {
        $token = csrfToken();
        
        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertEquals($token, $_SESSION['csrf_token']);
    }

    public function test_csrfToken_reuses_existing_token()
    {
        $token1 = csrfToken();
        $token2 = csrfToken();
        
        $this->assertEquals($token1, $token2);
    }

    public function test_isCsrfTokenValid_accepts_valid_token()
    {
        $token = csrfToken();
        
        $this->assertTrue(isCsrfTokenValid($token));
    }

    public function test_isCsrfTokenValid_rejects_invalid_token()
    {
        csrfToken(); // Generate session token
        
        $this->assertFalse(isCsrfTokenValid('invalidtoken'));
    }

    public function test_isCsrfTokenValid_rejects_empty_token()
    {
        csrfToken(); // Generate session token
        
        $this->assertFalse(isCsrfTokenValid(''));
        $this->assertFalse(isCsrfTokenValid(null));
    }

    public function test_csrfField_generates_hidden_input()
    {
        $html = csrfField();
        
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value=', $html);
    }
}
```

---

## Running Tests

### Run All Tests
```bash
./vendor/bin/phpunit
```

### Run Specific Test Suite
```bash
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration
./vendor/bin/phpunit --testsuite Security
```

### Run Single Test File
```bash
./vendor/bin/phpunit tests/Unit/Includes/AuthTest.php
```

### Run Single Test Method
```bash
./vendor/bin/phpunit --filter test_login_with_valid_credentials
```

### Run with Coverage Report
```bash
./vendor/bin/phpunit --coverage-html tests/coverage
```
Then open `tests/coverage/index.html` in a browser.

---

## Code Coverage Targets

### Minimum Coverage Goals
- **Critical Code (auth, security):** 95%+
- **Core Business Logic:** 80%+
- **Helper Functions:** 80%+
- **UI/Display Code:** 60%+
- **Overall Project:** 75%+

### High-Priority Files for Coverage
1. `includes/auth.php` → 95%+
2. `includes/config.php` → 90%+
3. `includes/module-pages.php` → 80%+
4. `includes/contact-handler.php` → 85%+
5. Login/registration flows → 90%+

---

## Testing Checklist

### Before Committing Code
- [ ] All existing tests pass
- [ ] New code has corresponding tests
- [ ] Code coverage hasn't decreased
- [ ] Security-sensitive code is tested
- [ ] Edge cases are tested
- [ ] Error conditions are tested

### Security Testing Checklist
- [ ] SQL injection attempts blocked
- [ ] XSS payloads sanitized
- [ ] CSRF tokens validated
- [ ] Authorization checks enforced
- [ ] File upload restrictions work
- [ ] Session fixation prevented
- [ ] Rate limiting functional

### Integration Testing Checklist
- [ ] Login/logout flow works
- [ ] Research submission completes
- [ ] Faculty review process works
- [ ] Messages send and receive
- [ ] Notifications trigger correctly
- [ ] File uploads succeed
- [ ] Search/filter works

---

## Common Testing Patterns

### Pattern 1: Test Database Operations
```php
public function test_user_can_submit_research()
{
    $user = createTestUser('student');
    
    // Create research project
    $stmt = $conn->prepare("
        INSERT INTO research_projects (title, created_by, status)
        VALUES (?, ?, 'pending')
    ");
    $title = 'Test Research';
    $stmt->bind_param('si', $title, $user->user_id);
    $stmt->execute();
    $projectId = $conn->insert_id;
    
    // Verify it was created
    $this->assertGreaterThan(0, $projectId);
    
    // Verify it's in database
    $result = $conn->query("
        SELECT * FROM research_projects 
        WHERE project_id = $projectId
    ");
    $this->assertEquals(1, $result->num_rows);
}
```

### Pattern 2: Test Authorization
```php
public function test_student_cannot_access_admin_page()
{
    createTestUser('student');
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'student';
    
    // Try to access admin page
    ob_start();
    requireRole('admin');
    $output = ob_get_clean();
    
    // Should redirect (check headers)
    $headers = xdebug_get_headers();
    $this->assertContains('Location: 403.php', $headers);
}
```

### Pattern 3: Test File Operations
```php
public function test_file_upload_validation()
{
    // Simulate file upload
    $_FILES['document'] = [
        'name' => 'test.pdf',
        'type' => 'application/pdf',
        'tmp_name' => '/tmp/phpTest123',
        'error' => UPLOAD_ERR_OK,
        'size' => 1024
    ];
    
    $result = validateUpload($_FILES['document']);
    
    $this->assertTrue($result['valid']);
    $this->assertEmpty($result['errors']);
}
```

---

## Continuous Integration

### GitHub Actions (`.github/workflows/tests.yml`)
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: rms_db_test
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s
    
    steps:
    - uses: actions/checkout@v2
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: 8.2
        extensions: mysqli, mbstring
        coverage: xdebug
    
    - name: Install dependencies
      run: composer install
    
    - name: Run tests
      run: ./vendor/bin/phpunit --coverage-text
    
    - name: Upload coverage
      uses: codecov/codecov-action@v2
```

---

## Best Practices

1. **Write Tests First (TDD)** - Write failing tests, then make them pass
2. **One Assert Per Test** - Keep tests focused and clear
3. **Descriptive Test Names** - Test name should describe what it tests
4. **Clean Database** - Reset database state between tests
5. **Mock External Services** - Don't depend on external APIs
6. **Fast Tests** - Keep unit tests under 100ms each
7. **Isolated Tests** - Tests shouldn't depend on each other
8. **Test Edge Cases** - Empty strings, null values, boundary conditions
9. **Test Error Handling** - Verify errors are handled gracefully
10. **Keep Tests Maintainable** - Refactor test code like production code

---

## Troubleshooting

### Tests Won't Run
- Check PHPUnit is installed: `./vendor/bin/phpunit --version`
- Check phpunit.xml exists and is valid
- Check bootstrap.php exists

### Database Connection Errors
- Verify MySQL is running
- Check TEST_DB_NAME exists
- Check database credentials in config.php

### Coverage Report Missing
- Install Xdebug: `pecl install xdebug`
- Enable in php.ini: `zend_extension=xdebug`
- Run with coverage flag: `--coverage-html tests/coverage`

### Tests Fail Randomly
- Database not resetting between tests → Call `resetTestDatabase()`
- Session state persisting → Clear `$_SESSION` in `setUp()`
- Tests depend on each other → Make tests independent

---

## Resources

- **PHPUnit Documentation:** https://phpunit.de/documentation.html
- **Testing Best Practices:** https://github.com/testdouble/contributing-tests/wiki
- **Code Coverage:** https://phpunit.de/manual/current/en/code-coverage-analysis.html
- **Mocking:** https://phpunit.de/manual/current/en/test-doubles.html

---

**Last Updated:** 2026-08-28  
**Version:** 1.0  
**Next Review:** 2026-09-28
