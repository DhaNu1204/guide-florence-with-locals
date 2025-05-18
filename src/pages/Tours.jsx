import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getGuides, getTours, addTour, deleteTour, updateTourPaidStatus, updateTourCancelStatus } from '../services/mysqlDB';
import DatePicker from "react-datepicker";
import { format } from "date-fns";
import { it } from "date-fns/locale";
import "react-datepicker/dist/react-datepicker.css";
import { usePageTitle } from '../contexts/PageTitleContext';
import TourCards from '../components/TourCards'; // Import our TourCards component
import UnpaidToursModal from '../components/UnpaidToursModal'; // Import the UnpaidToursModal component
import CardView from '../components/CardView'; // Import the new CardView component

// Predefined tour names and durations
const TOUR_NAMES = [
  'David and Accademia Gallery VIP Tour in Florence',
  'David & Accademia Gallery Florence Private Tour with Local Guide',
  'Private Tour-Pitti Palace & Palatina Gallery, Boboli Gardens Tkts',
  'Michelangelo Private Guided Tour',
  'Exclusive Evening Tour of Michelangelo\'s David',
  'Private Tour in Bargello Museum',
  'Medici Family Private Guided Walking Tour',
  'Discover Palazzo Vecchio a Private Museum Tour in Florence',
  'Renaissance Masterpiece David & Historic Florence Tour',
  'Renaissance Florence with Expert Guided Walking Tour',
  'Uffizi Gallery Small Group Guided Tour with Tickets',
  '3H Semi Private Guided Tour to Uffizi Gallery & Accademia Gallery',
  'Uffizi Gallery VIP Entrance and Private Guided Tour',
  'Accademia Gallery and Walking Semi-private Tour (6 people) - Evening Tour'
];

const DURATION_OPTIONS = [
  '1 hour',
  '1.5 hours',
  '2 hours',
  '2.5 hours',
  '3 hours',
  '3.5 hours',
  '4 hours'
];

// Price calculation based on tour duration (in euros)
const calculatePrice = (duration) => {
  switch(duration) {
    case '1 hour': return 50;
    case '1.5 hours': return 75;
    case '2 hours': return 100;
    case '2.5 hours': return 125;
    case '3 hours': return 150;
    case '3.5 hours': return 175;
    case '4 hours': return 200;
    default: return 0;
  }
};

// Generate time options in 5-minute intervals (00:00 to 23:55)
const generateTimeOptions = () => {
  const options = [];
  for (let hour = 0; hour < 24; hour++) {
    for (let minute = 0; minute < 60; minute += 5) {
      const formattedHour = hour.toString().padStart(2, '0');
      const formattedMinute = minute.toString().padStart(2, '0');
      options.push(`${formattedHour}:${formattedMinute}`);
    }
  }
  return options;
};

const TIME_OPTIONS = generateTimeOptions();

// Custom header for the DatePicker
const CustomHeader = ({
  date,
  decreaseMonth,
  increaseMonth,
  prevMonthButtonDisabled,
  nextMonthButtonDisabled,
}) => {
  const monthNames = [
    "GENNAIO", "FEBBRAIO", "MARZO", "APRILE", "MAGGIO", "GIUGNO",
    "LUGLIO", "AGOSTO", "SETTEMBRE", "OTTOBRE", "NOVEMBRE", "DICEMBRE"
  ];
  
  return (
    <div className="flex justify-between items-center bg-purple-600 text-white py-2 px-4">
      <button
        onClick={decreaseMonth}
        disabled={prevMonthButtonDisabled}
        type="button"
        className="text-white text-2xl font-bold"
      >
        &#8249;
      </button>
      <div className="text-center tracking-wide">
        <span>{monthNames[date.getMonth()]}</span>{" "}
        <span>{date.getFullYear()}</span>
      </div>
      <button
        onClick={increaseMonth}
        disabled={nextMonthButtonDisabled}
        type="button"
        className="text-white text-2xl font-bold"
      >
        &#8250;
      </button>
    </div>
  );
};

