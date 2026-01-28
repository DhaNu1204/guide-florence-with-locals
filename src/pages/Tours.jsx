import React, { useState, useEffect, useMemo } from 'react';
import { FiPlus, FiRefreshCw, FiSave } from 'react-icons/fi';
import { format } from 'date-fns';
import mysqlDB from '../services/mysqlDB';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import BookingDetailsModal from '../components/BookingDetailsModal';
import { isTicketProduct, filterToursOnly } from '../utils/tourFilters';

// Helper functions moved outside component to prevent dependency loops
const isToday = (tourDate, tourTime) => {
  const today = new Date();
  const tour = new Date(`${tourDate}T${tourTime}`);
  return today.toDateString() === tour.toDateString();
};

const isTomorrow = (tourDate) => {
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const tour = new Date(tourDate);
  return tomorrow.toDateString() === tour.toDateString();
};

const isDayAfterTomorrow = (tourDate) => {
  const dayAfter = new Date();
  dayAfter.setDate(dayAfter.getDate() + 2);
  const tour = new Date(tourDate);
  return dayAfter.toDateString() === tour.toDateString();
};

const isFutureDate = (tourDate) => {
  const future = new Date();
  future.setDate(future.getDate() + 3);
  const tour = new Date(tourDate);
  return tour >= future;
};

// Helper function to extract participant count from bokun_data
const getParticipantCount = (tour) => {
  try {
    if (tour.bokun_data) {
      const bokunData = JSON.parse(tour.bokun_data);
      if (bokunData.productBookings && bokunData.productBookings[0] && bokunData.productBookings[0].fields) {
        return bokunData.productBookings[0].fields.totalParticipants || parseInt(tour.participants) || 1;
      }
    }
    return parseInt(tour.participants) || 1;
  } catch (error) {
    return parseInt(tour.participants) || 1;
  }
};

// Helper function to extract booking time from bokun_data
const getBookingTime = (tour) => {
  try {
    if (tour.bokun_data) {
      const bokunData = JSON.parse(tour.bokun_data);
      if (bokunData.productBookings && bokunData.productBookings[0] && bokunData.productBookings[0].fields) {
        const startTimeStr = bokunData.productBookings[0].fields.startTimeStr;
        if (startTimeStr) {
          return startTimeStr;
        }
      }
    }
    // Fallback to tour.time if no Bokun data available
    return tour.time || '09:00';
  } catch (error) {
    return tour.time || '09:00';
  }
};

// Helper function to extract booking date from bokun_data
const getBookingDate = (tour) => {
  try {
    // First, try to use the tour.date field directly (most reliable from database)
    if (tour.date) {
      // Handle various date formats
      const dateStr = tour.date;
      // If it's already in YYYY-MM-DD format, return as-is
      if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
        return dateStr;
      }
      // If it's a full ISO string or timestamp, extract the date part
      const parsed = new Date(dateStr);
      if (!isNaN(parsed.getTime())) {
        // Use local date to avoid timezone issues
        const year = parsed.getFullYear();
        const month = String(parsed.getMonth() + 1).padStart(2, '0');
        const day = String(parsed.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
      }
    }

    // Fallback: try to extract from bokun_data
    if (tour.bokun_data) {
      const bokunData = typeof tour.bokun_data === 'string'
        ? JSON.parse(tour.bokun_data)
        : tour.bokun_data;

      if (bokunData.productBookings && bokunData.productBookings[0]) {
        // Try to get startDateTime first (more precise)
        const startDateTime = bokunData.productBookings[0].startDateTime;
        if (startDateTime) {
          const date = new Date(startDateTime);
          const year = date.getFullYear();
          const month = String(date.getMonth() + 1).padStart(2, '0');
          const day = String(date.getDate()).padStart(2, '0');
          return `${year}-${month}-${day}`;
        }

        // Fallback to startDate
        const startDate = bokunData.productBookings[0].startDate;
        if (startDate) {
          const date = new Date(startDate);
          const year = date.getFullYear();
          const month = String(date.getMonth() + 1).padStart(2, '0');
          const day = String(date.getDate()).padStart(2, '0');
          return `${year}-${month}-${day}`;
        }
      }
    }

    return '';
  } catch (error) {
    console.error('Error parsing tour date:', error, tour);
    return tour.date || '';
  }
};

