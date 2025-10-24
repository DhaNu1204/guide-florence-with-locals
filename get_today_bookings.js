const mysql = require('mysql2/promise');

async function getTodayBookings() {
    // Try local database first
    let config = {
        host: 'localhost',
        user: 'root',
        password: 'RL94_#BbiLhuy789xF',
        database: 'florence_guides'
    };

    let connection;
    let dbType = 'local';

    try {
        connection = await mysql.createConnection(config);
        console.log('✓ Connected to LOCAL database\n');
    } catch (error) {
        console.log('✗ Local database connection failed, trying production...');
        // Try production database
        config = {
            host: 'localhost',
            user: 'u803853690_withlocals',
            password: 'YY!C~W2frt*5',
            database: 'u803853690_withlocals'
        };

        try {
            connection = await mysql.createConnection(config);
            dbType = 'production';
            console.log('✓ Connected to PRODUCTION database\n');
        } catch (prodError) {
            console.error('✗ Production database connection also failed:', prodError.message);
            process.exit(1);
        }
    }

    // Get today's date
    const today = '2025-10-24';

    console.log('='.repeat(80));
    console.log(`BOOKINGS FOR TODAY: ${today} (${dbType.toUpperCase()} DATABASE)`);
    console.log('='.repeat(80));
    console.log();

    try {
        // Query for today's bookings
        const [bookings] = await connection.execute(`
            SELECT
                t.*,
                g.name as guide_name,
                g.email as guide_email,
                g.languages as guide_languages
            FROM tours t
            LEFT JOIN guides g ON t.guide_id = g.id
            WHERE DATE(t.date) = ?
            ORDER BY t.time ASC
        `, [today]);

        if (bookings.length > 0) {
            bookings.forEach((booking, index) => {
                console.log(`BOOKING #${index + 1}`);
                console.log('━'.repeat(80));
                console.log(`📅 BASIC INFO`);
                console.log(`   Tour ID: ${booking.id}`);
                console.log(`   Customer: ${booking.customer_name}`);
                console.log(`   Date: ${booking.date}`);
                console.log(`   Time: ${booking.time}`);
                console.log(`   People: ${booking.num_people}`);
                console.log(`   Tour Type: ${booking.tour_type || 'N/A'}`);
                console.log(`   Status: ${booking.status || 'confirmed'}`);

                console.log();
                console.log(`👤 GUIDE INFO`);
                console.log(`   Guide: ${booking.guide_name || 'Not assigned'}`);
                console.log(`   Email: ${booking.guide_email || 'N/A'}`);
                console.log(`   Languages: ${booking.guide_languages || 'N/A'}`);

                console.log();
                console.log(`💰 PAYMENT INFO`);
                console.log(`   Amount: €${booking.amount || '0'}`);
                console.log(`   Payment Status: ${booking.is_paid ? '✓ PAID' : '✗ NOT PAID'}`);
                console.log(`   Channel: ${booking.booking_channel || 'N/A'}`);

                console.log();
                console.log(`📝 ADDITIONAL DETAILS`);
                console.log(`   Description: ${booking.description || 'N/A'}`);
                console.log(`   Notes: ${booking.notes || 'N/A'}`);
                console.log(`   Bokun ID: ${booking.bokun_confirmation_code || 'N/A'}`);
                console.log(`   Bokun Ref: ${booking.bokun_reference_number || 'N/A'}`);

                console.log('━'.repeat(80));
                console.log();
            });

            console.log('='.repeat(80));
            console.log(`📊 SUMMARY: ${bookings.length} booking(s) found for today`);
            console.log('='.repeat(80));

            // Quick stats
            const paid = bookings.filter(b => b.is_paid).length;
            const unpaid = bookings.length - paid;
            const totalAmount = bookings.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
            const totalPeople = bookings.reduce((sum, b) => sum + (parseInt(b.num_people) || 0), 0);

            console.log();
            console.log('💵 QUICK STATS:');
            console.log(`   Total Bookings: ${bookings.length}`);
            console.log(`   Total People: ${totalPeople}`);
            console.log(`   Total Amount: €${totalAmount.toFixed(2)}`);
            console.log(`   Paid: ${paid} | Unpaid: ${unpaid}`);

        } else {
            console.log('❌ No bookings found for today.\n');

            // Check nearby dates
            console.log('🔍 Checking for bookings in nearby dates...\n');
            const [nearby] = await connection.execute(`
                SELECT
                    DATE(date) as booking_date,
                    COUNT(*) as count,
                    SUM(num_people) as total_people
                FROM tours
                WHERE DATE(date) BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
                GROUP BY DATE(date)
                ORDER BY booking_date
            `, [today, today]);

            if (nearby.length > 0) {
                console.log('📅 Bookings within ±7 days:');
                nearby.forEach(day => {
                    const marker = day.booking_date === today ? '👉' : '  ';
                    console.log(`${marker} ${day.booking_date}: ${day.count} booking(s), ${day.total_people} people`);
                });
            } else {
                console.log('No bookings found in the next 14 days.');
            }
        }

    } catch (error) {
        console.error('Error querying database:', error.message);
    } finally {
        await connection.end();
    }
}

// Run the function
getTodayBookings().catch(console.error);
