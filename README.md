# Florence with Locals - Tour Guide Management System

A comprehensive web application for managing tour guides, tours, and payments for Florence with Locals tour company.

## Features

### Core Functionality
- **Tour Management**: Create, view, edit, and manage tours with detailed information
- **Guide Management**: Manage tour guides and their assignments
- **Payment Tracking**: Track payments, generate reports, and manage financial records
- **Ticket Management**: Handle Uffizi and other museum ticket bookings
- **Bokun Integration**: Sync with Bokun API for tour bookings and availability

### Key Components
- **Dashboard**: Overview of tours, guides, and payment status
- **Authentication**: Secure login system for administrators and guides
- **Real-time Sync**: Automatic synchronization with Bokun platform
- **Payment Reports**: Monthly and custom payment reports for guides
- **Tour Cards**: Visual representation of tours with status indicators

## Technology Stack

### Frontend
- React.js with Vite
- Tailwind CSS for styling
- React Router for navigation
- Context API for state management

### Backend
- PHP for API endpoints
- MySQL database
- Node.js server (optional)
- Bokun API integration

## Project Structure

```
florence-with-locals-guide-assign-list/
├── guide-florence-with-locals/      # Main application directory
│   ├── src/                         # React source files
│   │   ├── components/              # React components
│   │   ├── pages/                   # Page components
│   │   ├── contexts/                # Context providers
│   │   └── services/                # API services
│   ├── public_html/                 # Public files and PHP APIs
│   │   ├── api/                     # PHP API endpoints
│   │   └── assets/                  # Built assets
│   ├── backend/                     # Node.js backend (optional)
│   └── dist/                        # Production build
└── public_html/                     # Deployment files
```

## Installation

### Prerequisites
- Node.js (v14 or higher)
- PHP (v7.4 or higher)
- MySQL database
- npm or yarn package manager

### Setup Instructions

1. **Clone the repository**
   ```bash
   git clone https://github.com/DhaNu1204/guide-florence-with-locals.git
   cd guide-florence-with-locals
   ```

2. **Install dependencies**
   ```bash
   cd guide-florence-with-locals
   npm install
   ```

3. **Configure environment variables**
   - Copy `.env.example` to `.env`
   - Update database credentials and API keys
   ```bash
   cp .env.example .env
   ```

4. **Set up the database**
   - Import the SQL schema from `database_schema.sql`
   - Update database connection in `public_html/api/config.php`

5. **Configure Bokun API** (if using)
   - Add your Bokun API credentials to the configuration
   - See `BOKUN_API_CONFIGURATION.md` for detailed setup

## Development

### Start development server
```bash
npm run dev
```
This will start both the frontend and backend servers concurrently.

### Build for production
```bash
npm run build
```

### Run PHP server locally
```bash
php -S localhost:8080 -t public_html
```

## API Endpoints

### Authentication
- `POST /api/auth.php` - User login
- `POST /api/auth.php?action=logout` - User logout

### Tours
- `GET /api/tours.php` - Get all tours
- `POST /api/tours.php` - Create new tour
- `PUT /api/tours.php` - Update tour
- `DELETE /api/tours.php` - Delete tour

### Guides
- `GET /api/guides.php` - Get all guides
- `POST /api/guides.php` - Add new guide

### Payments
- `GET /api/payments.php` - Get payment records
- `POST /api/payments.php` - Create payment record
- `GET /api/payment-reports.php` - Generate payment reports

### Bokun Integration
- `GET /api/bokun_sync.php` - Sync with Bokun
- `POST /api/bokun_webhook.php` - Bokun webhook endpoint

## Database Schema

The application uses MySQL with the following main tables:
- `tours` - Tour information and schedules
- `guides` - Guide profiles and credentials
- `payments` - Payment records and transactions
- `tickets` - Museum ticket bookings
- `bokun_products` - Bokun product synchronization

## Deployment

### Hostinger Deployment
See `HOSTINGER_DEPLOYMENT_GUIDE.md` for detailed deployment instructions.

### General Deployment
1. Build the production files: `npm run build`
2. Upload files to your web server
3. Configure database connection
4. Set up appropriate file permissions
5. Configure web server (Apache/Nginx) for PHP and React routing

## Configuration Files

- `.env` - Environment variables
- `vite.config.js` - Vite configuration
- `tailwind.config.js` - Tailwind CSS configuration
- `package.json` - Node dependencies and scripts

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## Support

For support and questions, please open an issue in the GitHub repository.

## License

This project is proprietary software for Florence with Locals.

## Authors

- Florence with Locals Development Team

## Acknowledgments

- Bokun API for tour management integration
- React and Vite communities
- All contributors to this project