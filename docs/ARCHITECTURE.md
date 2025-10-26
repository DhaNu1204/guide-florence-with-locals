# System Architecture

## Frontend Architecture

- **Component-Based**: Modular React components with clear separation of concerns
- **Context Management**: Authentication and page title contexts
- **Service Layer**: Centralized API communication with caching and error handling
- **Responsive Design**: Mobile-first approach with TailwindCSS utilities

## Backend Architecture

- **RESTful APIs**: Standard HTTP methods with proper status codes
- **Database Layer**: MySQLi with prepared statements for security
- **CORS Configuration**: Proper cross-origin resource sharing setup
- **Error Handling**: Comprehensive error responses with logging

## Data Flow

1. **User Interaction**: Frontend React components
2. **Service Layer**: mysqlDB.js handles API communication
3. **Backend APIs**: PHP endpoints process requests
4. **Database**: MySQL stores and retrieves data
5. **Response**: JSON data returned to frontend
6. **UI Update**: React components re-render with new data

## Technology Stack

### Frontend
- **Framework**: React 18
- **Build Tool**: Vite
- **Styling**: TailwindCSS
- **Icons**: React Icons (Fi)
- **State Management**: React Context API
- **Routing**: React Router

### Backend
- **Language**: PHP 8.2
- **Database**: MySQL
- **Server**: PHP Built-in Server (Development) / Apache (Production)
- **Authentication**: Session-based with tokens

### External Integrations
- **Bokun API**: HMAC-SHA1 authentication for booking synchronization
- **Booking Channels**: Viator.com, GetYourGuide

## Component Structure

### Layout Components
- **ModernLayout.jsx**: Main layout with sidebar navigation
- **Responsive Design**: Mobile collapsible menu, desktop fixed sidebar

### UI Components
- **Card.jsx**: Modern card component
- **Button.jsx**: Styled button component
- **Input.jsx**: Form input components
- **BookingDetailsModal.jsx**: Comprehensive booking details modal

### Page Components
- **Tours.jsx**: Tour management
- **Guides.jsx**: Guide management
- **Payments.jsx**: Payment tracking
- **Tickets.jsx**: Museum ticket inventory
- **PriorityTickets.jsx**: Museum ticket bookings
- **EditTour.jsx**: Tour editing
- **BokunIntegration.jsx**: Bokun sync interface
- **Login.jsx**: Authentication

### Services
- **mysqlDB.js**: Database service layer with caching

## Database Schema

See [ENVIRONMENT_SETUP.md](./ENVIRONMENT_SETUP.md) for detailed database schema information.
