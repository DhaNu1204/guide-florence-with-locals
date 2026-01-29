# Performance & Security

**Last Updated**: January 29, 2026

## Frontend Optimization

### Caching Strategy
- **localStorage**: 1-minute expiry for tour data
- **Cache Management**: Force refresh functionality for latest data

### Bundle Optimization
- **Build System**: Vite with code splitting
- **Hot Module Replacement**: Fast development experience

### Responsive Images
- Efficient loading and rendering
- Optimized for different screen sizes

### State Management
- Optimized React context usage
- Minimal re-renders

## Backend Optimization

### Database Queries
- Efficient JOIN operations for related data
- Proper indexing on frequently queried columns
- Prepared statements for security

### Connection Pool
- MySQLi connection management
- Efficient connection reuse

### Caching Headers
- Proper HTTP caching directives
- API response optimization

### Error Logging
- Comprehensive logging for debugging
- PHP error log integration

## Security Features

### API Rate Limiting (NEW - Jan 29, 2026)
Database-backed rate limiting protects all endpoints.

| Endpoint Type | Limit | Window |
|---------------|-------|--------|
| Login | 5 requests | per minute |
| Read (GET) | 100 requests | per minute |
| Write (POST) | 30 requests | per minute |
| Update (PUT) | 30 requests | per minute |
| Delete | 10 requests | per minute |
| Bokun Sync | 10 requests | per minute |

**Features:**
- Automatic IP detection (supports Cloudflare, proxies)
- HTTP headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset
- 429 Too Many Requests response with Retry-After header
- Self-cleaning database records

**Implementation:** `public_html/api/RateLimiter.php`

### SQL Injection Prevention
- Prepared statements throughout
- Parameter binding for all queries

### Input Validation
- Frontend validation for user input
- Backend validation for all API requests
- Data sanitization
- `public_html/api/Validator.php` utility class

### Authentication & Authorization
- Secure session management
- Session tokens stored in database
- Role-based access control (admin/viewer)
- Login rate limiting (5 attempts/min)

### CORS Configuration
- Controlled cross-origin access
- Proper headers for API endpoints

### Password Security
- Secure password hashing
- No plain text password storage

### Data Protection
- HTTPS encryption (production)
- Secure API communication
- Protected database credentials
- Environment variables via `EnvLoader.php`

## Performance Monitoring

### Response Time Tracking
- API endpoint performance monitoring
- Database query optimization

### Error Tracking
- Backend API errors logged
- Frontend error boundaries
- User-friendly error messages

### Database Monitoring
- Connection performance
- Query execution time
- Table optimization
