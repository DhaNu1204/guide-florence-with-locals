const fs = require('fs');

// Read the JSON file
const data = fs.readFileSync('../tours_temp.json', 'utf8');
const bookings = JSON.parse(data);

// Get the most recent bookings (last 7 days from latest date)
const latestDate = new Date('2025-10-21');
const sevenDaysAgo = new Date(latestDate);
sevenDaysAgo.setDate(sevenDaysAgo.getDate() - 7);

const recentBookings = bookings.filter(b => {
    const bookingDate = new Date(b.date);
    return bookingDate >= sevenDaysAgo && bookingDate <= latestDate;
}).sort((a, b) => {
    if (a.date === b.date) {
        return a.time.localeCompare(b.time);
    }
    return a.date.localeCompare(b.date);
});

console.log('='.repeat(80));
console.log('📊 RECENT BOOKINGS (October 14-21, 2025)');
console.log('='.repeat(80));
console.log(`\n⚠️  NOTE: This is from local data. For LIVE data for today (Oct 24), please access:`);
console.log(`   🌐 https://withlocals.deetech.cc\n`);
console.log('='.repeat(80));
console.log();

// Group by date
const byDate = {};
recentBookings.forEach(b => {
    if (!byDate[b.date]) byDate[b.date] = [];
    byDate[b.date].push(b);
});

Object.keys(byDate).sort().forEach(date => {
    const isToday = date === '2025-10-24';
    const marker = isToday ? '👉 TODAY' : '';

    console.log(`\n📅 ${date} ${marker}`);
    console.log('─'.repeat(80));

    byDate[date].forEach((booking, idx) => {
        console.log(`\n   BOOKING #${idx + 1}`);
        console.log(`   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━`);
        console.log(`   🆔 ID: ${booking.id}`);
        console.log(`   👤 Customer: ${booking.customer_name}`);
        console.log(`   🕐 Time: ${booking.time}`);
        console.log(`   👥 People: ${booking.num_people}`);
        console.log(`   🎯 Type: ${booking.tour_type || 'N/A'}`);
        console.log(`   📍 Status: ${booking.status || 'confirmed'}`);
        console.log();
        console.log(`   👨‍🏫 Guide: ${booking.guide_name || 'Not assigned'}`);
        console.log(`   📧 Email: ${booking.guide_email || 'N/A'}`);
        console.log();
        console.log(`   💰 Amount: €${booking.amount || '0'}`);
        console.log(`   💳 Payment: ${booking.is_paid ? '✅ PAID' : '❌ NOT PAID'}`);
        console.log(`   📺 Channel: ${booking.booking_channel || 'N/A'}`);
        console.log();
        console.log(`   📝 Description: ${booking.description || 'N/A'}`);
        console.log(`   💬 Notes: ${booking.notes || 'N/A'}`);
        console.log(`   🔖 Bokun ID: ${booking.bokun_confirmation_code || 'N/A'}`);
    });
});

console.log('\n' + '='.repeat(80));
console.log(`📊 SUMMARY: ${recentBookings.length} bookings in the last 7 days`);
console.log('='.repeat(80));

// Stats
const totalPeople = recentBookings.reduce((sum, b) => sum + (parseInt(b.num_people) || 0), 0);
const totalAmount = recentBookings.reduce((sum, b) => sum + (parseFloat(b.amount) || 0), 0);
const paid = recentBookings.filter(b => b.is_paid).length;

console.log(`\n💵 QUICK STATS:`);
console.log(`   Total Bookings: ${recentBookings.length}`);
console.log(`   Total People: ${totalPeople}`);
console.log(`   Total Revenue: €${totalAmount.toFixed(2)}`);
console.log(`   Paid: ${paid} | Unpaid: ${recentBookings.length - paid}`);
console.log(`   Average per booking: €${(totalAmount / recentBookings.length).toFixed(2)}`);
console.log();
