# Environment Setup

## PHP Configuration
- **Location**: `C:\php\php.ini`
- **Required Extensions**:
  - `extension=curl` (enabled)
  - `extension=openssl` (enabled)
  - `extension=mysqli` (enabled)

## Database Schema

### Development Environment
- **Host**: localhost
- **Database**: florence_guides

### Production Environment ✅
- **Host**: localhost (on production server)
- **Database**: u803853690_florence_guides
- **Production URL**: https://withlocals.deetech.cc
- **SSH Access**: ssh -p 65002 u803853690@82.25.82.111
- **Directory**: /home/u803853690/domains/deetech.cc/public_html/withlocals

### Tables with Record Counts (Production Active ✅):
  - `users` (1 record) - Authentication and roles
  - `guides` (3+ records) - Guide profiles with email and multi-language support
  - `tours` (132+ records) - Tour bookings with guide assignments, language detection, and payment tracking
    - **New Columns**:
      - `language VARCHAR(50)` - Automatically extracted from Bokun booking data
      - `notes TEXT` - Inline editable notes for tour/ticket details (used by Tours and Priority Tickets pages)
    - **Language Sources**: Viator (notes field), GetYourGuide (rate titles), product details
    - **Notes Column**: Full CRUD operations via inline editing with save/cancel controls
  - `tickets` (3 records) - Museum entrance ticket inventory (Uffizi/Accademia)
  - `bokun_config` (1 record) - Bokun API configuration (production credentials)
  - `sessions` (3+ records) - User session management
  - `payments` (2+ records) - Payment transaction records
  - `guide_payments` (3+ records) - Guide payment summaries and analytics

## Development Servers
- **Frontend**: `npm run dev` (currently port 5173)
- **Backend**: `php -S localhost:8080` (port 8080)

## Production Deployment ✅
- **Live URL**: https://withlocals.deetech.cc
- **API Base**: https://withlocals.deetech.cc/api
- **Server**: Hostinger shared hosting with SSL certificate
- **Deployment Method**: SSH upload via port 65002
- **Environment**: .env.production configured for production API URLs

## Application Access

### Production Access ✅
- **Live Application**: https://withlocals.deetech.cc
- **API Base URL**: https://withlocals.deetech.cc/api
- **Status**: Fully operational with all features working

### Development Access
- **Frontend URL**: http://localhost:5173 (**FIXED PORT - DO NOT USE OTHER PORTS**)
- **API Base URL**: http://localhost:8080/api/

## Authentication Credentials
- **Admin**: dhanu / Kandy@123
- **Viewer**: Sudeshshiwanka25@gmail.com / Sudesh@93
