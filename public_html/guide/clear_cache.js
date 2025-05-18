// Script to clear local storage cache
(function() {
  // Clear all localStorage keys related to tours
  localStorage.removeItem('tours');
  localStorage.removeItem('tours_v1');
  
  // Also look for any other potential tour-related keys
  Object.keys(localStorage).forEach(key => {
    if (key.includes('tour') || key.includes('Tour')) {
      localStorage.removeItem(key);
    }
  });
  
  console.log('Cache cleared successfully!');
  alert('Tour cache has been cleared. You should now see the most up-to-date tour data.');
})(); 