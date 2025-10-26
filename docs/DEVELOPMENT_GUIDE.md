# Development Guide

## Start Development Environment

### Terminal 1 - Start Frontend (React + Vite)

**ALWAYS use port 5173**

```bash
cd guide-florence-with-locals
npm run dev
```

### Terminal 2 - Start PHP Backend Server

```bash
cd guide-florence-with-locals/public_html
php -S localhost:8080
```

## ⚠️ IMPORTANT: Port Management

- **ALWAYS use port 5173** for the frontend - this is the standard development port
- **Before starting development**: Kill any existing processes using ports 5173-5178
- **Never run multiple frontend servers** on different ports simultaneously
- **If port 5173 is occupied**: Stop the existing process first

## Port Management Commands

### Check What's Using Port 5173

```bash
netstat -ano | findstr :5173
```

### Kill Process Using Port 5173

```bash
# Replace PID with actual process ID
taskkill //PID [PID_NUMBER] //F
```

### Start Fresh Development Server

```bash
cd guide-florence-with-locals
npm run dev
```

## Testing Bokun Integration

1. Navigate to http://localhost:5173
2. Login with admin credentials
3. Go to "Bokun Integration" in sidebar navigation
4. Click "API Monitor" tab to see real-time diagnostics
5. Run diagnostics to confirm API status and permissions

## Development Workflow

### Making Changes

1. Make code changes in the appropriate files
2. Frontend changes automatically hot-reload (Vite)
3. Backend changes require PHP server restart

### Testing Changes

1. Test in browser at http://localhost:5173
2. Check browser console for frontend errors
3. Check PHP server terminal for backend errors
4. Verify database changes using phpMyAdmin or database client

### Database Changes

1. Make schema changes in development database first
2. Test thoroughly
3. Document changes in migration SQL files
4. Apply to production database when ready

## Deployment Process

See separate deployment documentation files:
- `DEPLOYMENT_PLAN.md` - Complete deployment guide
- `MIGRATION_INSTRUCTIONS.md` - Database migration guide
- `DEPLOYMENT_SUCCESS.md` - Verification checklist