// Helper function to extract tour language from bokun_data
const getTourLanguage = (tour) => {
  try {
    // First check if language is stored in database
    if (tour.language) {
      return tour.language;
    }

    // Extract from Bokun data
    if (tour.bokun_data) {
      const bokunData = JSON.parse(tour.bokun_data);
      if (bokunData.productBookings && bokunData.productBookings[0]) {
        const booking = bokunData.productBookings[0];

        // IMPORTANT: Check in notes for "GUIDE : English" pattern
        if (booking.notes && Array.isArray(booking.notes)) {
          for (const note of booking.notes) {
            if (note.body) {
              // Look for "GUIDE : English" or similar patterns
              const guideMatch = note.body.match(/GUIDE\s*:\s*([A-Za-z]+)/i);
              if (guideMatch) {
                return guideMatch[1].charAt(0).toUpperCase() + guideMatch[1].slice(1).toLowerCase();
              }

              // Look for "Booking languages:" section
              const langMatch = note.body.match(/Booking languages.*?:\s*([A-Za-z]+)/is);
              if (langMatch) {
                return langMatch[1].charAt(0).toUpperCase() + langMatch[1].slice(1).toLowerCase();
              }
            }
          }
        }

        // Option 1: Check in fields
        if (booking.fields && booking.fields.language) {
          return booking.fields.language;
        }

        // Option 2: Check in product details
        if (booking.product && booking.product.language) {
          return booking.product.language;
        }

        // Option 3: Check title for language indicators
        const title = (booking.product?.title || booking.title || tour.title || '').toLowerCase();
        if (title.includes('italian')) return 'Italian';
        if (title.includes('spanish')) return 'Spanish';
        if (title.includes('french')) return 'French';
        if (title.includes('german')) return 'German';
        if (title.includes('english')) return 'English';
      }
    }

    // Return null if no language found - don't assume
    return null;
  } catch (error) {
    return null;
  }
};