const Tours = () => {
  const { setPageTitle } = usePageTitle();
  const [guides, setGuides] = useState([]);
  const [tours, setTours] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedDate, setSelectedDate] = useState(null);
  const [showAddForm, setShowAddForm] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState(null);
  
  // Add state for guide filter
  const [selectedGuideId, setSelectedGuideId] = useState('all');
  
  // Add state for date filter
  const [filterDate, setFilterDate] = useState(null);
  
  // Add pagination state
  const [currentPage, setCurrentPage] = useState(1);
  const [toursPerPage, setToursPerPage] = useState(20);
  const ITEMS_PER_PAGE_OPTIONS = [10, 20, 50, 100];
  
  // Add state for showing unpaid tours modal
  const [showUnpaidToursModal, setShowUnpaidToursModal] = useState(false);
  
  // Create a ref to track if guides have been fetched for this component instance
  const guidesLastFetchedAt = React.useRef(null);
  
  // Helper functions to determine upcoming tours with improved future date handling
  const isTomorrow = (dateString) => {
    const today = new Date();
    // Adjust to Italian timezone (CET/CEST)
    today.setMinutes(today.getMinutes() + today.getTimezoneOffset() + 60); // Add offset for Italian timezone (UTC+1)
    
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    
    // Reset times to midnight for comparison
    tomorrow.setHours(0, 0, 0, 0);
    
    const tourDate = new Date(dateString);
    tourDate.setHours(0, 0, 0, 0);
    
    return tourDate.getTime() === tomorrow.getTime();
  };
  
  const isDayAfterTomorrow = (dateString) => {
    const today = new Date();
    // Adjust to Italian timezone (CET/CEST)
    today.setMinutes(today.getMinutes() + today.getTimezoneOffset() + 60); // Add offset for Italian timezone (UTC+1)
    
    const dayAfter = new Date(today);
    dayAfter.setDate(today.getDate() + 2);
    
    // Reset times to midnight for comparison
    dayAfter.setHours(0, 0, 0, 0);
    
    const tourDate = new Date(dateString);
    tourDate.setHours(0, 0, 0, 0);
    
    return tourDate.getTime() === dayAfter.getTime();
  };
  
  // Check if the date is in the future (beyond day after tomorrow)
  const isFutureDate = (dateString) => {
    const today = new Date();
    // Adjust to Italian timezone (CET/CEST)
    today.setMinutes(today.getMinutes() + today.getTimezoneOffset() + 60); // Add offset for Italian timezone (UTC+1)
    
    const dayAfterTomorrow = new Date(today);
    dayAfterTomorrow.setDate(today.getDate() + 2);
    
    // Set to end of day for comparison
    dayAfterTomorrow.setHours(23, 59, 59, 999);
    
    const tourDate = new Date(dateString);
    
    return tourDate > dayAfterTomorrow;
  };
  
  // Add a helper function to normalize dates
  const normalizeDateForComparison = (dateString) => {
    // Create a new date object and ensure it's properly parsed
    const date = new Date(dateString);
    // Reset hours to ensure consistent comparison
    date.setHours(0, 0, 0, 0);
    return date;
  };
  
  // Updated to properly handle future dates and European timezone
  const isPastTour = (dateString, timeString) => {
    const now = new Date();
    // Adjust to Italian timezone (CET/CEST)
    now.setMinutes(now.getMinutes() + now.getTimezoneOffset() + 60); // Add offset for Italian timezone (UTC+1)
    
    // Using normalized date comparison
    const tourDate = normalizeDateForComparison(dateString);
    const nowDate = new Date(now);
    nowDate.setHours(0, 0, 0, 0);
    
    // If date is in the future, it's definitely not a past tour
    if (tourDate.getTime() > nowDate.getTime()) {
      return false;
    }
    
    // If date is in the past, it's a past tour
    if (tourDate.getTime() < nowDate.getTime()) {
      return true;
    }
    
    // If it's today, check the time
    if (tourDate.getTime() === nowDate.getTime()) {
      // Convert time string (HH:MM) to hours and minutes
      const [hours, minutes] = timeString.split(':').map(Number);
      
      // Get current hours and minutes
      const currentHours = now.getHours();
      const currentMinutes = now.getMinutes();
      
      // Compare times
      if (hours < currentHours || (hours === currentHours && minutes <= currentMinutes)) {
        return true; // Tour time has passed
      }
    }
    
    return false;
  };
  
  // Updated to check for both date and time for active tours today and handle European timezone
  const isToday = (dateString, timeString) => {
    const today = new Date();
    // Adjust to Italian timezone (CET/CEST)
    today.setMinutes(today.getMinutes() + today.getTimezoneOffset() + 60); // Add offset for Italian timezone (UTC+1)
    
    const tourDate = new Date(dateString);
    
    // If not the same date, it's not today
    if (tourDate.toDateString() !== today.toDateString()) {
      return false;
    }
    
    // It's today's date, now check if the time has passed
    const [hours, minutes] = timeString.split(':').map(Number);
    const currentHours = today.getHours();
    const currentMinutes = today.getMinutes();
    
    // If tour time has passed, it's not considered "today" anymore
    if (hours < currentHours || (hours === currentHours && minutes <= currentMinutes)) {
      return false;
    }
    
    return true;
  };
  
  const getCardClass = (dateString, timeString) => {
    if (isPastTour(dateString, timeString)) {
      return "bg-gray-200 border-gray-300";
    } else if (isToday(dateString, timeString)) {
      return "bg-yellow-100 border-yellow-300";
    } else if (isTomorrow(dateString)) {
      return "bg-green-100 border-green-300";
    } else if (isDayAfterTomorrow(dateString)) {
      return "bg-blue-50 border-blue-200";
    } else if (isFutureDate(dateString)) {
      return "bg-purple-50 border-purple-200";
    }
    return "bg-white";
  };
  
  const getHeaderClass = (dateString, timeString) => {
    if (isPastTour(dateString, timeString)) {
      return "bg-gray-300 text-gray-800";
    } else if (isToday(dateString, timeString)) {
      return "bg-yellow-500 text-white";
    } else if (isTomorrow(dateString)) {
      return "bg-green-500 text-white";
    } else if (isDayAfterTomorrow(dateString)) {
      return "bg-blue-500 text-white";
    } else if (isFutureDate(dateString)) {
      return "bg-purple-500 text-white";
    }
    return "bg-gray-100 text-gray-800";
  };
  
  const [formData, setFormData] = useState({
    title: '',
    duration: '',
    description: '',
    date: '',
    time: '',
    guideId: '',
    paid: false
  });
  
  // Enhanced fetchGuides function that forces a fresh fetch
  const fetchGuides = async (forceFresh = false) => {
    try {
      // If not forcing fresh and guides were fetched recently (within 2 seconds), don't fetch again
      if (!forceFresh && guidesLastFetchedAt.current && (new Date().getTime() - guidesLastFetchedAt.current < 2000)) {
        console.log('Using recently fetched guides data');
        return;
      }
      
      console.log('Fetching fresh guides from MySQL database...');
      setIsLoading(true);
      const data = await getGuides();
      console.log('Guides retrieved:', data.length, data);
      setGuides(data);
      guidesLastFetchedAt.current = new Date().getTime();
      setError(null);
    } catch (err) {
      console.error('Error fetching guides:', err);
      setError('Failed to load guides. Please try again later.');
    } finally {
      setIsLoading(false);
    }
  };
  
  // Main useEffect for component initialization
  useEffect(() => {
    // Set the page title when component mounts
    setPageTitle('Tours Dashboard');
    
    // Initial data loading
    fetchGuides(true); // Force fresh fetch on initial load
    fetchTours();
    
    // Clean up function to reset page title when component unmounts
    return () => setPageTitle('');
  }, [setPageTitle]);
  
  const fetchTours = async () => {
    try {
      console.log('Fetching tours from database...');
      setIsLoading(true);
      setError(null);
      const data = await getTours();
      console.log('Tours retrieved:', data.length, data);
      
      // Ensure data is an array and has the expected shape
      if (Array.isArray(data)) {
        setTours(data);
      } else {
        console.error('Tour data is not an array:', data);
        setTours([]);
        setError('Tour data format is incorrect. Please contact support.');
      }
    } catch (err) {
      console.error('Error fetching tours:', err);
      setError('Failed to load tours. Please try again later.');
      setTours([]); // Ensure tours is always an array
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData({
      ...formData,
      [name]: value
    });
  };
  
  const handleDateChange = (date) => {
    setSelectedDate(date);
    const formattedDate = format(date, "yyyy-MM-dd");
    setFormData({
      ...formData,
      date: formattedDate
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.title || !formData.date || !formData.time || !formData.guideId) return;

    try {
      setIsLoading(true);
      
      const newTour = await addTour(formData);
      
      // Process the tour to ensure date consistency
      const processedTour = {
        ...newTour,
        // Ensure date is in the correct format
        date: typeof newTour.date === 'string' ? newTour.date : format(new Date(newTour.date), "yyyy-MM-dd"),
        guide_id: newTour.guide_id ? newTour.guide_id.toString() : newTour.guide_id,
        paid: newTour.paid || false // Ensure paid status is included
      };
      
      // Update tours state with the processed tour
      setTours(currentTours => [...currentTours, processedTour]);
      
      setFormData({
        title: '',
        duration: '',
        description: '',
        date: '',
        time: '',
        guideId: '',
        paid: false
      });
      setSelectedDate(null);
      setShowAddForm(false);
      setError(null);
    } catch (err) {
      console.error('Error adding tour:', err);
      setError('Failed to add tour. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const confirmDelete = (tour) => {
    setDeleteConfirmation(tour);
  };
  
  const cancelDelete = () => {
    setDeleteConfirmation(null);
  };

  const handleDelete = async () => {
    if (!deleteConfirmation) return;
    
    try {
      setIsLoading(true);
      await deleteTour(deleteConfirmation.id);
      // Update local state after successful deletion
      setTours(tours.filter(tour => tour.id !== deleteConfirmation.id));
      setDeleteConfirmation(null);
      setError(null);
    } catch (error) {
      console.error('Error deleting tour:', error);
      setError('Failed to delete tour. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  // Update handler for the Add Tour button - completely replaced
  const handleAddTourClick = () => {
    if (!showAddForm) {
      // Always fetch fresh guides data before showing the form
      fetchGuides(true).then(() => {
        setShowAddForm(true);
      });
    } else {
      setShowAddForm(false);
    }
  };

  // Keep the existing useEffect for form visibility changes as a backup
  useEffect(() => {
    if (showAddForm) {
      fetchGuides(true);
    }
  }, [showAddForm]);

  // Handle guide filter change with pagination reset
  const handleGuideFilterChange = (e) => {
    setSelectedGuideId(e.target.value);
    setCurrentPage(1); // Reset to first page when filter changes
  };

  // Add function to clear date filter
  const clearDateFilter = () => {
    setFilterDate(null);
    setCurrentPage(1); // Reset to first page when filter changes
  };

  // Handle date filter change
  const handleDateFilterChange = (date) => {
    setFilterDate(date);
    setCurrentPage(1); // Reset to first page when filter changes
  };

  // Handle page change
  const handlePageChange = (pageNumber) => {
    setCurrentPage(pageNumber);
    // Scroll to top of tours list
    window.scrollTo({
      top: document.getElementById('tours-list')?.offsetTop - 100 || 0,
      behavior: 'smooth'
    });
  };

  // Handle items per page change
  const handleItemsPerPageChange = (e) => {
    const value = parseInt(e.target.value, 10);
    setToursPerPage(value);
    setCurrentPage(1); // Reset to first page when changing items per page
  };

  // Add function to handle paid status toggle
  const handlePaidToggle = async (tourId, currentPaidStatus) => {
    try {
      setIsLoading(true);
      
      // Toggle the paid status
      const newPaidStatus = !currentPaidStatus;
      
      // Update in database/localStorage
      await updateTourPaidStatus(tourId, newPaidStatus);
      
      // Update local state
      setTours(currentTours => 
        currentTours.map(tour => 
          tour.id === tourId ? { ...tour, paid: newPaidStatus } : tour
        )
      );
      
      setError(null);
    } catch (err) {
      console.error('Error updating paid status:', err);
      setError('Failed to update payment status. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  // Debugging - Log current state
  useEffect(() => {
    console.log('Current tours state:', tours);
    console.log('Current guides state:', guides);
    // Check the component exists
    console.log('TourCards component loaded:', typeof TourCards === 'function');
  }, [tours, guides]);

  // Just before the return statement, add this debug log
  useEffect(() => {
    if (tours.length > 0) {
      console.log('Tours data available, should be showing cards', tours.length);
    }
  }, [tours]);

  // Get all completed unpaid tours
  const getCompletedUnpaidTours = () => {
    return tours.filter(tour => 
      isPastTour(tour.date, tour.time) && !tour.paid
    );
  };

  // Add this new function to handle tour cancellations
  const handleCancelTour = async (tourId) => {
    try {
      setIsLoading(true);
      
      // Update in database/localStorage
      await updateTourCancelStatus(tourId, true);
      
      // Update local state
      setTours(currentTours => 
        currentTours.map(tour => 
          tour.id === tourId ? { ...tour, cancelled: true } : tour
        )
      );
      
      setError(null);
    } catch (err) {
      console.error('Error cancelling tour:', err);
      setError('Failed to cancel tour. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="max-w-6xl mx-auto p-4">
      {/* Dashboard action buttons */}
      <div className="flex justify-end items-center mb-6">
        <div className="flex flex-wrap gap-3">
          <Link
            to="/guides"
            className="relative px-4 sm:px-6 py-2 sm:py-3 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
          >
            <span className="relative z-10 flex items-center">
              <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
              </svg>
              Add Guide
            </span>
            <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
          </Link>
          <button 
            onClick={handleAddTourClick}
            className="relative px-4 sm:px-6 py-2 sm:py-3 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
          >
            <span className="relative z-10 flex items-center">
              <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {showAddForm ? (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                ) : (
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                )}
              </svg>
              {showAddForm ? 'Cancel' : 'Add Tour'}
            </span>
            <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
          </button>
        </div>
      </div>

      {/* Guide Filter - Updated for better mobile responsiveness */}
      <div className="mb-6 bg-gray-50 p-3 sm:p-4 rounded-lg border border-gray-200">
        <div className="flex flex-col gap-4">
          {/* Guide Filter */}
          <div className="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
            <label htmlFor="guideFilter" className="font-medium text-gray-700 whitespace-nowrap">
              Filter by Guide:
            </label>
            <div className="relative w-full sm:w-64">
              <select
                id="guideFilter"
                value={selectedGuideId}
                onChange={handleGuideFilterChange}
                className="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-purple-500 focus:border-purple-500 bg-white"
              >
                <option value="all">All Guides</option>
                {guides.map(guide => (
                  <option key={guide.id} value={guide.id}>
                    {guide.name}
                  </option>
                ))}
              </select>
              <div className="absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none">
                <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 20 20" stroke="currentColor">
                  <path d="M7 7l3-3 3 3m0 6l-3 3-3-3" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </div>
            </div>
          </div>
          
          {/* Date Filter - added to match guide filter style */}
          <div className="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3">
            <label htmlFor="dateFilter" className="font-medium text-gray-700 whitespace-nowrap">
              Filter by Date:
            </label>
            <div className="flex flex-1 sm:w-auto items-center gap-2">
              <div className="relative flex-1 sm:flex-none sm:w-64">
                <div className="flex items-center border border-gray-300 rounded-md shadow-sm bg-white">
                  <span className="pl-3 text-gray-500">
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                  </span>
                  <DatePicker
                    id="dateFilter"
                    selected={filterDate}
                    onChange={handleDateFilterChange}
                    dateFormat="dd/MM/yyyy"
                    locale={it}
                    renderCustomHeader={CustomHeader}
                    calendarClassName="bg-white shadow-lg border rounded-md" 
                    dayClassName={date => 
                      date.getDate() === filterDate?.getDate() && 
                      date.getMonth() === filterDate?.getMonth() 
                        ? "bg-[#8207c5] text-white rounded-full" 
                        : undefined
                    }
                    className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                    placeholderText="Select date to filter"
                  />
                </div>
              </div>
              
              {filterDate && (
                <button
                  onClick={clearDateFilter}
                  className="px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-400 transition-colors duration-200 shadow-sm"
                >
                  Clear
                </button>
              )}
            </div>
          </div>
        </div>
      </div>
      
      {/* Count of completed unpaid tours */}
      {(() => {
        // Calculate completed unpaid tours
        const completedUnpaidTours = getCompletedUnpaidTours();
        
        // Only show if there are any completed unpaid tours
        if (completedUnpaidTours.length > 0) {
          return (
            <div className="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
              <div className="flex justify-between items-center">
                <div className="flex items-center">
                  <svg className="h-6 w-6 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span className="font-medium text-red-800">
                    You have <span className="font-bold">{completedUnpaidTours.length}</span> completed {completedUnpaidTours.length === 1 ? 'tour' : 'tours'} that {completedUnpaidTours.length === 1 ? 'is' : 'are'} unpaid
                  </span>
                </div>
                <button 
                  className="text-sm px-3 py-1 bg-red-100 text-red-700 rounded-md hover:bg-red-200 transition-colors"
                  onClick={() => setShowUnpaidToursModal(true)}
                >
                  View all
                </button>
              </div>
            </div>
          );
        }
        
        return null;
      })()}
      
      {error && (
        <div className="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded mb-6">
          <div className="flex items-center">
            <svg className="h-5 w-5 mr-2 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p>{error}</p>
          </div>
        </div>
      )}
      
      {/* Add Tour Form */}
      {showAddForm && (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 sm:p-6 mb-6">
          <h2 className="text-xl font-semibold text-gray-800 mb-4 sm:mb-6 flex items-center">
            <svg className="w-6 h-6 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Add New Tour
          </h2>
          
          {/* Pricing Information */}
          <div className="mb-6 p-4 bg-purple-50 rounded-lg border border-purple-200">
            <h3 className="text-lg font-medium text-purple-800 mb-3">Guide Payment Information</h3>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              {DURATION_OPTIONS.map((duration, index) => (
                <div key={index} className="p-2 bg-white rounded border border-purple-100 text-center">
                  <span className="block text-sm text-gray-600">{duration}</span>
                  <span className="block font-bold text-purple-700">{calculatePrice(duration)} €</span>
                </div>
              ))}
            </div>
          </div>
          
          <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
            <div>
              <label htmlFor="title" className="block text-sm font-medium text-gray-700 mb-1">
                Tour Name
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                  </svg>
                </div>
                <select
                  id="title"
                  name="title"
                  value={formData.title}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  required
                >
                  <option value="">Select a tour</option>
                  {TOUR_NAMES.map((tourName, index) => (
                    <option key={index} value={tourName}>
                      {tourName}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            
            <div>
              <label htmlFor="guideId" className="block text-sm font-medium text-gray-700 mb-1">
                Guide
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </div>
                <select
                  id="guideId"
                  name="guideId"
                  value={formData.guideId}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  required
                  disabled={guides.length === 0}
                >
                  <option value="">Select a guide</option>
                  {guides.map((guide) => (
                    <option key={guide.id} value={guide.id}>
                      {guide.name}
                    </option>
                  ))}
                </select>
              </div>
              {guides.length === 0 && (
                <p className="text-sm text-yellow-600 mt-1">No guides available. Please add guides first.</p>
              )}
              {guides.length > 0 && guides.length < 3 && (
                <p className="text-sm text-blue-600 mt-1">Tip: Refresh page to see the latest guides added.</p>
              )}
            </div>
            
            <div>
              <label htmlFor="date" className="block text-sm font-medium text-gray-700 mb-1">
                Date
              </label>
              <div className="relative">
                <div className="flex items-center border border-gray-300 rounded-md shadow-sm">
                  <span className="pl-3 text-gray-500">
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                  </span>
                  <DatePicker
                    selected={selectedDate}
                    onChange={handleDateChange}
                    dateFormat="dd/MM/yyyy"
                    locale={it}
                    renderCustomHeader={CustomHeader}
                    calendarClassName="bg-white shadow-lg border rounded-md" 
                    dayClassName={date => 
                      date.getDate() === selectedDate?.getDate() && 
                      date.getMonth() === selectedDate?.getMonth() 
                        ? "bg-[#8207c5] text-white rounded-full" 
                        : undefined
                    }
                    className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                    placeholderText="Select date"
                    required
                  />
                </div>
              </div>
            </div>
            
            <div>
              <label htmlFor="time" className="block text-sm font-medium text-gray-700 mb-1">
                Time
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <select
                  id="time"
                  name="time"
                  value={formData.time}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  required
                >
                  <option value="">Select time</option>
                  {TIME_OPTIONS.map((time, index) => (
                    <option key={index} value={time}>
                      {time}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            
            <div>
              <label htmlFor="duration" className="block text-sm font-medium text-gray-700 mb-1">
                Duration
              </label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <select
                  id="duration"
                  name="duration"
                  value={formData.duration}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  required
                >
                  <option value="">Select duration</option>
                  {DURATION_OPTIONS.map((duration, index) => (
                    <option key={index} value={duration}>
                      {duration} - {calculatePrice(duration)} €
                    </option>
                  ))}
                </select>
              </div>
              {formData.duration && (
                <p className="mt-1 text-sm text-purple-600">
                  Guide payment: {calculatePrice(formData.duration)} €
                </p>
              )}
            </div>
            
            <div className="md:col-span-2">
              <label htmlFor="description" className="block text-sm font-medium text-gray-700 mb-1">
                Description (optional)
              </label>
              <textarea
                id="description"
                name="description"
                value={formData.description}
                onChange={handleChange}
                rows={3}
                className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                placeholder="Enter tour description"
              />
            </div>
            
            <div className="md:col-span-2 flex flex-col sm:flex-row sm:justify-end gap-3 mt-2">
              <button
                type="button"
                className="px-4 sm:px-5 py-2 sm:py-2.5 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-400 transition-colors duration-200 shadow-sm"
                onClick={() => setShowAddForm(false)}
              >
                Cancel
              </button>
              
              <button
                type="submit"
                className="relative px-4 sm:px-6 py-2 sm:py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-70 disabled:cursor-not-allowed"
                disabled={isLoading || guides.length === 0}
              >
                <span className="relative z-10 flex items-center justify-center">
                  {isLoading ? (
                    <>
                      <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      Saving...
                    </>
                  ) : (
                    <>Save Tour</>
                  )}
                </span>
                <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
              </button>
            </div>
          </form>
        </div>
      )}
      
      {/* Delete Confirmation Modal */}
      {deleteConfirmation && (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-8 max-w-md mx-auto shadow-xl">
            <h2 className="text-xl font-bold mb-4">Delete Tour</h2>
            <p className="mb-6">
              Are you sure you want to delete the tour "{deleteConfirmation.title}"? This action cannot be undone.
            </p>
            <div className="flex justify-end space-x-4">
              <button
                onClick={cancelDelete}
                className="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleDelete}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors"
                disabled={isLoading}
              >
                {isLoading ? 'Deleting...' : 'Delete'}
              </button>
            </div>
          </div>
        </div>
      )}
      
      {/* UnpaidToursModal */}
      {showUnpaidToursModal && (
        <UnpaidToursModal 
          unpaidTours={getCompletedUnpaidTours()}
          onClose={() => setShowUnpaidToursModal(false)}
          onPaidToggle={handlePaidToggle}
          calculatePrice={calculatePrice}
        />
      )}
      
      {/* Tours List - ONLY CARDS VIEW, NO TABLE */}
      <div id="tours-list" className="mt-6">
        {isLoading && !showAddForm ? (
          <div className="flex justify-center p-6">
            <svg className="animate-spin h-8 w-8 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
        ) : tours.length === 0 ? (
          <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
            <div className="flex justify-center items-center">
              <div className="w-12 h-12 text-purple-600">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
                </svg>
              </div>
            </div>
            <h3 className="text-lg font-semibold text-gray-800 mt-2 mb-2">No tours available</h3>
            <p className="text-gray-500 mb-4">Get started by adding your first tour</p>
            {!showAddForm && (
              <button 
                onClick={() => setShowAddForm(true)}
                className="relative px-5 py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
              >
                <span className="relative z-10 flex items-center">
                  <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                  Add Tour
                </span>
                <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
              </button>
            )}
          </div>
        ) : (
          <div className="card-view-container">
            {(() => {
              // Filter tours
              let filteredTours = tours;
              
              // Filter by guide if selected
              if (selectedGuideId !== 'all') {
                filteredTours = filteredTours.filter(tour => 
                  tour.guide_id && tour.guide_id.toString() === selectedGuideId.toString()
                );
              }
              
              // Filter by date if selected
              if (filterDate) {
                const filterDateStr = format(filterDate, "yyyy-MM-dd");
                filteredTours = filteredTours.filter(tour => tour.date === filterDateStr);
              }
              
              // Sort tours by timing category
              filteredTours.sort((a, b) => {
                const getTourCategory = (tour) => {
                  if (isToday(tour.date, tour.time)) return 0;
                  if (isTomorrow(tour.date)) return 1;
                  if (isDayAfterTomorrow(tour.date) || isFutureDate(tour.date)) return 2;
                  return 3; // Past tour
                };
                
                const categoryA = getTourCategory(a);
                const categoryB = getTourCategory(b);
                
                if (categoryA !== categoryB) {
                  return categoryA - categoryB;
                }
                
                const dateA = new Date(`${a.date}T${a.time}`);
                const dateB = new Date(`${b.date}T${b.time}`);
                
                // For past tours, show most recent first
                if (categoryA === 3) {
                  return dateB - dateA;
                }
                
                // For today, tomorrow and future tours, show earliest first
                return dateA - dateB;
              });
              
              console.log('Filtered tours for card view:', filteredTours.length);
              
              // Calculate pagination
              const indexOfLastTour = currentPage * toursPerPage;
              const indexOfFirstTour = indexOfLastTour - toursPerPage;
              const currentTours = filteredTours.slice(indexOfFirstTour, indexOfLastTour);
              const totalPages = Math.ceil(filteredTours.length / toursPerPage);
              
              // Create pagination range with ellipsis
              const getPaginationRange = () => {
                const delta = 2; // Number of page buttons to show on each side of current page
                const range = [];
                
                // Always include first page
                range.push(1);
                
                // Calculate start and end of range around current page
                let start = Math.max(2, currentPage - delta);
                let end = Math.min(totalPages - 1, currentPage + delta);
                
                // Adjust range to maintain consistent size
                if (end - start < 2 * delta) {
                  if (currentPage - delta < 2) {
                    end = Math.min(2 * delta + 1, totalPages - 1);
                  } else if (currentPage + delta >= totalPages) {
                    start = Math.max(totalPages - (2 * delta) - 1, 2);
                  }
                }
                
                // Add ellipsis before range if needed
                if (start > 2) {
                  range.push('...');
                }
                
                // Add pages in range
                for (let i = start; i <= end; i++) {
                  range.push(i);
                }
                
                // Add ellipsis after range if needed
                if (end < totalPages - 1) {
                  range.push('...');
                }
                
                // Always include last page if it exists and isn't already in range
                if (totalPages > 1) {
                  range.push(totalPages);
                }
                
                return range;
              };
              
              // Generate pagination range with ellipsis
              const paginationRange = getPaginationRange();
              
              // Use the dedicated CardView component
              return (
                <CardView
                  tours={tours}
                  filteredTours={filteredTours}
                  currentTours={currentTours}
                  calculatePrice={calculatePrice}
                  isPastTour={isPastTour}
                  isToday={isToday}
                  isTomorrow={isTomorrow}
                  isDayAfterTomorrow={isDayAfterTomorrow}
                  isFutureDate={isFutureDate}
                  onPaidToggle={handlePaidToggle}
                  onDelete={confirmDelete}
                  onCancelTour={handleCancelTour}
                  indexOfFirstTour={indexOfFirstTour}
                  indexOfLastTour={indexOfLastTour}
                  totalPages={totalPages}
                  currentPage={currentPage}
                  handlePageChange={handlePageChange}
                  paginationRange={paginationRange}
                  toursPerPage={toursPerPage}
                  handleItemsPerPageChange={handleItemsPerPageChange}
                  ITEMS_PER_PAGE_OPTIONS={ITEMS_PER_PAGE_OPTIONS}
                  filterDate={filterDate}
                  selectedGuideId={selectedGuideId}
                />
              );
            })()}
          </div>
        )}
      </div>
    </div>
  );
};

export default Tours;