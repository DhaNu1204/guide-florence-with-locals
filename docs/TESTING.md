# Testing & Quality Assurance

**Last Updated**: January 29, 2026

## Automated Testing (NEW - Jan 29, 2026)

### React Testing with Vitest
✅ **52 tests passing** with comprehensive coverage

#### Test Stack
- **Vitest** - Test runner, compatible with Vite
- **@testing-library/react** - React component testing
- **@testing-library/jest-dom** - DOM assertions
- **@testing-library/user-event** - User interaction simulation
- **jsdom** - DOM environment

#### Test Commands
```bash
# Run tests in watch mode
npm test

# Run tests once
npm run test:run

# Run with coverage report
npm run test:coverage
```

#### Test Coverage by Component

| Component | Tests | Description |
|-----------|-------|-------------|
| Button.jsx | 23 | Rendering, clicks, loading, disabled, icons, accessibility |
| Login.jsx | 16 | Form rendering, input handling, validation, structure |
| mysqlDB.js | 13 | API calls, error handling, caching, pagination |
| **Total** | **52** | All passing |

#### Test File Locations
```
src/
├── components/
│   └── __tests__/
│       └── Button.test.jsx     # UI component tests
├── pages/
│   └── __tests__/
│       └── Login.test.jsx      # Page component tests
├── services/
│   └── __tests__/
│       └── mysqlDB.test.js     # API service tests
└── test/
    └── setup.js                # Test environment setup
```

### PHP API Tests
Simple test runner (no PHPUnit required for shared hosting compatibility).

#### Running PHP Tests
```bash
php public_html/api/tests/run_tests.php
```

#### Test Coverage
- Authentication endpoints (auth.php)
- Tours CRUD operations
- Guides CRUD operations
- Rate limiting verification
- API response format validation

---

## Manual Testing Checklist

### CRUD Operations ✅
- ✅ All CRUD operations for Tours
- ✅ All CRUD operations for Guides
- ✅ All CRUD operations for Tickets
- ✅ All CRUD operations for Priority Tickets
- ✅ Payment transaction management
- ✅ Guide assignment and notes persistence

### Authentication & Authorization ✅
- ✅ User authentication (login/logout)
- ✅ Session management
- ✅ Role-based access control
- ✅ Session token validation
- ✅ Rate limiting (5 attempts/minute)

### User Interface ✅
- ✅ Responsive design across devices
- ✅ Mobile responsiveness (all pages)
- ✅ Desktop layout optimization
- ✅ Table column width balancing
- ✅ Inline editing functionality
- ✅ Modal interactions
- ✅ Navigation and routing
- ✅ PDF report generation

### Database ✅
- ✅ Database connection and query performance
- ✅ All tables and relationships verified
- ✅ Foreign key constraints
- ✅ Data integrity
- ✅ Migration processes
- ✅ VIEW queries (guide_payment_summary)

### API Endpoints ✅
- ✅ API endpoint functionality
- ✅ Error handling
- ✅ CORS configuration
- ✅ Request/response validation
- ✅ Authentication middleware
- ✅ Rate limiting headers
- ✅ Pending tours endpoint

### Integration ✅
- ✅ Frontend-backend data synchronization
- ✅ Bokun API integration
- ✅ Museum ticket inventory management
- ✅ Priority Tickets inline notes editing
- ✅ Dashboard chronological sorting
- ✅ Payment system with date filtering
- ✅ PDF report downloads

---

## Test Coverage Summary

### API Endpoints
- **Coverage**: 100% of core functionality tested
- **Methods**: GET, POST, PUT, DELETE
- **Status Codes**: Proper HTTP status codes verified
- **Error Scenarios**: Comprehensive error handling tested
- **Rate Limiting**: 429 responses verified

### Database Operations
- **Coverage**: All tables and relationships verified
- **Integrity**: Foreign keys and constraints tested
- **Performance**: Query optimization verified
- **VIEWs**: guide_payment_summary, monthly_payment_summary

### UI Components
- **Coverage**: 52 automated tests + manual testing
- **Browsers**: Tested on Chrome, Firefox, Safari
- **Screen Sizes**: Mobile (320px+), Tablet (768px+), Desktop (1024px+)

### Error Scenarios
- **Error Handling**: Proper error messages and user feedback
- **Edge Cases**: Null values, empty data, invalid input
- **Network Errors**: API failure handling
- **Database Errors**: Connection issues, query failures
- **Rate Limit Errors**: 429 responses with Retry-After header

---

## Writing New Tests

### React Component Tests
```jsx
// src/components/__tests__/MyComponent.test.jsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import MyComponent from '../MyComponent';

describe('MyComponent', () => {
  it('renders correctly', () => {
    render(<MyComponent title="Test" />);
    expect(screen.getByText('Test')).toBeInTheDocument();
  });

  it('handles click events', async () => {
    const handleClick = vi.fn();
    render(<MyComponent onClick={handleClick} />);

    fireEvent.click(screen.getByRole('button'));
    expect(handleClick).toHaveBeenCalledTimes(1);
  });
});
```

### API Service Tests
```javascript
// src/services/__tests__/myService.test.js
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { myApiFunction } from '../myService';

// Mock fetch globally
global.fetch = vi.fn();

describe('myApiFunction', () => {
  beforeEach(() => {
    fetch.mockClear();
  });

  it('returns data on success', async () => {
    fetch.mockResolvedValueOnce({
      ok: true,
      json: () => Promise.resolve({ data: 'test' }),
    });

    const result = await myApiFunction();
    expect(result.data).toBe('test');
  });

  it('handles errors correctly', async () => {
    fetch.mockRejectedValueOnce(new Error('Network error'));

    await expect(myApiFunction()).rejects.toThrow('Network error');
  });
});
```

---

## Testing Tools

### Automated Testing
- **Vitest** - Fast unit test runner
- **React Testing Library** - Component testing
- **jsdom** - DOM simulation

### Manual Testing
- Browser DevTools
- Network tab for API inspection
- Console for error debugging
- Responsive design mode

### Database Testing
- phpMyAdmin for query testing
- Direct SQL queries for verification
- Data integrity checks

---

## Known Issues

Currently no known critical issues. System is fully operational on production.

### Resolved Issues (Jan 2026)
1. ✅ Guide payment summary showing €0.00 - Fixed VIEW table reference
2. ✅ Pending payments count inconsistent - Added authoritative API endpoint
3. ✅ PDF autoTable function error - Fixed import pattern

---

## Future Testing Improvements

- [ ] End-to-end testing with Playwright
- [ ] Visual regression testing
- [ ] Performance testing (load testing)
- [ ] API contract testing
- [ ] Increase unit test coverage to 80%+