const Tours = () => {
  const [tours, setTours] = useState([]);
  const [guides, setGuides] = useState([]);
  const [selectedGuideId, setSelectedGuideId] = useState('all');
  const [filterDate, setFilterDate] = useState(new Date()); // Default to today
  const [currentPage, setCurrentPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [editingNotes, setEditingNotes] = useState({});
  const [editingGuides, setEditingGuides] = useState({});
  const [editingLanguages, setEditingLanguages] = useState({});
  const [savingChanges, setSavingChanges] = useState({});
  const [showUpcoming, setShowUpcoming] = useState(true); // Default to upcoming to show 2026 data
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedTour, setSelectedTour] = useState(null);
  const [pagination, setPagination] = useState({
    current_page: 1,
    per_page: 50,
    total: 0,
    total_pages: 0,
    has_next: false,
    has_prev: false
  });

  const toursPerPage = 100; // Increased to show more tours per page

  // Load data function with server-side filtering
  const loadData = async (forceRefresh = false, page = 1, filters = {}) => {
    try {
      setLoading(true);
      setError(null);

      // Build filters for the API
      const apiFilters = {
        ...filters
      };

      const [toursResponse, guidesData] = await Promise.all([
        mysqlDB.fetchTours(forceRefresh, page, toursPerPage, apiFilters),
        mysqlDB.fetchGuides()
      ]);

      // Handle paginated response
      if (toursResponse && toursResponse.data) {
        setTours(toursResponse.data || []);
        setPagination(toursResponse.pagination);
      } else {
        // Fallback for non-paginated response (backward compatibility)
        setTours(toursResponse || []);
      }

      setGuides(guidesData || []);

    } catch (err) {
      console.error('Load error:', err);
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  // Build current filters object
  const getCurrentFilters = () => {
    const filters = {};
    if (!showUpcoming && filterDate) {
      filters.date = format(filterDate, 'yyyy-MM-dd');
    }
    if (showUpcoming) {
      filters.upcoming = true;
    }
    if (selectedGuideId !== 'all') {
      filters.guide_id = selectedGuideId;
    }
    return filters;
  };

  // Handle refresh button click
  const handleRefresh = async () => {
    await loadData(true, currentPage, getCurrentFilters());
  };

  // Handle page change
  const handlePageChange = async (newPage) => {
    setCurrentPage(newPage);
    await loadData(false, newPage, getCurrentFilters());
  };

  // Load data when filters change
  useEffect(() => {
    setCurrentPage(1); // Reset to page 1 when filters change
    loadData(false, 1, getCurrentFilters());
  }, [filterDate, showUpcoming, selectedGuideId]);

  // Memoized filtered and grouped tours by date
  // Note: Date and guide filtering is now done on the server side for efficiency
  const groupedTours = useMemo(() => {
    // Filter out ticket products using smart keyword detection from tourFilters utility
    // Tickets don't need guide assignment and belong in Priority Tickets page
    let filtered = filterToursOnly(tours);

    // Helper function to get time period
    const getTimePeriod = (time) => {
      const hour = parseInt(time.split(':')[0]);
      if (hour < 12) return 'Morning (6:00 - 11:59)';
      if (hour < 17) return 'Afternoon (12:00 - 16:59)';
      return 'Evening (17:00 - 23:59)';
    };

    // Group tours by date, then by time period
    const grouped = {};
    filtered.forEach(tour => {
      const tourDate = getBookingDate(tour);
      const tourTime = getBookingTime(tour);
      const timePeriod = getTimePeriod(tourTime);

      if (!grouped[tourDate]) {
        grouped[tourDate] = {};
      }
      if (!grouped[tourDate][timePeriod]) {
        grouped[tourDate][timePeriod] = [];
      }
      grouped[tourDate][timePeriod].push(tour);
    });

    // Sort tours within each time period by time
    Object.keys(grouped).forEach(date => {
      Object.keys(grouped[date]).forEach(period => {
        grouped[date][period].sort((a, b) => {
          const timeA = getBookingTime(a);
          const timeB = getBookingTime(b);
          const dateTimeA = new Date(`${getBookingDate(a)}T${timeA}`);
          const dateTimeB = new Date(`${getBookingDate(b)}T${timeB}`);
          return dateTimeA - dateTimeB;
        });
      });
    });

    // Sort dates - today first, then chronologically
    const sortedDates = Object.keys(grouped).sort((a, b) => {
      const today = format(new Date(), 'yyyy-MM-dd');

      if (a === today && b !== today) return -1;
      if (b === today && a !== today) return 1;

      // Both are not today, sort chronologically (nearest dates first)
      const dateA = new Date(a);
      const dateB = new Date(b);
      return dateA - dateB;
    });

    // Sort time periods within each date (Morning, Afternoon, Evening)
    const periodOrder = ['Morning (6:00 - 11:59)', 'Afternoon (12:00 - 16:59)', 'Evening (17:00 - 23:59)'];

    return sortedDates.map(date => ({
      date,
      periods: periodOrder
        .filter(period => grouped[date][period])
        .map(period => ({
          period,
          tours: grouped[date][period]
        }))
    }));
  }, [tours]); // Only depends on tours - filtering is done server-side

  // Calculate total tours and participants from grouped data
  const totalData = useMemo(() => {
    let allTours = [];
    let totalParticipants = 0;

    groupedTours.forEach(group => {
      group.periods.forEach(periodGroup => {
        allTours = [...allTours, ...periodGroup.tours];
        periodGroup.tours.forEach(tour => {
          totalParticipants += getParticipantCount(tour);
        });
      });
    });

    return {
      totalTours: allTours.length,
      totalParticipants,
      allTours
    };
  }, [groupedTours]);

  // Handle notes editing
  const handleNotesChange = (tourId, notes) => {
    setEditingNotes(prev => ({
      ...prev,
      [tourId]: notes
    }));
  };

  // Handle guide selection
  const handleGuideChange = (tourId, guideId) => {
    setEditingGuides(prev => ({
      ...prev,
      [tourId]: guideId
    }));
  };

  // Save notes for a tour
  const saveNotes = async (tourId) => {
    const notes = editingNotes[tourId];
    setSavingChanges(prev => ({ ...prev, [`notes_${tourId}`]: true }));

    try {
      await mysqlDB.updateTour(tourId, { notes });
      // Update local state
      setTours(prev => prev.map(tour =>
        tour.id === tourId ? { ...tour, notes } : tour
      ));
      setEditingNotes(prev => {
        const newState = { ...prev };
        delete newState[tourId];
        return newState;
      });
    } catch (error) {
      console.error('Error saving notes:', error);
      setError('Failed to save notes');
    } finally {
      setSavingChanges(prev => {
        const newState = { ...prev };
        delete newState[`notes_${tourId}`];
        return newState;
      });
    }
  };

  // Save guide assignment for a tour
  const saveGuideAssignment = async (tourId) => {
    const guideId = editingGuides[tourId];
    setSavingChanges(prev => ({ ...prev, [`guide_${tourId}`]: true }));

    try {
      await mysqlDB.updateTour(tourId, { guide_id: guideId });
      // Update local state
      setTours(prev => prev.map(tour =>
        tour.id === tourId ? { ...tour, guide_id: guideId } : tour
      ));
      setEditingGuides(prev => {
        const newState = { ...prev };
        delete newState[tourId];
        return newState;
      });
    } catch (error) {
      console.error('Error saving guide assignment:', error);
      setError('Failed to save guide assignment');
    } finally {
      setSavingChanges(prev => {
        const newState = { ...prev };
        delete newState[`guide_${tourId}`];
        return newState;
      });
    }
  };

  const handleRowClick = (tour) => {
    setSelectedTour(tour);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedTour(null);
  };

  const handleUpdateNotesFromModal = async (tourId, newNotes) => {
    await mysqlDB.updateTour(tourId, { notes: newNotes });

    // Update local state
    setTours(prev =>
      prev.map(tour =>
        tour.id === tourId ? { ...tour, notes: newNotes } : tour
      )
    );
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-lg text-stone-600">Loading tours...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-lg text-terracotta-600">Error: {error}</div>
      </div>
    );
  }


  return (
    <div className="min-h-screen bg-stone-50 p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex justify-between items-center">
          <div>
            <h1 className="text-2xl font-bold text-stone-900">Tours Management</h1>
            <p className="text-stone-600">Manage your Florence tours and bookings</p>
          </div>
          <div className="flex items-center gap-3">
            <Button
              onClick={handleRefresh}
              disabled={loading}
              className="flex items-center gap-2"
            >
              <FiRefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              {loading ? 'Refreshing...' : 'Refresh'}
            </Button>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <h3 className="text-lg font-semibold mb-4">Filters</h3>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-stone-700 mb-2">Filter by Guide</label>
              <select
                value={selectedGuideId}
                onChange={(e) => setSelectedGuideId(e.target.value)}
                className="w-full px-3 py-2 border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
              >
                <option value="all">All Guides</option>
                {guides.map(guide => (
                  <option key={guide.id} value={guide.id}>{guide.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-stone-700 mb-2">Select Date</label>
              <div className="flex gap-2">
                <input
                  type="date"
                  value={filterDate ? format(filterDate, 'yyyy-MM-dd') : ''}
                  onChange={(e) => {
                    setFilterDate(e.target.value ? new Date(e.target.value) : new Date());
                    setShowUpcoming(false);
                  }}
                  className="flex-1 px-3 py-2 border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                />
                <button
                  onClick={() => {
                    setFilterDate(new Date());
                    setShowUpcoming(false);
                  }}
                  className={`px-4 py-2.5 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] ${
                    !showUpcoming && filterDate && format(filterDate, 'yyyy-MM-dd') === format(new Date(), 'yyyy-MM-dd')
                      ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                >
                  Today
                </button>
                <button
                  onClick={() => setShowUpcoming(!showUpcoming)}
                  className={`px-4 py-2.5 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] ${
                    showUpcoming
                      ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                  title="Show tours for the next 60 days"
                >
                  Upcoming
                </button>
              </div>
            </div>
          </div>
        </Card>


        {/* Tours by Date */}
        <div className="space-y-6">
          {groupedTours.length === 0 ? (
            <Card>
              <div className="text-center py-8">
                <p className="text-stone-500">No tours found for the selected criteria.</p>
              </div>
            </Card>
          ) : (
            groupedTours.map((dateGroup) => {
              const dateObj = new Date(dateGroup.date);
              const isToday = format(new Date(), 'yyyy-MM-dd') === dateGroup.date;
              const dateParticipants = dateGroup.periods.reduce((total, periodGroup) =>
                total + periodGroup.tours.reduce((sum, tour) => sum + getParticipantCount(tour), 0), 0
              );
              const dateTourCount = dateGroup.periods.reduce((total, periodGroup) =>
                total + periodGroup.tours.length, 0
              );

              return (
                <div key={dateGroup.date} className="bg-white border border-stone-200 rounded-tuscan-lg shadow-tuscan overflow-hidden">
                  {/* Date Header */}
                  <div className={`px-6 py-4 border-b border-stone-200 ${isToday ? 'bg-gold-50' : 'bg-stone-50'}`}>
                    <div className="flex justify-between items-center">
                      <div className="flex items-center gap-3">
                        <h3 className={`text-lg font-semibold ${isToday ? 'text-gold-900' : 'text-stone-900'}`}>
                          {format(dateObj, 'EEEE, d MMMM yyyy')}
                        </h3>
                        {isToday && (
                          <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gold-100 text-gold-800">
                            Today
                          </span>
                        )}
                      </div>
                      <div className="text-sm text-stone-600">
                        {dateTourCount} tours • {dateParticipants} PAX
                      </div>
                    </div>
                  </div>

                  {/* Time Periods */}
                  {dateGroup.periods.map((periodGroup, periodIndex) => (
                    <div key={`${dateGroup.date}-${periodGroup.period}`}>
                      {/* Time Period Header */}
                      <div className="bg-stone-100 px-6 py-2 border-b border-stone-200">
                        <div className="flex justify-between items-center">
                          <h4 className="text-sm font-semibold text-stone-700">
                            {periodGroup.period}
                          </h4>
                          <div className="text-xs text-stone-600">
                            {periodGroup.tours.length} tours • {periodGroup.tours.reduce((sum, tour) => sum + getParticipantCount(tour), 0)} PAX
                          </div>
                        </div>
                      </div>

                      {/* Tours Table */}
                      <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-stone-200">
                          <thead className="bg-stone-50">
                            <tr>
                              <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-20">
                                Time
                              </th>
                              <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-24">
                                Channel
                              </th>
                              <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-96">
                                Tour
                              </th>
                              <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-24">
                                Language
                              </th>
                              <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-20">
                                People
                              </th>
                              <th scope="col" className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider w-32">
                                Guide
                              </th>
                              <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                                Notes
                              </th>
                            </tr>
                          </thead>
                          <tbody className="bg-white divide-y divide-stone-200">
                            {periodGroup.tours.map((tour) => {
                          const guideName = guides.find(g => g.id == tour.guide_id)?.name || 'Unassigned';
                          return (
                            <tr
                              key={tour.id}
                              className="hover:bg-stone-50 cursor-pointer"
                              onClick={() => handleRowClick(tour)}
                            >
                              <td className="px-4 py-4 whitespace-nowrap text-sm font-medium text-stone-900">
                                {getBookingTime(tour)}
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900">
                                <div className="truncate">
                                  {tour.booking_channel || 'Website'}
                                </div>
                              </td>
                              <td className="px-6 py-4 text-sm text-stone-900">
                                <div className="break-words">
                                  {tour.title}
                                </div>
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900">
                                <span className="inline-block px-2 py-1 bg-renaissance-50 text-renaissance-700 text-xs font-medium rounded-tuscan">
                                  {getTourLanguage(tour)}
                                </span>
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900">
                                {getParticipantCount(tour)} PAX
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900" onClick={(e) => e.stopPropagation()}>
                                <div className="flex items-center gap-2">
                                  {editingGuides[tour.id] !== undefined ? (
                                    <div className="flex items-center gap-2">
                                      <select
                                        value={editingGuides[tour.id]}
                                        onChange={(e) => handleGuideChange(tour.id, e.target.value)}
                                        className="px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                                      >
                                        <option value="">Unassigned</option>
                                        {guides.map(guide => (
                                          <option key={guide.id} value={guide.id}>{guide.name}</option>
                                        ))}
                                      </select>
                                      <button
                                        onClick={() => saveGuideAssignment(tour.id)}
                                        disabled={savingChanges[`guide_${tour.id}`]}
                                        className="p-2 min-h-[40px] min-w-[40px] text-olive-600 hover:text-olive-800 hover:bg-olive-50 active:bg-olive-100 disabled:opacity-50 rounded-tuscan transition-colors touch-manipulation flex items-center justify-center"
                                        title="Save guide assignment"
                                      >
                                        <FiSave size={18} />
                                      </button>
                                      <button
                                        onClick={() => setEditingGuides(prev => {
                                          const newState = { ...prev };
                                          delete newState[tour.id];
                                          return newState;
                                        })}
                                        className="p-2 min-h-[40px] min-w-[40px] text-stone-400 hover:text-stone-600 hover:bg-stone-100 active:bg-stone-200 rounded-tuscan transition-colors touch-manipulation flex items-center justify-center"
                                        title="Cancel"
                                      >
                                        <span className="text-lg font-bold">×</span>
                                      </button>
                                    </div>
                                  ) : (
                                    <div className="flex items-center gap-2">
                                      <span
                                        className="cursor-pointer hover:bg-stone-100 px-2 py-1 rounded-tuscan"
                                        onClick={() => setEditingGuides(prev => ({
                                          ...prev,
                                          [tour.id]: tour.guide_id || ''
                                        }))}
                                      >
                                        {guideName}
                                      </span>
                                    </div>
                                  )}
                                </div>
                              </td>
                              <td className="px-6 py-4 text-sm text-stone-900" onClick={(e) => e.stopPropagation()}>
                                <div className="flex items-start gap-2">
                                  <div className="flex flex-col gap-2 flex-1">
                                    {editingNotes[tour.id] !== undefined ? (
                                      <div className="flex items-center gap-2">
                                        <textarea
                                          value={editingNotes[tour.id]}
                                          onChange={(e) => handleNotesChange(tour.id, e.target.value)}
                                          className="flex-1 px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 resize-none"
                                          rows="2"
                                          placeholder="Add notes..."
                                        />
                                        <button
                                          onClick={() => saveNotes(tour.id)}
                                          disabled={savingChanges[`notes_${tour.id}`]}
                                          className="p-2 min-h-[40px] min-w-[40px] text-olive-600 hover:text-olive-800 hover:bg-olive-50 active:bg-olive-100 disabled:opacity-50 rounded-tuscan transition-colors touch-manipulation flex items-center justify-center"
                                          title="Save notes"
                                        >
                                          <FiSave size={18} />
                                        </button>
                                        <button
                                          onClick={() => setEditingNotes(prev => {
                                            const newState = { ...prev };
                                            delete newState[tour.id];
                                            return newState;
                                          })}
                                          className="p-2 min-h-[40px] min-w-[40px] text-stone-400 hover:text-stone-600 hover:bg-stone-100 active:bg-stone-200 rounded-tuscan transition-colors touch-manipulation flex items-center justify-center"
                                          title="Cancel"
                                        >
                                          <span className="text-lg font-bold">×</span>
                                        </button>
                                      </div>
                                    ) : (
                                      <div
                                        className="cursor-pointer hover:bg-stone-100 px-2 py-1 rounded-tuscan min-h-[2rem] flex items-center"
                                        onClick={() => setEditingNotes(prev => ({
                                          ...prev,
                                          [tour.id]: tour.notes || ''
                                        }))}
                                      >
                                        {tour.notes || 'Click to add notes...'}
                                      </div>
                                    )}

                                    <div className="flex items-center gap-2 flex-wrap">
                                      {tour.paid && (
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-olive-100 text-olive-800">
                                          Paid
                                        </span>
                                      )}
                                      {tour.cancelled && (
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                                          Cancelled
                                        </span>
                                      )}
                                      {tour.rescheduled && !tour.cancelled && (
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gold-100 text-gold-800" title={`Originally scheduled for ${tour.original_date} at ${tour.original_time}`}>
                                          Rescheduled
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                </div>
                              </td>
                            </tr>
                          );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  ))}
                </div>
              );
            })
          )}

          {/* Pagination Controls */}
          {pagination.total_pages > 1 && (
            <Card>
              <div className="px-6 py-4">
                <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
                  {/* Pagination Info */}
                  <div className="text-sm text-stone-700">
                    Showing <span className="font-medium">{((pagination.current_page - 1) * pagination.per_page) + 1}</span> to{' '}
                    <span className="font-medium">
                      {Math.min(pagination.current_page * pagination.per_page, pagination.total)}
                    </span> of{' '}
                    <span className="font-medium">{pagination.total}</span> tours
                  </div>

                  {/* Pagination Buttons */}
                  <div className="flex items-center space-x-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handlePageChange(currentPage - 1)}
                      disabled={!pagination.has_prev || loading}
                    >
                      Previous
                    </Button>

                    {/* Page Numbers */}
                    <div className="flex items-center space-x-1">
                      {Array.from({ length: Math.min(5, pagination.total_pages) }, (_, i) => {
                        let pageNum;
                        if (pagination.total_pages <= 5) {
                          pageNum = i + 1;
                        } else if (currentPage <= 3) {
                          pageNum = i + 1;
                        } else if (currentPage >= pagination.total_pages - 2) {
                          pageNum = pagination.total_pages - 4 + i;
                        } else {
                          pageNum = currentPage - 2 + i;
                        }

                        return (
                          <button
                            key={pageNum}
                            onClick={() => handlePageChange(pageNum)}
                            disabled={loading}
                            className={`px-3 py-2 min-h-[40px] min-w-[40px] text-sm font-medium rounded-tuscan transition-colors touch-manipulation active:scale-[0.98] ${
                              currentPage === pageNum
                                ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                                : 'bg-white text-stone-700 hover:bg-stone-100 active:bg-stone-200 border border-stone-300'
                            }`}
                          >
                            {pageNum}
                          </button>
                        );
                      })}
                    </div>

                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => handlePageChange(currentPage + 1)}
                      disabled={!pagination.has_next || loading}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              </div>
            </Card>
          )}

          {/* Summary */}
          {groupedTours.length > 0 && (
            <Card>
              <div className="px-6 py-4">
                <div className="flex justify-between items-center">
                  <h3 className="text-lg font-semibold text-stone-900">Summary</h3>
                  <div className="text-sm text-stone-600">
                    Total: {totalData.totalTours} tours • {totalData.totalParticipants} PAX
                  </div>
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>

      {/* Booking Details Modal */}
      <BookingDetailsModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        ticket={selectedTour}
        onUpdateNotes={handleUpdateNotesFromModal}
      />
    </div>
  );
};

export default Tours;