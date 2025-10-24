const express = require('express');
const cors = require('cors');
const fs = require('fs');
const path = require('path');

const app = express();
const PORT = process.env.PORT || 3001;

// Middleware
app.use(cors());
app.use(express.json());

// Load data from JSON file
let tours = [];
let guides = [];

try {
    const toursData = fs.readFileSync(path.join(__dirname, '../tours_temp.json'), 'utf8');
    tours = JSON.parse(toursData);
    console.log(`✅ Loaded ${tours.length} tours from tours_temp.json`);
} catch (error) {
    console.error('❌ Error loading tours data:', error.message);
}

// Helper function to get today's date
function getTodayDate() {
    const today = new Date();
    return today.toISOString().split('T')[0];
}

// Helper function to filter tours by date
function getToursByDate(date) {
    return tours.filter(tour => tour.date === date);
}

// Helper function to get date range
function getToursByDateRange(startDate, endDate) {
    return tours.filter(tour => {
        return tour.date >= startDate && tour.date <= endDate;
    });
}

// ============================================================================
// API ENDPOINTS
// ============================================================================

// Root endpoint
app.get('/', (req, res) => {
    res.json({
        message: 'Florence with Locals - Local Backend Server',
        status: 'running',
        endpoints: {
            tours: '/api/tours',
            todayTours: '/api/tours/today',
            toursByDate: '/api/tours/date/:date',
            tourById: '/api/tours/:id',
            stats: '/api/stats',
            health: '/api/health'
        },
        dataSource: 'tours_temp.json',
        totalTours: tours.length
    });
});

// Health check
app.get('/api/health', (req, res) => {
    res.json({
        status: 'healthy',
        timestamp: new Date().toISOString(),
        toursLoaded: tours.length
    });
});

// Get all tours
app.get('/api/tours', (req, res) => {
    const { limit, status, date } = req.query;

    let filteredTours = [...tours];

    // Filter by date if provided
    if (date) {
        filteredTours = filteredTours.filter(t => t.date === date);
    }

    // Filter by status if provided
    if (status) {
        filteredTours = filteredTours.filter(t => t.status === status);
    }

    // Sort by date and time
    filteredTours.sort((a, b) => {
        if (a.date === b.date) {
            return (a.time || '').localeCompare(b.time || '');
        }
        return a.date.localeCompare(b.date);
    });

    // Limit results if specified
    if (limit) {
        filteredTours = filteredTours.slice(0, parseInt(limit));
    }

    res.json({
        success: true,
        count: filteredTours.length,
        data: filteredTours
    });
});

// Get today's tours
app.get('/api/tours/today', (req, res) => {
    const today = getTodayDate();
    const todayTours = getToursByDate(today);

    res.json({
        success: true,
        date: today,
        count: todayTours.length,
        data: todayTours
    });
});

// Get tours by specific date
app.get('/api/tours/date/:date', (req, res) => {
    const { date } = req.params;
    const toursByDate = getToursByDate(date);

    res.json({
        success: true,
        date: date,
        count: toursByDate.length,
        data: toursByDate
    });
});

// Get tour by ID
app.get('/api/tours/:id', (req, res) => {
    const { id } = req.params;
    const tour = tours.find(t => t.id == id);

    if (!tour) {
        return res.status(404).json({
            success: false,
            error: 'Tour not found'
        });
    }

    res.json({
        success: true,
        data: tour
    });
});

// Get statistics
app.get('/api/stats', (req, res) => {
    const today = getTodayDate();
    const todayTours = getToursByDate(today);

    // Get upcoming tours (future dates)
    const upcomingTours = tours.filter(t => t.date > today);

    // Get past tours
    const pastTours = tours.filter(t => t.date < today);

    // Calculate totals
    const totalRevenue = tours.reduce((sum, t) => sum + (parseFloat(t.amount) || 0), 0);
    const totalPeople = tours.reduce((sum, t) => sum + (parseInt(t.num_people) || 0), 0);
    const paidTours = tours.filter(t => t.is_paid).length;

    // Group by booking channel
    const byChannel = {};
    tours.forEach(t => {
        const channel = t.booking_channel || 'Unknown';
        byChannel[channel] = (byChannel[channel] || 0) + 1;
    });

    // Group by status
    const byStatus = {};
    tours.forEach(t => {
        const status = t.status || 'confirmed';
        byStatus[status] = (byStatus[status] || 0) + 1;
    });

    // Get date range
    const dates = tours.map(t => t.date).sort();
    const dateRange = {
        earliest: dates[0],
        latest: dates[dates.length - 1]
    };

    res.json({
        success: true,
        stats: {
            total: {
                tours: tours.length,
                revenue: totalRevenue.toFixed(2),
                people: totalPeople,
                paid: paidTours,
                unpaid: tours.length - paidTours
            },
            today: {
                tours: todayTours.length,
                date: today
            },
            upcoming: {
                tours: upcomingTours.length
            },
            past: {
                tours: pastTours.length
            },
            byChannel: byChannel,
            byStatus: byStatus,
            dateRange: dateRange
        }
    });
});

// Get upcoming tours (next 7 days)
app.get('/api/tours/upcoming', (req, res) => {
    const today = new Date();
    const nextWeek = new Date(today);
    nextWeek.setDate(today.getDate() + 7);

    const todayStr = today.toISOString().split('T')[0];
    const nextWeekStr = nextWeek.toISOString().split('T')[0];

    const upcomingTours = getToursByDateRange(todayStr, nextWeekStr);

    res.json({
        success: true,
        dateRange: {
            from: todayStr,
            to: nextWeekStr
        },
        count: upcomingTours.length,
        data: upcomingTours
    });
});

// ============================================================================
// START SERVER
// ============================================================================

app.listen(PORT, () => {
    console.log('\n' + '='.repeat(80));
    console.log('🚀 Florence with Locals - Local Backend Server');
    console.log('='.repeat(80));
    console.log(`\n✅ Server running on: http://localhost:${PORT}`);
    console.log(`📊 Loaded ${tours.length} tours from JSON file`);
    console.log('\n📍 Available endpoints:');
    console.log(`   • http://localhost:${PORT}/                    - Server info`);
    console.log(`   • http://localhost:${PORT}/api/health          - Health check`);
    console.log(`   • http://localhost:${PORT}/api/tours           - All tours`);
    console.log(`   • http://localhost:${PORT}/api/tours/today     - Today's tours`);
    console.log(`   • http://localhost:${PORT}/api/tours/date/YYYY-MM-DD - Tours by date`);
    console.log(`   • http://localhost:${PORT}/api/tours/:id       - Tour by ID`);
    console.log(`   • http://localhost:${PORT}/api/tours/upcoming  - Next 7 days`);
    console.log(`   • http://localhost:${PORT}/api/stats           - Statistics`);
    console.log('\n💡 Example: http://localhost:' + PORT + '/api/tours/date/2025-10-21');
    console.log('\n' + '='.repeat(80));
    console.log('Press Ctrl+C to stop the server\n');
});

// Error handling
process.on('uncaughtException', (error) => {
    console.error('❌ Uncaught Exception:', error.message);
});

process.on('unhandledRejection', (reason, promise) => {
    console.error('❌ Unhandled Rejection at:', promise, 'reason:', reason);
});
