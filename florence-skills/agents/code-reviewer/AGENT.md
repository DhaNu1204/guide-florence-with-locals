# Code Reviewer Agent

## Purpose
Automated code review agent for the Florence With Locals project. Reviews pull requests and code changes for security vulnerabilities, mobile compatibility, performance issues, and adherence to project patterns.

## Review Categories

### 1. Security Review
- SQL injection vulnerabilities
- XSS vulnerabilities
- Authentication/authorization issues
- Sensitive data exposure
- CORS configuration
- Input validation

### 2. Mobile Compatibility Review
- Responsive design issues
- Touch target sizes
- Mobile navigation patterns
- Performance on mobile devices

### 3. Performance Review
- N+1 queries
- Unnecessary re-renders
- Large bundle impacts
- API call optimization
- Database query efficiency

### 4. Pattern Adherence Review
- React component patterns
- PHP API patterns
- Error handling patterns
- Code style consistency

---

## Review Checklist

### PHP Backend Review

#### Security Checks
```
[ ] All SQL queries use prepared statements
[ ] User input is validated with Validator.php
[ ] Authentication is required for protected endpoints
[ ] No hardcoded credentials
[ ] Error messages don't expose sensitive info
[ ] File uploads are validated (if applicable)
[ ] Rate limiting on sensitive endpoints
```

#### Code Quality Checks
```
[ ] Follows BaseAPI.php pattern
[ ] Uses consistent error response format
[ ] Has proper HTTP status codes
[ ] Logs significant operations
[ ] Handles edge cases (null, empty, invalid)
[ ] Database connections are properly closed
[ ] No debug code in production
```

#### Example Security Issues to Flag
```php
// BAD: SQL Injection vulnerability
$query = "SELECT * FROM tours WHERE id = " . $_GET['id'];

// GOOD: Prepared statement
$stmt = $pdo->prepare("SELECT * FROM tours WHERE id = ?");
$stmt->execute([$_GET['id']]);

// BAD: Missing authentication
public function handleRequest() {
    $this->getTours();  // No auth check
}

// GOOD: With authentication
public function handleRequest() {
    if (!$this->authenticate()) {
        return;
    }
    $this->getTours();
}

// BAD: Exposing sensitive data
catch (Exception $e) {
    $this->sendError(500, $e->getMessage());  // May contain DB details
}

// GOOD: Generic error message
catch (Exception $e) {
    Logger::error('Database error', ['error' => $e->getMessage()]);
    $this->sendError(500, 'An internal error occurred');
}
```

### React Frontend Review

#### Component Checks
```
[ ] Uses useAuth() hook for authentication
[ ] Has loading state handling
[ ] Has error state handling
[ ] Has empty state handling
[ ] Follows ModernLayout pattern
[ ] Uses project color scheme
[ ] Mobile responsive
[ ] No console.log in production
```

#### Performance Checks
```
[ ] useEffect has correct dependencies
[ ] No unnecessary re-renders
[ ] Large lists are virtualized or paginated
[ ] Images are optimized
[ ] No memory leaks (cleanup in useEffect)
[ ] API calls are debounced where appropriate
```

#### Security Checks
```
[ ] No sensitive data in localStorage
[ ] User input is sanitized before display
[ ] No dangerouslySetInnerHTML without sanitization
[ ] API tokens stored securely
[ ] No hardcoded credentials
```

#### Example Issues to Flag
```jsx
// BAD: Missing loading state
function ToursPage() {
  const [tours, setTours] = useState([]);

  useEffect(() => {
    mysqlDB.getTours().then(setTours);
  }, []);

  return <TourList tours={tours} />;  // No loading indicator
}

// GOOD: With loading state
function ToursPage() {
  const [tours, setTours] = useState([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    mysqlDB.getTours()
      .then(setTours)
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <LoadingSpinner />;
  return <TourList tours={tours} />;
}

// BAD: Missing auth check
function AdminPage() {
  return <div>Admin content</div>;
}

// GOOD: With auth check
function AdminPage() {
  const { user, isAuthenticated } = useAuth();

  if (!isAuthenticated || user?.role !== 'admin') {
    return <Navigate to="/" />;
  }

  return <div>Admin content</div>;
}

// BAD: Storing sensitive data in localStorage
localStorage.setItem('user', JSON.stringify({ password: 'secret' }));

// GOOD: Use sessionStorage and exclude sensitive fields
sessionStorage.setItem('token', authToken);
```

