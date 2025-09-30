// Local storage-based database service
const GUIDES_KEY = 'florence_guides';
const TOURS_KEY = 'florence_tours';

// Initialize with some default data if empty
const initializeLocalStorage = () => {
  if (!localStorage.getItem(GUIDES_KEY)) {
    const defaultGuides = [
      { id: 1, name: 'Marco Rossi', phone: '+39 055 123 4567' },
      { id: 2, name: 'Lucia Bianchi', phone: '+39 055 765 4321' }
    ];
    localStorage.setItem(GUIDES_KEY, JSON.stringify(defaultGuides));
  }

  if (!localStorage.getItem(TOURS_KEY)) {
    const defaultTours = [
      {
        id: 1,
        title: 'Historical Center Walking Tour',
        duration: '3 hours',
        description: 'Explore the historical center of Florence, including the Duomo, Palazzo Vecchio, and Ponte Vecchio.',
        date: '2023-06-15',
        time: '09:00',
        guide_id: 1,
        guide_name: 'Marco Rossi'
      },
      {
        id: 2,
        title: 'Uffizi Gallery Tour',
        duration: '2 hours',
        description: 'Discover the masterpieces of the Renaissance at the world-famous Uffizi Gallery.',
        date: '2023-06-16',
        time: '10:00',
        guide_id: 2,
        guide_name: 'Lucia Bianchi'
      }
    ];
    localStorage.setItem(TOURS_KEY, JSON.stringify(defaultTours));
  }
};

// Initialize the local storage database
initializeLocalStorage();

// Guide functions
export const getGuides = () => {
  return new Promise((resolve) => {
    const guides = JSON.parse(localStorage.getItem(GUIDES_KEY) || '[]');
    setTimeout(() => resolve(guides), 200); // Simulate network delay
  });
};

export const addGuide = (guide) => {
  return new Promise((resolve) => {
    const guides = JSON.parse(localStorage.getItem(GUIDES_KEY) || '[]');
    
    // Check if we are editing an existing guide (if it has an id)
    if (guide.id) {
      const index = guides.findIndex(g => g.id === guide.id);
      if (index !== -1) {
        guides[index] = { ...guides[index], ...guide };
        localStorage.setItem(GUIDES_KEY, JSON.stringify(guides));
        setTimeout(() => resolve(guides[index]), 200); // Simulate network delay
        return;
      }
    }
    
    // Otherwise create a new guide
    const newId = guides.length > 0 ? Math.max(...guides.map(g => g.id)) + 1 : 1;
    
    const newGuide = {
      id: newId,
      name: guide.name,
      phone: guide.phone
    };
    
    guides.push(newGuide);
    localStorage.setItem(GUIDES_KEY, JSON.stringify(guides));
    
    setTimeout(() => resolve(newGuide), 200); // Simulate network delay
  });
};

export const deleteGuide = (guideId) => {
  return new Promise((resolve, reject) => {
    try {
      const guides = JSON.parse(localStorage.getItem(GUIDES_KEY) || '[]');
      const tours = JSON.parse(localStorage.getItem(TOURS_KEY) || '[]');
      
      // Check if guide exists
      const guideIndex = guides.findIndex(g => g.id === guideId);
      if (guideIndex === -1) {
        reject(new Error('Guide not found'));
        return;
      }
      
      // Check if guide is used in any tours
      const hasAssociatedTours = tours.some(tour => tour.guide_id === guideId);
      if (hasAssociatedTours) {
        reject(new Error('Cannot delete guide with associated tours'));
        return;
      }
      
      // Remove the guide
      const updatedGuides = guides.filter(g => g.id !== guideId);
      localStorage.setItem(GUIDES_KEY, JSON.stringify(updatedGuides));
      
      // Simulate network delay
      setTimeout(() => resolve(true), 200);
    } catch (error) {
      reject(error);
    }
  });
};

// Tour functions
export const getTours = () => {
  return new Promise((resolve) => {
    const tours = JSON.parse(localStorage.getItem(TOURS_KEY) || '[]');
    setTimeout(() => resolve(tours), 200); // Simulate network delay
  });
};

export const addTour = (tour) => {
  return new Promise((resolve) => {
    const tours = JSON.parse(localStorage.getItem(TOURS_KEY) || '[]');
    const guides = JSON.parse(localStorage.getItem(GUIDES_KEY) || '[]');
    
    const selectedGuide = guides.find(g => g.id === parseInt(tour.guideId));
    const guideName = selectedGuide ? selectedGuide.name : 'Unknown Guide';
    
    const newId = tours.length > 0 ? Math.max(...tours.map(t => t.id)) + 1 : 1;
    
    const newTour = {
      id: newId,
      title: tour.title,
      duration: tour.duration || '2 hours',
      description: tour.description || 'No description provided.',
      date: tour.date,
      time: tour.time,
      guide_id: parseInt(tour.guideId),
      guide_name: guideName
    };
    
    tours.push(newTour);
    localStorage.setItem(TOURS_KEY, JSON.stringify(tours));
    
    setTimeout(() => resolve(newTour), 200); // Simulate network delay
  });
};

export const deleteTour = (tourId) => {
  return new Promise((resolve, reject) => {
    try {
      const tours = JSON.parse(localStorage.getItem(TOURS_KEY) || '[]');
      
      // Check if tour exists
      const tourIndex = tours.findIndex(t => t.id === tourId);
      if (tourIndex === -1) {
        reject(new Error('Tour not found'));
        return;
      }
      
      // Remove the tour
      const updatedTours = tours.filter(t => t.id !== tourId);
      localStorage.setItem(TOURS_KEY, JSON.stringify(updatedTours));
      
      // Simulate network delay
      setTimeout(() => resolve(true), 200);
    } catch (error) {
      reject(error);
    }
  });
};

// This file will be replaced by MySQL implementation
// Keeping for backward compatibility during transition 