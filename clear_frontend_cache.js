// Simple script to clear frontend cache
// Open browser console on http://localhost:5173 and run this:

console.log('Clearing frontend tour cache...');

// Clear the tours cache
localStorage.removeItem('tours_v1');
localStorage.removeItem('tours');

console.log('Frontend cache cleared! Please refresh the page to see updated data.');

// Auto refresh the page
setTimeout(() => {
    location.reload();
}, 1000);