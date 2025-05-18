# Florence with Locals - Tour Management System

A comprehensive web application for managing tour guides and their scheduled tours in Florence, Italy. Designed for tour operators and administrators to efficiently schedule, track, manage, and monitor the payment status of guided tours across the beautiful city of Florence.

## 🌟 Features

### Guide Management
- Add new guides to the system with contact information
- View all guides in a clean, organized interface
- Delete guides when they're no longer active (only if they have no associated tours)

### Tour Management
- Schedule new tours with specific guides, dates, times, and durations
- View tours organized by date with intuitive color-coding
- Filter tours by guide name or specific date
- Mark tours as paid or unpaid to track payment status
- Cancel tours without deleting them (keeps the record while marking as unavailable)
- Delete tours when needed

### Smart Tour Status Visualization
- **Color-coded tour cards** for easy status identification:
  - 🟣 **Purple** - Future tours (beyond 2 days)
  - 🔵 **Blue** - Tours in 2 days
  - 🟢 **Green** - Tours tomorrow
  - 🟡 **Yellow** - Tours today (not yet started)
  - ⚪ **Gray** - Completed tours
  - 🔴 **Red** - Cancelled tours

### Payment Tracking
- Toggle payment status for each tour directly from the tour card
- Visual indicators for paid/unpaid status
- Special notification for completed unpaid tours
- Summary view of all unpaid completed tours for financial tracking

### User Experience
- Fully responsive design for all screen sizes
- Mobile-friendly interface with optimized layouts
- Interactive form with date picker for scheduling tours
- Real-time status updates without page refreshes
- Pagination for handling large numbers of tours efficiently
- Customizable tours-per-page display settings

## 🛠️ Technology Stack

- **Frontend**: 
  - React 18
  - Tailwind CSS for styling
  - date-fns for date handling
  - React Router for navigation

- **Backend**: 
  - PHP API endpoints
  - MySQL database
  - RESTful API architecture

- **Data Management**:
  - Server-first approach with localStorage fallback for offline capability
  - Intelligent caching with timestamp-based expiration
  - Cache versioning to handle structural changes

## 🧠 System Architecture

### Frontend Logic
1. **API Communication Layer** (`mysqlDB.js`)
   - Handles all communication with backend API
   - Implements cache management with versioning and expiration
   - Provides fallback to localStorage when offline
   - Forces data refreshes after updates for consistency

2. **Component Structure**
   - `CardView`: Container component managing pagination and filtering
   - `TourCards`: Core component for displaying tour information
   - `Layout`: Page structure with consistent header/footer
   - Modal components for special views (e.g., UnpaidToursModal)

3. **Data Flow**
   - Server data is the source of truth
   - Updates trigger immediate server persistence
   - Cross-device consistency maintained through cache invalidation
   - Intelligent merging of server data with local changes

### Backend Structure
1. **API Routing** (`index.php`)
   - Routes requests to appropriate handlers
   - Supports specialized endpoints for tour cancellation and payment updates
   - Implements CORS and preflight handling
   - Provides detailed error responses

2. **Database Operations**
   - Direct SQL operations with prepared statements for security
   - Dynamic schema adaptation (adds columns if missing)
   - Comprehensive error handling and logging

## 🚀 Recent Improvements

### New Features
- **Tour Cancellation System**: Ability to mark tours as cancelled without deletion
- **Enhanced Payment Tracking**: Improved UI for paid/unpaid status with direct toggles
- **Cache Management Tools**: Added utilities to clear cache for troubleshooting

### UI/UX Enhancements
- Added a guide filter dropdown to allow filtering tours by specific guides
- Added date filtering for finding tours on specific dates
- Improved mobile responsiveness for better small-screen experience
- Enhanced visual distinction between tour statuses
- Added "Cancel Tour" button for future-dated tours

### Technical Improvements
- **Advanced Caching System**: Implemented versioned caching with timestamps
- **Database Synchronization**: Better handling of online/offline states
- **Error Resilience**: Improved error catching and fallback mechanisms
- **Cross-Device Consistency**: Solved data inconsistency issues between devices

### Bug Fixes
- Fixed date comparison logic to correctly categorize future tours
- Resolved inconsistencies in newly added tour display
- Ensured proper color-coding for all tour statuses
- Fixed localStorage and server data synchronization issues

## 📋 Usage Guide

### Managing Guides
1. Click "Add Guide" to create a new tour guide
2. Fill in the required information and save
3. New guides become immediately available for tour assignment

### Managing Tours
1. Click "Add Tour" to schedule a new tour
2. Select the tour name, guide, date, time, and duration
3. Save the tour to add it to the schedule
4. View the tour in the color-coded list organized by date

### Payment Management
1. Click the "Paid"/"Unpaid" button on any tour card to toggle its payment status
2. Use the "unpaid tours" notification to quickly access all completed unpaid tours
3. Track payment status visually through the user interface

### Tour Cancellation
1. For future-dated tours, use the "Cancel Tour" button to mark a tour as cancelled
2. Cancelled tours remain in the system but are visibly marked and color-coded in red
3. This allows maintaining records of cancelled tours without deleting the data

### Filtering Tours
1. Use the "Filter by Guide" dropdown to view tours for a specific guide
2. Use the date picker to filter tours occurring on a specific date
3. Select "All Guides" and clear the date filter to view the complete tour schedule
4. Use pagination controls to navigate through large sets of tours

### Troubleshooting
If you experience data inconsistency across devices:
1. Visit `/guide/clear_cache.html` on each device
2. Click the "Clear Tour Cache" button
3. Return to the main application to see freshly loaded data

## 🔧 Setup Instructions

### Requirements
- Node.js (v16+)
- MySQL database
- PHP server for API (e.g., Apache with PHP 7.4+)

### Installation
1. Clone the repository
2. Install dependencies:
   ```
   npm install
   ```
3. Configure your database connection in `public_html/guide/api/config.php`
4. Start the development server:
   ```
   npm run dev
   ```
5. Build for production:
   ```
   npm run build
   ```
6. Deploy:
   - Upload the contents of the `dist` folder to your web hosting (main app)
   - Upload the contents of `public_html/guide/api` to your API directory
   - Upload `public_html/guide/clear_cache.html` and `public_html/guide/clear_cache.js` to your hosting

## 📊 Database Schema

### Guides Table
- id (INT, Primary Key)
- name (VARCHAR)
- phone (VARCHAR)
- created_at (TIMESTAMP)

### Tours Table
- id (INT, Primary Key)
- title (VARCHAR)
- duration (VARCHAR)
- description (TEXT)
- date (DATE)
- time (TIME)
- guide_id (INT, Foreign Key)
- paid (TINYINT) - Indicates payment status (0=unpaid, 1=paid)
- cancelled (TINYINT) - Indicates cancellation status (0=active, 1=cancelled)
- created_at (TIMESTAMP)
- updated_at (TIMESTAMP)

## 📝 License

This project is proprietary and confidential. Unauthorized copying, distribution or use is prohibited.

---

© 2025 Florence with Locals. All rights reserved. 