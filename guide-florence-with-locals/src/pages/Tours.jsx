import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { FiPlus, FiRefreshCw, FiSave, FiX, FiLayers, FiCheckSquare, FiSquare, FiUsers as FiUsersIcon, FiDownload } from 'react-icons/fi';
import { format } from 'date-fns';
import mysqlDB, { tourGroupsAPI } from '../services/mysqlDB';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import BookingDetailsModal from '../components/BookingDetailsModal';
import TourGroup from '../components/TourGroup';
import TourCardMobile from '../components/TourCardMobile';
import TourGroupCardMobile from '../components/TourGroupCardMobile';
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

// Helper: parse participant_names JSON into array
const getParticipantNames = (tour) => {
  if (!tour.participant_names) return [];
  try {
    const parsed = typeof tour.participant_names === 'string'
      ? JSON.parse(tour.participant_names)
      : tour.participant_names;
    return Array.isArray(parsed) ? parsed : [];
  } catch { return []; }
};

// Compact display: "First Last +N more"
const ParticipantNamesCompact = ({ tour }) => {
  const [expanded, setExpanded] = useState(false);
  const names = getParticipantNames(tour);
  if (names.length === 0) return null;

  const first = `${names[0].first} ${names[0].last}`;
  const rest = names.length - 1;

  return (
    <div className="text-xs text-stone-500 mt-0.5">
      {expanded ? (
        <div className="space-y-0.5">
          {names.map((p, i) => (
            <div key={i}>{p.first} {p.last}</div>
          ))}
          <button
            onClick={(e) => { e.stopPropagation(); setExpanded(false); }}
            className="text-terracotta-500 hover:text-terracotta-700"
          >
            show less
          </button>
        </div>
      ) : (
        <span
          className="cursor-pointer hover:text-terracotta-600"
          onClick={(e) => { e.stopPropagation(); if (rest > 0) setExpanded(true); }}
        >
          {first}{rest > 0 && <span className="text-terracotta-500 ml-1">+{rest} more</span>}
        </span>
      )}
    </div>
  );
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
  const [tourGroups, setTourGroups] = useState([]);
  const [guides, setGuides] = useState([]);
  const [selectedGuideId, setSelectedGuideId] = useState('all');
  const [filterDate, setFilterDate] = useState(new Date()); // Default to today
  const [currentPage, setCurrentPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [success, setSuccess] = useState(null);
  const [editingNotes, setEditingNotes] = useState({});
  const [editingGuides, setEditingGuides] = useState({});
  const [editingLanguages, setEditingLanguages] = useState({});
  const [savingChanges, setSavingChanges] = useState({});
  const [showUpcoming, setShowUpcoming] = useState(true); // Default to upcoming to show 2026 data
  const [showPast, setShowPast] = useState(false); // Show past 40 days for payment verification
  const [showDateRange, setShowDateRange] = useState(false); // Custom date range mode
  const [rangeStartDate, setRangeStartDate] = useState(''); // YYYY-MM-DD string
  const [rangeEndDate, setRangeEndDate] = useState(''); // YYYY-MM-DD string
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedTour, setSelectedTour] = useState(null);
  const [autoGrouping, setAutoGrouping] = useState(false);
  const [dragState, setDragState] = useState({ draggedId: null, draggedType: null, overTargetId: null, overTargetType: null });
  // Mobile merge selection mode
  const [selectionMode, setSelectionMode] = useState(false);
  const [selectedItems, setSelectedItems] = useState([]); // [{id, type: 'tour'|'group'}]
  const [pagination, setPagination] = useState({
    current_page: 1,
    per_page: 50,
    total: 0,
    total_pages: 0,
    has_next: false,
    has_prev: false
  });

  const toursPerPage = 500; // Load all tours in one page to avoid group splitting across pages

  // Load data function with server-side filtering
  const loadData = async (forceRefresh = false, page = 1, filters = {}) => {
    try {
      setLoading(true);
      setError(null);

      // Build filters for the API
      const apiFilters = {
        ...filters
      };

      // Build group filters matching tour filters
      const groupFilters = {};
      if (filters.start_date && filters.end_date) {
        groupFilters.start_date = filters.start_date;
        groupFilters.end_date = filters.end_date;
      } else if (filters.upcoming) groupFilters.upcoming = 'true';
      else if (filters.past) groupFilters.past = 'true';
      else if (filters.date) groupFilters.date = filters.date;
      if (filters.guide_id) groupFilters.guide_id = filters.guide_id;

      const [toursResponse, guidesData, groupsResponse] = await Promise.all([
        mysqlDB.fetchTours(forceRefresh, page, toursPerPage, apiFilters),
        mysqlDB.fetchGuides(),
        tourGroupsAPI.list(groupFilters).catch(() => ({ data: [] }))
      ]);

      // Handle paginated response
      if (toursResponse && toursResponse.data) {
        setTours(toursResponse.data || []);
        setPagination(toursResponse.pagination);
      } else {
        // Fallback for non-paginated response (backward compatibility)
        setTours(toursResponse || []);
      }

      // Handle paginated response - extract data array
      setGuides(Array.isArray(guidesData) ? guidesData : (guidesData?.data || []));

      // Set tour groups
      setTourGroups(groupsResponse?.data || []);

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
    if (showDateRange && rangeStartDate && rangeEndDate) {
      filters.start_date = rangeStartDate;
      filters.end_date = rangeEndDate;
    } else if (showPast) {
      filters.past = true; // Show past 40 days
    } else if (showUpcoming) {
      filters.upcoming = true;
    } else if (filterDate) {
      filters.date = format(filterDate, 'yyyy-MM-dd');
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
    // Skip fetching if date range mode is active but range is incomplete
    if (showDateRange && (!rangeStartDate || !rangeEndDate)) return;
    setCurrentPage(1); // Reset to page 1 when filters change
    loadData(false, 1, getCurrentFilters());
  }, [filterDate, showUpcoming, showPast, showDateRange, rangeStartDate, rangeEndDate, selectedGuideId]);

  // Build a Set of tour IDs that belong to groups (for filtering ungrouped tours)
  const groupedTourIds = useMemo(() => {
    const ids = new Set();
    tourGroups.forEach(g => {
      (g.tours || []).forEach(t => ids.add(t.id));
    });
    return ids;
  }, [tourGroups]);

  // Build a map of group_id -> group for quick lookup
  const groupById = useMemo(() => {
    const map = {};
    tourGroups.forEach(g => { map[g.id] = g; });
    return map;
  }, [tourGroups]);

  // Memoized filtered and grouped tours by date
  // Note: Date and guide filtering is now done on the server side for efficiency
  const groupedTours = useMemo(() => {
    // Ticket products are now excluded by the backend (products table JOIN in tours.php).
    // Frontend filterToursOnly() kept as import for other consumers but not needed here.
    let filtered = tours;

    // Helper function to get time period
    const getTimePeriod = (time) => {
      const hour = parseInt(time.split(':')[0]);
      if (hour < 12) return 'Morning (6:00 - 11:59)';
      if (hour < 17) return 'Afternoon (12:00 - 16:59)';
      return 'Evening (17:00 - 23:59)';
    };

    // Group tours by date, then by time period
    // Items in each period can be either an ungrouped tour or a tour group
    const grouped = {};

    // Track which group IDs we've already placed
    const placedGroupIds = new Set();

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

      // If tour belongs to a group, insert the group (once) instead
      if (tour.group_id && groupById[tour.group_id]) {
        if (!placedGroupIds.has(tour.group_id)) {
          placedGroupIds.add(tour.group_id);
          grouped[tourDate][timePeriod].push({
            _isGroup: true,
            group: groupById[tour.group_id],
            _sortTime: tourTime,
            _sortDate: tourDate
          });
        }
      } else if (!groupedTourIds.has(tour.id)) {
        // Ungrouped tour — render as individual row
        grouped[tourDate][timePeriod].push(tour);
      }
    });

    // Sort items within each time period by time
    Object.keys(grouped).forEach(date => {
      Object.keys(grouped[date]).forEach(period => {
        grouped[date][period].sort((a, b) => {
          const timeA = a._isGroup ? a._sortTime : getBookingTime(a);
          const timeB = b._isGroup ? b._sortTime : getBookingTime(b);
          const dateA2 = a._isGroup ? a._sortDate : getBookingDate(a);
          const dateB2 = b._isGroup ? b._sortDate : getBookingDate(b);
          const dateTimeA = new Date(`${dateA2}T${timeA}`);
          const dateTimeB = new Date(`${dateB2}T${timeB}`);
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
          items: grouped[date][period] // renamed from 'tours' to 'items' — can be tour or group
        }))
    }));
  }, [tours, tourGroups, groupedTourIds, groupById]); // Also depends on groups now

  // Calculate total tours and participants from grouped data
  // Groups count as 1 tour in the summary
  const totalData = useMemo(() => {
    let itemCount = 0;
    let totalParticipants = 0;

    groupedTours.forEach(group => {
      group.periods.forEach(periodGroup => {
        periodGroup.items.forEach(item => {
          if (item._isGroup) {
            itemCount += 1; // Group = 1 tour for counting
            totalParticipants += item.group.total_pax || 0;
          } else {
            itemCount += 1;
            totalParticipants += getParticipantCount(item);
          }
        });
      });
    });

    return {
      totalTours: itemCount,
      totalParticipants
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
      setError(null);
      setSuccess('Notes saved successfully!');
      setTimeout(() => setSuccess(null), 4000);
    } catch (error) {
      console.error('Error saving notes:', error);
      setSuccess(null);
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
    const guide = guides.find(g => g.id === parseInt(guideId));
    const guideName = guide?.name || 'Guide';
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
      setError(null);
      setSuccess(`Guide "${guideName}" assigned successfully!`);
      setTimeout(() => setSuccess(null), 4000);
    } catch (error) {
      console.error('Error saving guide assignment:', error);
      setSuccess(null);
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
    try {
      await mysqlDB.updateTour(tourId, { notes: newNotes });

      // Update local state
      setTours(prev =>
        prev.map(tour =>
          tour.id === tourId ? { ...tour, notes: newNotes } : tour
        )
      );
      setError(null);
      setSuccess('Notes updated successfully!');
      setTimeout(() => setSuccess(null), 4000);
    } catch (error) {
      console.error('Error updating notes:', error);
      setSuccess(null);
      setError('Failed to update notes');
    }
  };

  // Auto-group handler
  const handleAutoGroup = async () => {
    setAutoGrouping(true);
    try {
      const result = await tourGroupsAPI.autoGroup();
      const msg = result.groups_created > 0
        ? `Auto-grouped: ${result.groups_created} groups created, ${result.tours_grouped} tours grouped`
        : 'No new groups to create';
      setSuccess(msg);
      setTimeout(() => setSuccess(null), 5000);
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError('Auto-group failed: ' + (err.response?.data?.error || err.message));
    } finally {
      setAutoGrouping(false);
    }
  };

  // === Drag-and-Drop Handlers ===
  const handleDragStart = (e, id, type) => {
    // type: 'tour' or 'group'
    setDragState(prev => ({ ...prev, draggedId: id, draggedType: type }));
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', JSON.stringify({ id, type }));
    // Reduce opacity of dragged element
    requestAnimationFrame(() => {
      e.target.style.opacity = '0.5';
    });
  };

  const handleDragEnd = (e) => {
    e.target.style.opacity = '1';
    setDragState({ draggedId: null, draggedType: null, overTargetId: null, overTargetType: null });
  };

  const handleDragOver = (e, targetId, targetType) => {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (dragState.overTargetId !== targetId || dragState.overTargetType !== targetType) {
      setDragState(prev => ({ ...prev, overTargetId: targetId, overTargetType: targetType }));
    }
  };

  const handleDragLeave = (e) => {
    // Only clear if we actually left the target (not just entered a child)
    if (!e.currentTarget.contains(e.relatedTarget)) {
      setDragState(prev => ({ ...prev, overTargetId: null, overTargetType: null }));
    }
  };

  const handleDrop = async (e, targetId, targetType) => {
    e.preventDefault();
    setDragState({ draggedId: null, draggedType: null, overTargetId: null, overTargetType: null });

    let dragData;
    try {
      dragData = JSON.parse(e.dataTransfer.getData('text/plain'));
    } catch {
      return;
    }

    const { id: draggedId, type: draggedType } = dragData;

    // Don't drop on self
    if (draggedId === targetId && draggedType === targetType) return;

    // Determine what tour IDs to merge
    let tourIdsToMerge = [];

    if (draggedType === 'tour' && targetType === 'tour') {
      // Tour onto tour — merge both
      tourIdsToMerge = [draggedId, targetId];
    } else if (draggedType === 'tour' && targetType === 'group') {
      // Tour onto group — add tour to existing group tours
      const targetGroup = tourGroups.find(g => g.id === targetId);
      if (!targetGroup) return;
      const existingTourIds = (targetGroup.tours || []).map(t => t.id);
      tourIdsToMerge = [...existingTourIds, draggedId];
    } else if (draggedType === 'group' && targetType === 'tour') {
      // Group onto tour — add tour to dragged group
      const draggedGroup = tourGroups.find(g => g.id === draggedId);
      if (!draggedGroup) return;
      const existingTourIds = (draggedGroup.tours || []).map(t => t.id);
      tourIdsToMerge = [...existingTourIds, targetId];
    } else if (draggedType === 'group' && targetType === 'group') {
      // Group onto group — merge both groups' tours
      const draggedGroup = tourGroups.find(g => g.id === draggedId);
      const targetGroup = tourGroups.find(g => g.id === targetId);
      if (!draggedGroup || !targetGroup) return;
      const draggedTourIds = (draggedGroup.tours || []).map(t => t.id);
      const targetTourIds = (targetGroup.tours || []).map(t => t.id);
      tourIdsToMerge = [...targetTourIds, ...draggedTourIds];
    }

    if (tourIdsToMerge.length < 2) return;

    // Validate PAX <= 9
    const totalPax = tourIdsToMerge.reduce((sum, tid) => {
      // Check in tours list
      const tour = tours.find(t => t.id === tid);
      if (tour) return sum + (parseInt(tour.participants) || getParticipantCount(tour));
      // Check in group tours
      for (const g of tourGroups) {
        const gt = (g.tours || []).find(t => t.id === tid);
        if (gt) return sum + (parseInt(gt.participants) || 1);
      }
      return sum + 1;
    }, 0);

    if (totalPax > 9) {
      setError(`Cannot merge: total PAX (${totalPax}) exceeds maximum of 9`);
      setTimeout(() => setError(null), 5000);
      return;
    }

    try {
      await tourGroupsAPI.manualMerge(tourIdsToMerge);
      setSuccess('Tours merged successfully');
      setTimeout(() => setSuccess(null), 4000);
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError('Merge failed: ' + (err.response?.data?.error || err.message));
    }
  };

  // === Mobile Selection Mode Handlers ===
  const toggleSelectionMode = () => {
    setSelectionMode(prev => !prev);
    setSelectedItems([]);
  };

  const handleToggleSelect = (id, type) => {
    setSelectedItems(prev => {
      const existing = prev.find(s => s.id === id && s.type === type);
      if (existing) {
        return prev.filter(s => !(s.id === id && s.type === type));
      }
      return [...prev, { id, type }];
    });
  };

  const handleMobileAssignGuide = async (guideId) => {
    if (selectedItems.length === 0) return;
    try {
      for (const item of selectedItems) {
        if (item.type === 'tour') {
          await mysqlDB.updateTour(item.id, { guide_id: guideId });
        } else if (item.type === 'group') {
          await tourGroupsAPI.update(item.id, { guide_id: guideId || null });
        }
      }
      const guideName = guides.find(g => g.id === parseInt(guideId))?.name || 'None';
      setSuccess(`Guide "${guideName}" assigned to ${selectedItems.length} item(s)`);
      setTimeout(() => setSuccess(null), 4000);
      setSelectionMode(false);
      setSelectedItems([]);
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError('Failed to assign guide: ' + err.message);
    }
  };

  const handleMobileMerge = async () => {
    // Collect all tour IDs to merge
    let tourIdsToMerge = [];
    for (const item of selectedItems) {
      if (item.type === 'tour') {
        tourIdsToMerge.push(item.id);
      } else if (item.type === 'group') {
        const group = tourGroups.find(g => g.id === item.id);
        if (group) {
          tourIdsToMerge.push(...(group.tours || []).map(t => t.id));
        }
      }
    }

    if (tourIdsToMerge.length < 2) {
      setError('Need at least 2 tours to merge');
      return;
    }

    // Validate PAX <= 9
    const totalPax = tourIdsToMerge.reduce((sum, tid) => {
      const tour = tours.find(t => t.id === tid);
      if (tour) return sum + (parseInt(tour.participants) || getParticipantCount(tour));
      for (const g of tourGroups) {
        const gt = (g.tours || []).find(t => t.id === tid);
        if (gt) return sum + (parseInt(gt.participants) || 1);
      }
      return sum + 1;
    }, 0);

    if (totalPax > 9) {
      setError(`Cannot merge: total PAX (${totalPax}) exceeds maximum of 9`);
      setTimeout(() => setError(null), 5000);
      return;
    }

    try {
      await tourGroupsAPI.manualMerge(tourIdsToMerge);
      setSuccess('Tours merged successfully');
      setTimeout(() => setSuccess(null), 4000);
      setSelectionMode(false);
      setSelectedItems([]);
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError('Merge failed: ' + (err.response?.data?.error || err.message));
    }
  };

  // Helper for mobile — guide edit callbacks passed to cards
  const handleGuideEditStart = (tourId) => {
    setEditingGuides(prev => ({ ...prev, [tourId]: tours.find(t => t.id === tourId)?.guide_id || '' }));
  };
  const handleGuideEditCancel = (tourId) => {
    setEditingGuides(prev => { const s = { ...prev }; delete s[tourId]; return s; });
  };
  const handleNotesEditStart = (tourId) => {
    setEditingNotes(prev => ({ ...prev, [tourId]: tours.find(t => t.id === tourId)?.notes || '' }));
  };
  const handleNotesEditCancel = (tourId) => {
    setEditingNotes(prev => { const s = { ...prev }; delete s[tourId]; return s; });
  };

  // Download unassigned tours report as .txt
  const downloadUnassignedReport = () => {
    // Determine current filter label
    let filterLabel = '';
    if (showDateRange && rangeStartDate && rangeEndDate) {
      filterLabel = `${rangeStartDate} to ${rangeEndDate}`;
    } else if (showPast) {
      filterLabel = 'Past 40 Days';
    } else if (showUpcoming) {
      filterLabel = 'Upcoming';
    } else {
      filterLabel = filterDate ? format(filterDate, 'yyyy-MM-dd') === format(new Date(), 'yyyy-MM-dd') ? 'Today' : format(filterDate, 'dd MMM yyyy') : 'Today';
    }

    const now = new Date();
    const generated = format(now, 'dd MMM yyyy, HH:mm');

    const lines = [];
    lines.push('UNASSIGNED TOURS REPORT');
    lines.push(`Generated: ${generated}`);
    lines.push(`Filter: ${filterLabel}`);
    lines.push('========================');
    lines.push('');

    // Extract location from tour title by keyword matching
    const getLocation = (title) => {
      if (!title) return 'Florence';
      const t = title.toLowerCase();
      if (t.includes('uffizi') && t.includes('accademia')) return 'Uffizi + Accademia';
      if (t.includes('uffizi')) return 'Uffizi';
      if (t.includes('accademia')) return 'Accademia';
      if (t.includes('duomo') || t.includes('cathedral')) return 'Duomo';
      if (t.includes('pitti')) return 'Pitti';
      if (t.includes('boboli')) return 'Boboli';
      if (t.includes('palazzo vecchio')) return 'Palazzo Vecchio';
      if (t.includes('san lorenzo') || t.includes('medici chapel')) return 'San Lorenzo';
      if (t.includes('santa croce')) return 'Santa Croce';
      if (t.includes('ponte vecchio')) return 'Ponte Vecchio';
      if (t.includes('bargello')) return 'Bargello';
      if (t.includes('vasari')) return 'Vasari Corridor';
      return 'Florence';
    };

    let totalUnassigned = 0;

    groupedTours.forEach(dateGroup => {
      const unassignedItems = [];

      dateGroup.periods.forEach(periodGroup => {
        periodGroup.items.forEach(item => {
          if (item._isGroup) {
            const g = item.group;
            if (!g.guide_id) {
              const time = g.group_time ? g.group_time.substring(0, 5) : '00:00';
              const location = getLocation(g.display_name);
              unassignedItems.push({ time, location });
              totalUnassigned++;
            }
          } else {
            if (item.cancelled) return;
            if (!item.guide_id) {
              const time = getBookingTime(item).substring(0, 5);
              const location = getLocation(item.title);
              unassignedItems.push({ time, location });
              totalUnassigned++;
            }
          }
        });
      });

      if (unassignedItems.length > 0) {
        const dateObj = new Date(dateGroup.date + 'T00:00:00');
        const dateLabel = format(dateObj, 'EEEE, dd MMMM yyyy');
        lines.push(`--- ${dateLabel} ---`);
        lines.push('');
        unassignedItems
          .sort((a, b) => a.time.localeCompare(b.time))
          .forEach(entry => {
            lines.push(`  ${entry.time}  ${entry.location}`);
          });
        lines.push('');
      }
    });

    if (totalUnassigned === 0) {
      lines.push('No unassigned tours found.');
      lines.push('');
    }

    lines.push('========================');
    lines.push(`Total: ${totalUnassigned} unassigned tours`);

    const content = lines.join('\n');
    const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `unassigned_tours_${format(now, 'yyyyMMdd_HHmm')}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-lg text-stone-600">Loading tours...</div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-stone-50 p-3 md:p-6">
      <div className="max-w-7xl mx-auto space-y-4 md:space-y-6">
        {/* Success Alert */}
        {success && (
          <div className="bg-green-50 border-l-4 border-green-500 rounded-lg p-3 md:p-4 shadow-sm">
            <div className="flex items-center justify-between">
              <div className="flex items-center min-w-0">
                <div className="w-8 h-8 md:w-10 md:h-10 bg-green-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                  <svg className="h-4 w-4 md:h-5 md:w-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div className="min-w-0">
                  <p className="text-sm text-green-700 truncate">{success}</p>
                </div>
              </div>
              <button
                onClick={() => setSuccess(null)}
                className="text-green-500 hover:text-green-700 p-1 flex-shrink-0 ml-2"
              >
                <FiX className="h-5 w-5" />
              </button>
            </div>
          </div>
        )}

        {/* Error Alert */}
        {error && (
          <div className="bg-terracotta-50 border-l-4 border-terracotta-500 rounded-lg p-3 md:p-4 shadow-sm">
            <div className="flex items-center justify-between">
              <div className="flex items-center min-w-0">
                <div className="w-8 h-8 md:w-10 md:h-10 bg-terracotta-100 rounded-lg flex items-center justify-center mr-3 flex-shrink-0">
                  <svg className="h-4 w-4 md:h-5 md:w-5 text-terracotta-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                </div>
                <div className="min-w-0">
                  <p className="text-sm text-terracotta-700 truncate">{error}</p>
                </div>
              </div>
              <button
                onClick={() => setError(null)}
                className="text-terracotta-500 hover:text-terracotta-700 p-1 flex-shrink-0 ml-2"
              >
                <FiX className="h-5 w-5" />
              </button>
            </div>
          </div>
        )}

        {/* Header — responsive */}
        <div className="flex flex-col gap-3 md:flex-row md:justify-between md:items-center">
          <div>
            <h1 className="text-xl md:text-2xl font-bold text-stone-900">Tours Management</h1>
            <p className="text-sm text-stone-600">Manage your Florence tours and bookings</p>
          </div>
          <div className="flex items-center gap-2 flex-wrap">
            {/* Mobile: selection mode toggle */}
            <button
              onClick={toggleSelectionMode}
              className={`md:hidden flex items-center gap-1.5 px-3 py-2 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation ${
                selectionMode
                  ? 'bg-terracotta-500 text-white'
                  : 'bg-stone-200 text-stone-700'
              }`}
            >
              {selectionMode ? <FiCheckSquare className="h-4 w-4" /> : <FiSquare className="h-4 w-4" />}
              {selectionMode ? 'Cancel' : 'Select'}
            </button>
            <Button
              variant="outline"
              onClick={handleAutoGroup}
              disabled={autoGrouping || loading}
              className="flex items-center gap-2 flex-1 md:flex-none justify-center"
            >
              <FiLayers className={`h-4 w-4 ${autoGrouping ? 'animate-spin' : ''}`} />
              <span className="hidden sm:inline">{autoGrouping ? 'Grouping...' : 'Auto-Group'}</span>
              <span className="sm:hidden">{autoGrouping ? '...' : 'Group'}</span>
            </Button>
            <Button
              onClick={handleRefresh}
              disabled={loading}
              className="flex items-center gap-2 flex-1 md:flex-none justify-center"
            >
              <FiRefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
              <span className="hidden sm:inline">{loading ? 'Refreshing...' : 'Refresh'}</span>
              <span className="sm:hidden">{loading ? '...' : 'Refresh'}</span>
            </Button>
          </div>
        </div>

        {/* Filters — responsive */}
        <Card>
          <h3 className="text-base md:text-lg font-semibold mb-3 md:mb-4">Filters</h3>
          <div className="space-y-3 md:space-y-0 md:grid md:grid-cols-2 md:gap-4">
            <div>
              <label className="block text-sm font-medium text-stone-700 mb-1.5 md:mb-2">Filter by Guide</label>
              <select
                value={selectedGuideId}
                onChange={(e) => setSelectedGuideId(e.target.value)}
                className="w-full px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
              >
                <option value="all">All Guides</option>
                {guides.map(guide => (
                  <option key={guide.id} value={guide.id}>{guide.name}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-stone-700 mb-1.5 md:mb-2">
                {showDateRange ? 'Date Range' : 'Select Date'}
              </label>
              {/* Date input(s) */}
              {showDateRange ? (
                <div className="flex gap-2 items-center mb-2">
                  <input
                    type="date"
                    value={rangeStartDate}
                    onChange={(e) => setRangeStartDate(e.target.value)}
                    className="flex-1 min-w-0 px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                  />
                  <span className="text-stone-400 text-sm flex-shrink-0">to</span>
                  <input
                    type="date"
                    value={rangeEndDate}
                    min={rangeStartDate || undefined}
                    onChange={(e) => setRangeEndDate(e.target.value)}
                    className="flex-1 min-w-0 px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                  />
                </div>
              ) : (
                <input
                  type="date"
                  value={filterDate ? format(filterDate, 'yyyy-MM-dd') : ''}
                  onChange={(e) => {
                    setFilterDate(e.target.value ? new Date(e.target.value) : new Date());
                    setShowUpcoming(false);
                    setShowPast(false);
                    setShowDateRange(false);
                  }}
                  className="w-full px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500 mb-2"
                />
              )}
              {/* Period buttons — horizontal scrollable on mobile */}
              <div className="flex gap-2 overflow-x-auto pb-1 -mx-1 px-1 scrollbar-hide mt-2">
                <button
                  onClick={() => {
                    setFilterDate(new Date());
                    setShowUpcoming(false);
                    setShowPast(false);
                    setShowDateRange(false);
                  }}
                  className={`px-3 md:px-4 py-2 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] whitespace-nowrap flex-shrink-0 ${
                    !showUpcoming && !showPast && !showDateRange && filterDate && format(filterDate, 'yyyy-MM-dd') === format(new Date(), 'yyyy-MM-dd')
                      ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                >
                  Today
                </button>
                <button
                  onClick={() => {
                    setShowUpcoming(true);
                    setShowPast(false);
                    setShowDateRange(false);
                  }}
                  className={`px-3 md:px-4 py-2 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] whitespace-nowrap flex-shrink-0 ${
                    showUpcoming && !showPast && !showDateRange
                      ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                  title="Show tours for the next 60 days"
                >
                  Upcoming
                </button>
                <button
                  onClick={() => {
                    setShowPast(true);
                    setShowUpcoming(false);
                    setShowDateRange(false);
                  }}
                  className={`px-3 md:px-4 py-2 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] whitespace-nowrap flex-shrink-0 ${
                    showPast && !showDateRange
                      ? 'bg-amber-600 text-white active:bg-amber-700'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                  title="Show completed tours from past 40 days"
                >
                  Past 40 Days
                </button>
                <button
                  onClick={() => {
                    setShowDateRange(true);
                    setShowUpcoming(false);
                    setShowPast(false);
                  }}
                  className={`px-3 md:px-4 py-2 min-h-[44px] rounded-tuscan text-sm font-medium transition-colors touch-manipulation active:scale-[0.98] whitespace-nowrap flex-shrink-0 ${
                    showDateRange
                      ? 'bg-terracotta-500 text-white active:bg-terracotta-600'
                      : 'bg-stone-200 text-stone-700 hover:bg-stone-300 active:bg-stone-400'
                  }`}
                  title="Select a custom date range"
                >
                  Date Range
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
              const isTodayDate = format(new Date(), 'yyyy-MM-dd') === dateGroup.date;
              const dateParticipants = dateGroup.periods.reduce((total, periodGroup) =>
                total + periodGroup.items.reduce((sum, item) => {
                  if (item._isGroup) return sum + (item.group.total_pax || 0);
                  return sum + getParticipantCount(item);
                }, 0), 0
              );
              const dateTourCount = dateGroup.periods.reduce((total, periodGroup) =>
                total + periodGroup.items.length, 0
              );

              return (
                <div key={dateGroup.date}>
                  {/* ========== Date Header — mobile card / desktop inline ========== */}
                  <div className={`px-4 md:px-6 py-3 md:py-4 rounded-tuscan-lg md:rounded-none md:rounded-t-tuscan-lg border border-stone-200 md:border-b mb-2 md:mb-0 ${isTodayDate ? 'bg-gold-50' : 'bg-stone-50'}`}>
                    <div className="flex flex-col md:flex-row md:justify-between md:items-center gap-1">
                      <div className="flex items-center gap-2 flex-wrap">
                        <h3 className={`text-base md:text-lg font-semibold ${isTodayDate ? 'text-gold-900' : 'text-stone-900'}`}>
                          {format(dateObj, 'EEEE, d MMMM yyyy')}
                        </h3>
                        {isTodayDate && (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gold-100 text-gold-800">
                            Today
                          </span>
                        )}
                      </div>
                      <div className="text-xs md:text-sm text-stone-600">
                        {dateTourCount} tours · {dateParticipants} PAX
                      </div>
                    </div>
                  </div>

                  {/* ========== Desktop table wrapper ========== */}
                  <div className="hidden md:block bg-white border border-stone-200 border-t-0 rounded-b-tuscan-lg shadow-tuscan overflow-hidden">
                  {/* Time Periods — desktop table */}
                  {dateGroup.periods.map((periodGroup, periodIndex) => (
                    <div key={`${dateGroup.date}-${periodGroup.period}`}>
                      {/* Time Period Header */}
                      <div className="bg-stone-100 px-6 py-2 border-b border-stone-200">
                        <div className="flex justify-between items-center">
                          <h4 className="text-sm font-semibold text-stone-700">
                            {periodGroup.period}
                          </h4>
                          <div className="text-xs text-stone-600">
                            {periodGroup.items.length} tours · {periodGroup.items.reduce((sum, item) => {
                              if (item._isGroup) return sum + (item.group.total_pax || 0);
                              return sum + getParticipantCount(item);
                            }, 0)} PAX
                          </div>
                        </div>
                      </div>

                      {/* Tours & Groups List — desktop table */}
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
                            {periodGroup.items.map((item) => {
                          // === Render a TourGroup ===
                          if (item._isGroup) {
                            return (
                              <tr key={`group-${item.group.id}`} className="!border-0">
                                <td colSpan="7" className="p-2">
                                  <TourGroup
                                    group={item.group}
                                    guides={guides}
                                    onRefresh={() => loadData(true, currentPage, getCurrentFilters())}
                                    onError={(msg) => { setError(msg); setTimeout(() => setError(null), 5000); }}
                                    onSuccess={(msg) => { setSuccess(msg); setTimeout(() => setSuccess(null), 4000); }}
                                    onTourClick={(tour) => {
                                      const fullTour = tours.find(t => t.id === tour.id) || tour;
                                      handleRowClick(fullTour);
                                    }}
                                    draggable={true}
                                    onDragStart={(e) => handleDragStart(e, item.group.id, 'group')}
                                    onDragOver={(e) => handleDragOver(e, item.group.id, 'group')}
                                    onDragLeave={handleDragLeave}
                                    onDrop={(e) => handleDrop(e, item.group.id, 'group')}
                                    isDragOver={dragState.overTargetId === item.group.id && dragState.overTargetType === 'group'}
                                  />
                                </td>
                              </tr>
                            );
                          }

                          // === Render an ungrouped tour row ===
                          const tour = item;
                          const guideName = guides.find(g => g.id == tour.guide_id)?.name || 'Unassigned';
                          const isDraggedOver = dragState.overTargetId === tour.id && dragState.overTargetType === 'tour';
                          return (
                            <tr
                              key={tour.id}
                              className={`hover:bg-stone-50 cursor-pointer transition-all ${
                                isDraggedOver ? 'bg-terracotta-50 ring-2 ring-terracotta-200' : ''
                              } ${
                                dragState.draggedId === tour.id && dragState.draggedType === 'tour' ? 'opacity-50' : ''
                              } ${
                                tour.cancelled ? 'bg-red-50' : ''
                              }`}
                              onClick={() => handleRowClick(tour)}
                              draggable={true}
                              onDragStart={(e) => {
                                e.stopPropagation();
                                handleDragStart(e, tour.id, 'tour');
                              }}
                              onDragEnd={handleDragEnd}
                              onDragOver={(e) => {
                                e.stopPropagation();
                                handleDragOver(e, tour.id, 'tour');
                              }}
                              onDragLeave={(e) => {
                                e.stopPropagation();
                                handleDragLeave(e);
                              }}
                              onDrop={(e) => {
                                e.stopPropagation();
                                handleDrop(e, tour.id, 'tour');
                              }}
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
                                  <ParticipantNamesCompact tour={tour} />
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
                                        <span className="text-lg font-bold">&times;</span>
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
                                          <span className="text-lg font-bold">&times;</span>
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
                                      {tour.rescheduled && !tour.cancelled && tour.original_date && tour.original_time &&
                                       (tour.original_date !== tour.date || tour.original_time !== tour.time) && (
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

                  {/* ========== Mobile cards ========== */}
                  <div className="md:hidden space-y-2">
                  {dateGroup.periods.map((periodGroup) => (
                    <div key={`mobile-${dateGroup.date}-${periodGroup.period}`}>
                      {/* Time Period divider — mobile */}
                      <div className="flex items-center gap-2 py-2 px-1">
                        <div className="h-px flex-1 bg-stone-300" />
                        <span className="text-xs font-semibold text-stone-500 uppercase whitespace-nowrap">
                          {periodGroup.period.split(' (')[0]}
                        </span>
                        <span className="text-xs text-stone-400">
                          {periodGroup.items.length} · {periodGroup.items.reduce((sum, item) => {
                            if (item._isGroup) return sum + (item.group.total_pax || 0);
                            return sum + getParticipantCount(item);
                          }, 0)} PAX
                        </span>
                        <div className="h-px flex-1 bg-stone-300" />
                      </div>

                      {/* Mobile cards */}
                      <div className="space-y-2">
                        {periodGroup.items.map((item) => {
                          if (item._isGroup) {
                            return (
                              <TourGroupCardMobile
                                key={`mobile-group-${item.group.id}`}
                                group={item.group}
                                guides={guides}
                                onRefresh={() => loadData(true, currentPage, getCurrentFilters())}
                                onError={(msg) => { setError(msg); setTimeout(() => setError(null), 5000); }}
                                onSuccess={(msg) => { setSuccess(msg); setTimeout(() => setSuccess(null), 4000); }}
                                onTourClick={(tour) => {
                                  const fullTour = tours.find(t => t.id === tour.id) || tour;
                                  handleRowClick(fullTour);
                                }}
                                selectionMode={selectionMode}
                                isSelected={selectedItems.some(s => s.id === item.group.id && s.type === 'group')}
                                onToggleSelect={handleToggleSelect}
                              />
                            );
                          }

                          const tour = item;
                          const guideName = guides.find(g => g.id == tour.guide_id)?.name || 'Unassigned';

                          return (
                            <TourCardMobile
                              key={`mobile-tour-${tour.id}`}
                              tour={tour}
                              guideName={guideName}
                              guides={guides}
                              tourTime={getBookingTime(tour)}
                              tourLanguage={getTourLanguage(tour)}
                              participantCount={getParticipantCount(tour)}
                              editingGuides={editingGuides}
                              editingNotes={editingNotes}
                              savingChanges={savingChanges}
                              onGuideChange={handleGuideChange}
                              onGuideSave={saveGuideAssignment}
                              onGuideEditStart={handleGuideEditStart}
                              onGuideEditCancel={handleGuideEditCancel}
                              onNotesChange={handleNotesChange}
                              onNotesSave={saveNotes}
                              onNotesEditStart={handleNotesEditStart}
                              onNotesEditCancel={handleNotesEditCancel}
                              onCardClick={handleRowClick}
                              selectionMode={selectionMode}
                              isSelected={selectedItems.some(s => s.id === tour.id && s.type === 'tour')}
                              onToggleSelect={handleToggleSelect}
                              dragState={dragState}
                            />
                          );
                        })}
                      </div>
                    </div>
                  ))}
                  </div>
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
                    <span className="font-medium">{pagination.total}</span> bookings
                    {totalData.totalTours !== pagination.total && (
                      <> ({totalData.totalTours} tour {totalData.totalTours === 1 ? 'unit' : 'units'})</>
                    )}
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

          {/* Summary — responsive */}
          {groupedTours.length > 0 && (
            <Card>
              <div className="px-4 md:px-6 py-3 md:py-4">
                <div className="flex flex-col md:flex-row md:justify-between md:items-center gap-2 text-center md:text-left">
                  <h3 className="text-base md:text-lg font-semibold text-stone-900">Summary</h3>
                  <div className="flex flex-col md:flex-row items-center gap-2">
                    <div className="text-sm text-stone-600">
                      Total: {totalData.totalTours} tours · {totalData.totalParticipants} PAX
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={downloadUnassignedReport}
                      icon={FiDownload}
                    >
                      <span className="hidden md:inline">Unassigned Report</span>
                      <span className="md:hidden">Report</span>
                    </Button>
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

      {/* Mobile Merge/Assign Floating Bottom Bar */}
      {selectionMode && selectedItems.length > 0 && (
        <div className="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-white border-t-2 border-terracotta-400 shadow-tuscan-xl px-4 py-3 animate-slide-in-bottom safe-area-inset-bottom">
          <div className="flex items-center gap-2 mb-2">
            <span className="text-sm font-medium text-stone-700">
              {selectedItems.length} selected
            </span>
            <button
              onClick={() => setSelectedItems([])}
              className="text-xs text-stone-500 underline ml-auto"
            >
              Clear
            </button>
          </div>
          <div className="flex gap-2">
            {/* Merge button */}
            {selectedItems.length >= 2 && (
              <button
                onClick={handleMobileMerge}
                className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 min-h-[44px] bg-terracotta-500 text-white rounded-tuscan font-medium text-sm touch-manipulation active:bg-terracotta-600 transition-colors"
              >
                <FiLayers size={16} />
                Merge Selected
              </button>
            )}
            {/* Assign guide dropdown */}
            <div className="flex-1">
              <select
                defaultValue=""
                onChange={(e) => {
                  if (e.target.value) {
                    handleMobileAssignGuide(e.target.value);
                    e.target.value = '';
                  }
                }}
                className="w-full px-3 py-2.5 min-h-[44px] border border-stone-300 rounded-tuscan text-sm font-medium text-stone-700 bg-white focus:outline-none focus:ring-2 focus:ring-terracotta-500 touch-manipulation"
              >
                <option value="" disabled>Assign Guide...</option>
                {guides.map(guide => (
                  <option key={guide.id} value={guide.id}>{guide.name}</option>
                ))}
              </select>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Tours;