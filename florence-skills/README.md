# Florence Tour Toolkit - Claude Code Skills

Custom Claude Code skills and agents tailored for the Florence With Locals tour guide management system.

## Overview

This toolkit provides specialized AI assistance for developing and maintaining the Florence With Locals application. It includes 6 skills for specific development tasks and 2 agents for automated workflows.

## Project Context

- **Application:** Tour guide management system for Florence, Italy
- **Frontend:** React 18 + Vite 5 + TailwindCSS 3.4
- **Backend:** PHP 8.2 (standalone, no framework) + MySQL 8.0
- **Integration:** Bokun booking platform API
- **Production:** https://withlocals.deetech.cc
- **Testing:** Vitest + React Testing Library (52 tests)
- **PDF Reports:** jsPDF + jsPDF-AutoTable (frontend-only)
- **Security:** Database-backed API rate limiting

## Recent Additions (Jan 2026)

| Feature | Description |
|---------|-------------|
| PDF Report Generation | Frontend PDF using jsPDF with Tuscan theme (#C75D3A) |
| API Rate Limiting | Database-backed limits (login: 5/min, read: 100/min) |
| Automated Testing | Vitest + React Testing Library, 52 tests |
| Payment System Fixes | Fixed VIEW table mismatch, added pending_tours API |

## Directory Structure

```
florence-skills/
├── .claude-plugin/
│   └── plugin.json          # Plugin metadata and configuration
├── skills/
│   ├── ui-designer/
│   │   └── SKILL.md         # Tuscan-inspired design system
│   ├── mobile-optimizer/
│   │   └── SKILL.md         # Mobile-first responsive patterns
│   ├── security-hardener/
│   │   └── SKILL.md         # Security best practices
│   ├── reliability-engineer/
│   │   └── SKILL.md         # Error handling and monitoring
│   ├── php-backend/
│   │   └── SKILL.md         # PHP API development patterns
│   └── react-patterns/
│       └── SKILL.md         # React component patterns
├── agents/
│   ├── code-reviewer/
│   │   └── AGENT.md         # Automated code review
│   └── deployment-checker/
│       └── AGENT.md         # Pre-deployment validation
└── README.md                # This file
```

## Skills

### 1. UI Designer (`skills/ui-designer/`)
Tuscan-inspired design system matching the Florence theme.

**Use for:**
- Creating new UI components
- Applying consistent styling
- Color palette guidance
- Button and card patterns
- Status badges and indicators

**Key colors:**
- Terracotta: `#C75D3A` (primary)
- Tuscan Gold: `#D4A84B` (accent)
- Olive Green: `#6B7C4C` (success)
- Stone: `#78716C` (neutral)

### 2. Mobile Optimizer (`skills/mobile-optimizer/`)
Mobile-first responsive design patterns.

**Use for:**
- Making components responsive
- Touch-friendly interactions
- Mobile navigation patterns
- Bottom sheets for mobile
- Swipe gestures

**Breakpoints:**
- `sm`: 640px
- `md`: 768px
- `lg`: 1024px
- `xl`: 1280px

### 3. Security Hardener (`skills/security-hardener/`)
Security best practices for the application.

**Use for:**
- Input validation
- SQL injection prevention
- XSS protection
- Authentication hardening
- Secure API design
- Environment variable handling

### 4. Reliability Engineer (`skills/reliability-engineer/`)
Error handling, monitoring, and deployment practices.

**Use for:**
- Error boundary implementation
- Sentry integration
- Health check endpoints
- Database retry logic
- Backup procedures
- Rollback strategies

### 5. PHP Backend (`skills/php-backend/`)
PHP API development patterns for this project.

**Use for:**
- Creating new API endpoints
- Database queries with prepared statements
- Authentication middleware
- Error response formatting
- Logging implementation
- Pagination patterns

### 6. React Patterns (`skills/react-patterns/`)
React component patterns specific to this codebase.

**Use for:**
- Page component structure
- AuthContext usage
- API service calls (mysqlDB.js)
- Modal patterns
- Form handling
- Date formatting with date-fns
- Sentry error tracking

## Agents

### Code Reviewer (`agents/code-reviewer/`)
Automated code review for pull requests and changes.

**Reviews for:**
- Security vulnerabilities
- Mobile compatibility
- Performance issues
- Pattern adherence

**Usage:**
```
Review the changes in [file/PR] for security and performance issues.
```

### Deployment Checker (`agents/deployment-checker/`)
Pre-deployment validation and deployment guidance.

**Checks:**
- Code quality
- Build verification
- Environment configuration
- Database status
- API health
- Security headers

**Usage:**
```
Run pre-deployment checks for production release.
```

## Quick Start

### Using a Skill
Reference the skill when asking Claude Code to perform related tasks:

```
Using the UI Designer skill, create a new card component for displaying tour information.
```

```
Using the PHP Backend skill, create an API endpoint for managing guide schedules.
```

### Using an Agent
Invoke agents for automated workflows:

```
Using the Code Reviewer agent, review the changes in the latest PR for security issues.
```

```
Using the Deployment Checker agent, validate the application is ready for production deployment.
```

## Integration with Development Workflow

### New Feature Development
1. Use **React Patterns** skill for component structure
2. Use **UI Designer** skill for styling
3. Use **Mobile Optimizer** skill for responsiveness
4. Use **PHP Backend** skill for API endpoints
5. Use **Security Hardener** skill for validation
6. Use **Code Reviewer** agent before committing

### Bug Fixes
1. Use **Reliability Engineer** skill for error handling
2. Use **Security Hardener** skill if security-related
3. Use **Code Reviewer** agent before merging

### Deployment
1. Use **Deployment Checker** agent for pre-deployment validation
2. Follow the deployment checklist
3. Verify post-deployment health

## Customization

### Adding New Skills
1. Create a new directory under `skills/`
2. Add a `SKILL.md` file with:
   - Overview
   - Patterns and examples
   - Code snippets
   - Best practices
3. Update `plugin.json` to include the new skill

### Modifying Existing Skills
- Edit the `SKILL.md` file in the respective skill directory
- Keep patterns consistent with existing codebase
- Update examples when project patterns change

## Maintenance

### Updating Skills
When the project evolves:
- Update color values in UI Designer if branding changes
- Add new patterns to React Patterns as they emerge
- Update security practices in Security Hardener
- Revise deployment procedures in Deployment Checker

### Version Control
The skills are versioned with the main project. Track changes:
```bash
git log --oneline florence-skills/
```

## Related Documentation

- [Technical Audit Report](../docs/TECHNICAL_AUDIT_REPORT.md)
- [Database Schema](../database_schema_updated.sql)
- [Deployment Plan](../DEPLOYMENT_PLAN.md)
- [Environment Setup](../ENVIRONMENT_SETUP.md)

## Support

For issues with the skills:
1. Check the relevant SKILL.md for correct usage
2. Verify patterns match current codebase
3. Update skills if project patterns have changed

---

**Florence With Locals** - Tour Guide Management System
Built with React, PHP, and TailwindCSS