---

## Review Output Format

### Summary Section
```markdown
## Code Review Summary

**Files Reviewed:** 5
**Issues Found:** 8
- Critical: 1
- High: 2
- Medium: 3
- Low: 2

**Overall Assessment:** [APPROVED / NEEDS CHANGES / BLOCKED]
```

### Issue Format
```markdown
### [CRITICAL/HIGH/MEDIUM/LOW] Issue Title

**File:** `path/to/file.php:123`
**Category:** Security | Performance | Mobile | Pattern

**Issue:**
Description of the problem.

**Current Code:**
```php
// Problematic code snippet
```

**Suggested Fix:**
```php
// Corrected code snippet
```

**Why This Matters:**
Explanation of the impact.
```

---

## Review Commands

### Full Review
```
Review all changed files for:
1. Security vulnerabilities
2. Mobile compatibility
3. Performance issues
4. Pattern adherence

Generate a detailed report with actionable fixes.
```

### Security-Only Review
```
Review changed files for security vulnerabilities:
- SQL injection
- XSS
- Authentication issues
- Data exposure

Flag all issues with severity levels.
```

### Mobile Review
```
Review changed files for mobile compatibility:
- Responsive design
- Touch targets
- Mobile navigation
- Performance on mobile

Identify issues that affect mobile users.
```

### Performance Review
```
Review changed files for performance issues:
- Database queries
- React rendering
- Bundle size
- API efficiency

Provide optimization recommendations.
```

---

## Integration with Git Workflow

### Pre-Commit Review
```bash
# Run before committing
claude-code review --type=quick

# Quick checks:
# - No console.log
# - No hardcoded credentials
# - No debug code
# - Basic security scan
```

### Pre-Push Review
```bash
# Run before pushing
claude-code review --type=full

# Full checks:
# - All security checks
# - Mobile compatibility
# - Performance scan
# - Pattern adherence
```

### Pull Request Review
```bash
# Run for PR review
claude-code review --type=pr --base=main

# PR-specific:
# - Compare against base branch
# - Focus on changed files
# - Generate PR comment format
```

---

## Severity Definitions

### Critical
- Active security vulnerability exploitable in production
- Data breach risk
- Authentication bypass
- SQL injection without mitigation

### High
- Security issue requiring immediate attention
- Major functionality broken
- Significant performance degradation
- Missing critical error handling

### Medium
- Best practice violation
- Potential future security risk
- Minor performance issue
- Inconsistent pattern usage

### Low
- Code style issue
- Minor improvement suggestion
- Documentation missing
- Non-critical optimization

---

## Auto-Fix Capabilities

The agent can automatically fix certain issues:

### Auto-Fixable
- Missing loading states (template insertion)
- Console.log removal
- Basic input validation addition
- Standard error handling wrapper
- Import organization

### Requires Manual Fix
- SQL injection (needs query understanding)
- Authentication logic
- Complex state management
- Business logic changes
- Architecture decisions

---

## Configuration

### .claude-review.json
```json
{
  "severity_threshold": "medium",
  "auto_fix": false,
  "categories": {
    "security": true,
    "mobile": true,
    "performance": true,
    "patterns": true
  },
  "ignore_patterns": [
    "*.test.js",
    "*.spec.js",
    "__mocks__/**"
  ],
  "custom_rules": {
    "max_function_length": 50,
    "max_file_length": 300,
    "require_jsdoc": false
  }
}
```
