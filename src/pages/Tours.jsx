import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import { FiPlus, FiRefreshCw, FiSave, FiX, FiLayers, FiCheckSquare, FiSquare, FiUsers as FiUsersIcon, FiDownload, FiMessageCircle, FiCopy, FiExternalLink, FiCheck } from 'react-icons/fi';
import { format } from 'date-fns';
import mysqlDB, { tourGroupsAPI, getOpenGuideRequests } from '../services/mysqlDB';
import bokunAutoSync from '../services/bokunAutoSync';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import BookingDetailsModal from '../components/BookingDetailsModal';
import AskGuideModal from '../components/AskGuideModal';
import ManualTourModal from '../components/ManualTourModal';
import TourGroup from '../components/TourGroup';
import TourCardMobile from '../components/TourCardMobile';
import TourGroupCardMobile from '../components/TourGroupCardMobile';
import DateFilter from '../components/DateFilter';
import { useToast } from '../components/Toast/ToastProvider';
import { useAuth } from '../contexts/AuthContext';
import { isTicketProduct, filterToursOnly } from '../utils/tourFilters';
import { getMaxPax, countActivePax, tourCategory, getPaxBreakdown, formatBreakdown } from '../utils/tourCapacity';
import { isGuidePaid } from '../utils/paymentBadges';
import { buildUnassignedReportText } from '../utils/unassignedReport';

// Fixed display order for the Summary category tiles. Buckets with 0 tours are hidden.
const CATEGORY_ORDER = ['Combo', 'Uffizi', 'Accademia', 'Pitti', 'Other', 'Private Combo', 'Private Uffizi', 'Private Accademia', 'Private Pitti', 'Private (other)'];
const PRIVATE_CATEGORIES = new Set(['Private Combo', 'Private Uffizi', 'Private Accademia', 'Private Pitti', 'Private (other)']);

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
    // Step 4.2: list rows (view=list) carry the server-derived count, no bokun_data
    if (tour.total_participants != null) {
      return parseInt(tour.total_participants) || 1;
    }
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

// Count stats for a list of items (each item is a group {_isGroup} or a standalone tour).
//   activeTours    = groups + standalone non-cancelled tours
//   cancelledCount = standalone cancelled tours
//   pax            = active PAX (groups exclude cancelled bookings; cancelled standalone = 0)
const computeItemStats = (items) => {
  let activeTours = 0;
  let cancelledCount = 0;
  let pax = 0;
  (items || []).forEach((item) => {
    if (item._isGroup) {
      activeTours += 1;
      pax += countActivePax(item.group.tours);
    } else if (item.cancelled) {
      cancelledCount += 1;
    } else {
      activeTours += 1;
      pax += getParticipantCount(item);
    }
  });
  return { activeTours, cancelledCount, pax };
};

// Renders "{activeTours} tours · {pax} PAX" with a muted "· {N} cancelled" only when N > 0.
const CountLabel = ({ activeTours, cancelledCount, pax }) => (
  <>
    {activeTours} tours · {pax} PAX
    {cancelledCount > 0 && (
      <span className="text-stone-400"> · {cancelledCount} cancelled</span>
    )}
  </>
);

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
    // Step 4.2: list rows (view=list) carry the server-derived startTimeStr
    if (tour.start_time_str) {
      return tour.start_time_str;
    }
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

// Step 6.1: the language is computed ONCE at sync time and stored on the row (tours.language,
// canonical spelling), and the list endpoint returns it - so the page just reads it. The old
// browser-side extractor that re-parsed bokun_data on every render is gone; since 4.2 the list
// does not even carry bokun_data, so its fallbacks could never fire anyway.
const getTourLanguage = (tour) => (tour && tour.language ? tour.language : null);

// Read an optional ?date=YYYY-MM-DD deep link (e.g. from the Dashboard "needs a guide" alert).
// Parse from parts (not new Date('YYYY-MM-DD')) to avoid a UTC off-by-one shifting the day.
const getInitialDateParam = () => {
  try {
    const raw = new URLSearchParams(window.location.search).get('date');
    if (raw && /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
      const [y, m, d] = raw.split('-').map(Number);
      const parsed = new Date(y, m - 1, d);
      if (!isNaN(parsed.getTime())) return parsed;
    }
  } catch (e) {
    /* ignore malformed URL/param */
  }
  return null;
};

const Tours = () => {
  // If the page was opened with ?date=YYYY-MM-DD, start in single-date mode on that date.
  const initialDateParam = getInitialDateParam();

  const [tours, setTours] = useState([]);
  const [tourGroups, setTourGroups] = useState([]);
  // Step 5.2: departures without a guide for the current filter - the server's number (same query as
  // the unassigned report). null = not shown (a guide filter is active, or the count could not be read).
  const [needGuideCount, setNeedGuideCount] = useState(null);
  // Step 5.2: set when the list could not be loaded completely - shown instead of a misleading list.
  const [loadError, setLoadError] = useState(null);
  const [guides, setGuides] = useState([]);
  const [selectedGuideId, setSelectedGuideId] = useState('all');
  // Step 6.1: filter by the language the tour is given in. 'Unknown' = the rows that have none.
  const [selectedLanguage, setSelectedLanguage] = useState('all');
  const [languageOptions, setLanguageOptions] = useState([]);
  const [filterDate, setFilterDate] = useState(initialDateParam || new Date()); // Default to today (or ?date= deep link)
  const [currentPage, setCurrentPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const toast = useToast();
  // Page/action feedback now surfaces as toasts (visible regardless of scroll).
  // Thin wrappers keep the existing setError/setSuccess call sites unchanged;
  // clearing calls like setError(null) become harmless no-ops (toasts self-dismiss).
  const setError = (msg) => { if (msg) toast.error(msg); };
  const setSuccess = (msg) => { if (msg) toast.success(msg); };
  const [editingNotes, setEditingNotes] = useState({});
  const [editingGuides, setEditingGuides] = useState({});
  const [editingLanguages, setEditingLanguages] = useState({});
  const [savingChanges, setSavingChanges] = useState({});
  const [showUpcoming, setShowUpcoming] = useState(initialDateParam ? false : true); // Single-date mode when ?date= present, else Upcoming
  const [showPast, setShowPast] = useState(false); // Show past 40 days for payment verification
  const [showDateRange, setShowDateRange] = useState(false); // Custom date range mode
  const [rangeStartDate, setRangeStartDate] = useState(''); // YYYY-MM-DD string
  const [rangeEndDate, setRangeEndDate] = useState(''); // YYYY-MM-DD string
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedTour, setSelectedTour] = useState(null);
  // "Ask a guide" (availability request) flow
  const [askTour, setAskTour] = useState(null);          // tour being asked about
  const [openRequests, setOpenRequests] = useState({});  // tour_id -> { status, guide_name } (persistent badges)
  const [autoGrouping, setAutoGrouping] = useState(false);
  // Step 6.4: departures typed in by hand (a channel listing not connected to Bokun).
  const { isAdmin } = useAuth();
  const [manualModalOpen, setManualModalOpen] = useState(false);
  const [manualEditTour, setManualEditTour] = useState(null);
  const [manualSaving, setManualSaving] = useState(false);
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
      // Step 4.2: light rows — the details modal fetches the full record on open
      const apiFilters = {
        ...filters,
        view: 'list'
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

      // Step 5.2: ALL groups for the same date filter as the tours (paged through on the client,
      // 100 per request, total checked). A failed or incomplete group request is an error - it is
      // no longer swallowed, because bookings would then silently render as loose, ungrouped rows.
      const { guide_id: _guideFilter, view: _view, ...countFilters } = apiFilters;
      const [toursResponse, guidesData, groupsResponse, unassignedTotal] = await Promise.all([
        mysqlDB.fetchTours(forceRefresh, page, toursPerPage, apiFilters),
        mysqlDB.getAllGuides(),
        tourGroupsAPI.listAll(groupFilters),
        // the banner number: the unassigned report's own total (null = do not show a number)
        filters.guide_id ? Promise.resolve(null) : mysqlDB.getUnassignedCount(countFilters).catch(() => null)
      ]);
      setLoadError(null);
      setNeedGuideCount(unassignedTotal);

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
      // Step 5.2: never show a half-loaded list (e.g. tours without their groups)
      setTours([]);
      setTourGroups([]);
      setNeedGuideCount(null);
      setLoadError(err.message || 'Could not load the tours');
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
    if (selectedLanguage !== 'all') {
      filters.language = selectedLanguage;
    }
    return filters;
  };

  // Handle refresh button click
  // Reloads from the database immediately, then triggers a Bokun sync in the
  // background — when the sync finishes, the 'florence:bookings-updated' event
  // reloads the list again so brand-new bookings appear without re-login.
  const handleRefresh = async () => {
    await loadData(true, currentPage, getCurrentFilters());
    bokunAutoSync.syncNow().catch((err) => {
      console.warn('Background Bokun sync failed:', err?.message || err);
    });
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
  }, [filterDate, showUpcoming, showPast, showDateRange, rangeStartDate, rangeEndDate, selectedGuideId, selectedLanguage]);

  // Step 6.1: the dropdown only offers languages that exist in the range in view. The language
  // filter itself is excluded from the question, so choosing one never empties the list.
  useEffect(() => {
    if (showDateRange && (!rangeStartDate || !rangeEndDate)) return;
    const { language: _lang, guide_id: _guide, ...rangeFilters } = getCurrentFilters();
    mysqlDB.getTourLanguages(rangeFilters)
      .then((res) => setLanguageOptions(Array.isArray(res?.data) ? res.data : []))
      .catch(() => setLanguageOptions([]));
  }, [filterDate, showUpcoming, showPast, showDateRange, rangeStartDate, rangeEndDate]);

  // ---- "Ask a guide" availability-request flow ----------------------------
  // NB: declared BEFORE the mount effect below — that effect lists loadOpenRequests
  // in its dependency array, which is evaluated during render, so the const must
  // exist by then (avoids a temporal-dead-zone ReferenceError).
  const openAskGuide = (tour) => setAskTour(tour);
  const closeAskGuide = () => setAskTour(null);

  // Load open (pending/declined) requests and map them per tour for persistent badges.
  const loadOpenRequests = useCallback(async () => {
    try {
      const res = await getOpenGuideRequests();
      const list = res && res.data ? res.data : [];
      const map = {};
      for (const r of list) {
        const existing = map[r.tour_id];
        // Prefer a 'pending' over a 'declined' when both exist for the same tour
        if (!existing || (existing.status === 'declined' && r.status === 'pending')) {
          map[r.tour_id] = { status: r.status, guide_name: r.guide_name };
        }
      }
      setOpenRequests(map);
    } catch (e) {
      console.warn('Failed to load open guide requests:', e);
    }
  }, []);

  // Refresh badges after a request is created so it persists across reloads
  const handleGuideRequested = () => {
    loadOpenRequests();
  };

  // Load open guide requests once on mount (persistent request-status badges)
  useEffect(() => {
    loadOpenRequests();
  }, [loadOpenRequests]);

  // Auto-reload when a Bokun sync brings in new/changed bookings,
  // so the list stays current without logging out and back in
  useEffect(() => {
    const onBookingsUpdated = () => {
      if (showDateRange && (!rangeStartDate || !rangeEndDate)) return;
      console.log('[Tours] Bookings updated by Bokun sync — reloading tour list');
      loadData(true, currentPage, getCurrentFilters());
    };
    window.addEventListener('florence:bookings-updated', onBookingsUpdated);
    return () => window.removeEventListener('florence:bookings-updated', onBookingsUpdated);
  }, [filterDate, showUpcoming, showPast, showDateRange, rangeStartDate, rangeEndDate, selectedGuideId, currentPage]);

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
    let totalTours = 0;
    let totalCancelled = 0;
    let totalParticipants = 0;

    groupedTours.forEach(group => {
      group.periods.forEach(periodGroup => {
        const { activeTours, cancelledCount, pax } = computeItemStats(periodGroup.items);
        totalTours += activeTours; // group or standalone non-cancelled tour
        totalCancelled += cancelledCount;
        totalParticipants += pax;
      });
    });

    return {
      totalTours,
      totalCancelled,
      totalParticipants
    };
  }, [groupedTours]);

  // Category breakdown over the currently displayed (filtered) tours — counts each
  // ACTIVE departure once. Groups are always shared/non-private. Also tallies how
  // many active departures still have no guide.
  const categorySummary = useMemo(() => {
    const buckets = {}; // key -> { tours, pax }
    const add = (key, pax) => {
      if (!buckets[key]) buckets[key] = { tours: 0, pax: 0 };
      buckets[key].tours += 1;
      buckets[key].pax += pax;
    };

    groupedTours.forEach(dateGroup => {
      dateGroup.periods.forEach(periodGroup => {
        periodGroup.items.forEach(item => {
          if (item._isGroup) {
            const g = item.group;
            add(tourCategory(g.display_name), countActivePax(g.tours));
          } else {
            if (item.cancelled) return; // skip cancelled standalone tours entirely
            const cat = tourCategory(item.title);
            let key = cat;
            if (item.is_private) {
              // Private mirrors the shared categories exactly; only 'Other' -> 'Private (other)'.
              key = cat === 'Other' ? 'Private (other)' : `Private ${cat}`;
            }
            add(key, getParticipantCount(item));
          }
        });
      });
    });

    // Step 5.2: "N tours still need a guide" is NOT counted here any more - see needGuideCount.
    return { buckets };
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

    // Applies the assignment; pass force=true to bypass the double-booking guard.
    // Selecting "Unassigned" sends an explicit guide_id: null so the backend clears it.
    const applyAssignment = async (force = false) => {
      const newGuideId = guideId || null;
      await mysqlDB.updateTour(tourId, force ? { guide_id: newGuideId, force: true } : { guide_id: newGuideId });
      setTours(prev => prev.map(tour =>
        tour.id === tourId ? { ...tour, guide_id: newGuideId } : tour
      ));
      setEditingGuides(prev => {
        const newState = { ...prev };
        delete newState[tourId];
        return newState;
      });
      setError(null);
      setSuccess(guide ? `Guide "${guideName}" assigned successfully!` : 'Guide unassigned successfully!');
      setTimeout(() => setSuccess(null), 4000);
    };

    try {
      await applyAssignment(false);
    } catch (error) {
      if (error && error.code === 'guide_double_booked' && error.conflict) {
        const c = error.conflict;
        const proceed = window.confirm(
          `⚠️ ${guideName} already has a tour at ${c.date} ${c.time}: ${c.title}. Assign anyway?`
        );
        if (proceed) {
          try {
            await applyAssignment(true);
          } catch (retryError) {
            console.error('Error saving guide assignment (forced):', retryError);
            setSuccess(null);
            setError('Failed to save guide assignment');
          }
        } else {
          // Leave it unassigned; keep the dropdown open so another guide can be picked
          setError(null);
          setSuccess(`Assignment cancelled — ${guideName} not assigned (double-booked).`);
          setTimeout(() => setSuccess(null), 4000);
        }
      } else {
        console.error('Error saving guide assignment:', error);
        setSuccess(null);
        setError('Failed to save guide assignment');
      }
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

  // Step 6.4: hand-entered departures. The sync never touches these rows, so the only
  // way one changes is here.
  const handleOpenManualAdd = () => {
    setManualEditTour(null);
    setManualModalOpen(true);
  };

  const handleOpenManualEdit = (tour) => {
    setManualEditTour(tour);
    setManualModalOpen(true);
  };

  const handleSaveManualTour = async (payload) => {
    setManualSaving(true);
    try {
      if (manualEditTour) {
        await mysqlDB.updateManualTour(manualEditTour.id, payload);
        setSuccess('Tour updated');
      } else {
        await mysqlDB.createManualTour(payload);
        setSuccess('Tour added');
      }
      setManualModalOpen(false);
      setManualEditTour(null);
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError(err?.response?.data?.error || 'Could not save the tour');
    } finally {
      setManualSaving(false);
    }
  };

  const handleDeleteManualTour = async (tour, confirmText) => {
    if (!window.confirm(confirmText)) return;
    try {
      await mysqlDB.deleteManualTour(tour.id);
      setSuccess('Manual tour removed');
      await loadData(true, currentPage, getCurrentFilters());
    } catch (err) {
      setError(err?.response?.data?.error || 'Could not delete the tour');
    }
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

  // Resolve a tour object by id from either the ungrouped list or any group's tours.
  const findTourById = (tid) => {
    const t = tours.find(t => t.id === tid);
    if (t) return t;
    for (const g of tourGroups) {
      const gt = (g.tours || []).find(t => t.id === tid);
      if (gt) return gt;
    }
    return null;
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

    // Validate PAX against the capacity for this tour type (exclude cancelled bookings).
    const mergeTours = tourIdsToMerge.map(findTourById).filter(Boolean);
    const totalPax = mergeTours.reduce((sum, t) => t.cancelled ? sum : sum + (parseInt(t.participants) || getParticipantCount(t)), 0);
    const maxPax = mergeTours.length ? Math.min(...mergeTours.map(t => getMaxPax(t.title))) : 9;

    if (totalPax > maxPax) {
      setError(`Cannot merge: total PAX (${totalPax}) exceeds maximum of ${maxPax}`);
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
    const guideName = guides.find(g => g.id === parseInt(guideId))?.name || 'None';
    try {
      let assigned = 0;
      let skipped = 0;
      for (const item of selectedItems) {
        if (item.type === 'tour') {
          try {
            await mysqlDB.updateTour(item.id, { guide_id: guideId });
            assigned++;
          } catch (err) {
            if (err && err.code === 'guide_double_booked' && err.conflict) {
              const c = err.conflict;
              const proceed = window.confirm(
                `⚠️ ${guideName} already has a tour at ${c.date} ${c.time}: ${c.title}. Assign anyway?`
              );
              if (proceed) {
                await mysqlDB.updateTour(item.id, { guide_id: guideId, force: true });
                assigned++;
              } else {
                skipped++;
              }
            } else {
              throw err;
            }
          }
        } else if (item.type === 'group') {
          await tourGroupsAPI.update(item.id, { guide_id: guideId || null });
          assigned++;
        }
      }
      setSuccess(`Guide "${guideName}" assigned to ${assigned} item(s)${skipped ? `, ${skipped} skipped (double-booked)` : ''}`);
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

    // Validate PAX against the capacity for this tour type (exclude cancelled bookings).
    const mergeTours = tourIdsToMerge.map(findTourById).filter(Boolean);
    const totalPax = mergeTours.reduce((sum, t) => t.cancelled ? sum : sum + (parseInt(t.participants) || getParticipantCount(t)), 0);
    const maxPax = mergeTours.length ? Math.min(...mergeTours.map(t => getMaxPax(t.title))) : 9;

    if (totalPax > maxPax) {
      setError(`Cannot merge: total PAX (${totalPax}) exceeds maximum of ${maxPax}`);
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
  // Step 3.5: WHAT is unassigned is decided by the server (one row per departure, effective guide
  // of the group or the tour, the page's current filter) - not by whatever groups this page has loaded.
  const downloadUnassignedReport = async () => {
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
    let report;
    try {
      const { guide_id: _ignoredGuideFilter, ...reportFilters } = getCurrentFilters();
      report = await mysqlDB.getUnassignedReport(reportFilters);
    } catch (err) {
      console.error('Unassigned report failed:', err);
      setError('Could not build the unassigned report. Please try again.');
      setTimeout(() => setError(null), 5000);
      return;
    }

    const content = buildUnassignedReportText(report.departures, { filterLabel, now });
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
            {isAdmin() && (
              <Button
                variant="outline"
                onClick={handleOpenManualAdd}
                disabled={loading}
                data-testid="add-manual-tour"
                className="flex items-center gap-2 flex-1 md:flex-none justify-center"
              >
                <FiPlus className="h-4 w-4" />
                <span className="hidden sm:inline">Add tour</span>
                <span className="sm:hidden">Add</span>
              </Button>
            )}
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
              <label className="block text-sm font-medium text-stone-700 mb-1.5 md:mb-2">Filter by Language</label>
              <select
                value={selectedLanguage}
                onChange={(e) => setSelectedLanguage(e.target.value)}
                className="w-full px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                data-testid="tours-language-filter"
              >
                <option value="all">All Languages</option>
                {languageOptions.map((l) => (
                  <option key={l.language} value={l.language}>
                    {l.language} ({l.departures})
                  </option>
                ))}
              </select>
            </div>
            <DateFilter
              filterDate={filterDate}
              setFilterDate={setFilterDate}
              showUpcoming={showUpcoming}
              setShowUpcoming={setShowUpcoming}
              showPast={showPast}
              setShowPast={setShowPast}
              showDateRange={showDateRange}
              setShowDateRange={setShowDateRange}
              rangeStartDate={rangeStartDate}
              setRangeStartDate={setRangeStartDate}
              rangeEndDate={rangeEndDate}
              setRangeEndDate={setRangeEndDate}
            />
          </div>
        </Card>


        {/* Step 5.2: the list could not be loaded completely - say so instead of showing a wrong list */}
        {loadError && (
          <Card className="border-terracotta-200 bg-terracotta-50" role="alert" data-testid="tours-load-error">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div>
                <p className="font-medium text-terracotta-800">The tours list could not be loaded.</p>
                <p className="text-sm text-terracotta-700">{loadError}</p>
              </div>
              <Button onClick={() => loadData(true, currentPage, getCurrentFilters())} className="min-h-[44px]">
                Try again
              </Button>
            </div>
          </Card>
        )}

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
              const dateStats = computeItemStats(
                dateGroup.periods.flatMap(periodGroup => periodGroup.items)
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
                        <CountLabel {...dateStats} />
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
                            <CountLabel {...computeItemStats(periodGroup.items)} />
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
                              className={`cursor-pointer transition-all ${
                                isDraggedOver
                                  ? 'bg-terracotta-50 ring-2 ring-terracotta-200'
                                  : tour.cancelled
                                    ? 'bg-red-50'
                                    : tour.guide_id
                                      ? 'bg-green-50 hover:bg-green-100'
                                      : 'hover:bg-stone-50'
                              } ${
                                dragState.draggedId === tour.id && dragState.draggedType === 'tour' ? 'opacity-50' : ''
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
                                {/* Step 6.4: this departure was typed in, not synced. */}
                                {tour.is_manual && (
                                  <span
                                    className="inline-block mt-1 px-2 py-0.5 text-xs font-medium rounded-tuscan bg-amber-100 text-amber-800"
                                    data-testid="manual-tour-badge"
                                    title="Added by hand - the Bokun sync never changes this row"
                                  >
                                    Added by hand
                                  </span>
                                )}
                                {tour.possible_duplicate_of && tour.possible_duplicate_of.length > 0 && (
                                  <span
                                    className="inline-block mt-1 ml-1 px-2 py-0.5 text-xs font-medium rounded-tuscan bg-red-100 text-red-800"
                                    data-testid="duplicate-badge"
                                    title={`Same date, time and PAX as tour ${tour.possible_duplicate_of.join(', ')} - nothing was merged`}
                                  >
                                    Possible duplicate
                                  </span>
                                )}
                              </td>
                              <td className="px-6 py-4 text-sm text-stone-900">
                                <div className="break-words">
                                  {tour.title}
                                  <ParticipantNamesCompact tour={tour} />
                                </div>
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900">
                                <span
                                  className={`inline-block px-2 py-1 text-xs font-medium rounded-tuscan ${
                                    getTourLanguage(tour)
                                      ? 'bg-renaissance-50 text-renaissance-700'
                                      : 'bg-stone-100 text-stone-500'
                                  }`}
                                  data-testid="tour-language-chip"
                                >
                                  {getTourLanguage(tour) || 'Unknown'}
                                </span>
                              </td>
                              <td className="px-4 py-4 whitespace-nowrap text-sm text-stone-900">
                                {getParticipantCount(tour)} PAX
                                {(() => {
                                  const s = formatBreakdown(getPaxBreakdown(tour));
                                  return s ? <span className="text-xs text-stone-500 ml-1">({s})</span> : null;
                                })()}
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
                                    <div className="flex items-center gap-2 flex-wrap">
                                      <span
                                        className="cursor-pointer hover:bg-stone-100 px-2 py-1 rounded-tuscan"
                                        onClick={() => setEditingGuides(prev => ({
                                          ...prev,
                                          [tour.id]: tour.guide_id || ''
                                        }))}
                                      >
                                        {guideName}
                                      </span>
                                      {!tour.guide_id && !tour.cancelled && (
                                        <>
                                          {openRequests[tour.id] && (
                                            openRequests[tour.id].status === 'pending' ? (
                                              <span className="text-xs font-medium text-gold-800 bg-gold-50 border border-gold-300 px-2 py-0.5 rounded-full whitespace-nowrap">
                                                Richiesto a {openRequests[tour.id].guide_name} — in attesa
                                              </span>
                                            ) : (
                                              <span className="text-xs font-medium text-stone-600 bg-stone-100 border border-stone-300 px-2 py-0.5 rounded-full whitespace-nowrap">
                                                Rifiutato da {openRequests[tour.id].guide_name}
                                              </span>
                                            )
                                          )}
                                          <button
                                            onClick={() => openAskGuide(tour)}
                                            className="inline-flex items-center gap-1 px-2 py-1 min-h-[32px] text-xs font-medium text-olive-700 bg-olive-50 hover:bg-olive-100 active:bg-olive-200 border border-olive-200 rounded-tuscan transition-colors touch-manipulation"
                                            title="Ask a guide via WhatsApp"
                                          >
                                            <FiMessageCircle size={13} />
                                            Ask
                                          </button>
                                        </>
                                      )}
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
                                      {Number(tour.is_private) === 1 && (
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                          Private
                                        </span>
                                      )}
                                      {isGuidePaid(tour) && (
                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-olive-100 text-olive-800">
                                          Guide paid
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
                                      {/* Step 6.4: a hand-entered row is the only kind the owner may edit or remove here. */}
                                      {tour.is_manual && isAdmin() && (
                                        <>
                                          <button
                                            onClick={() => handleOpenManualEdit(tour)}
                                            data-testid={`manual-edit-${tour.id}`}
                                            className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-stone-100 text-stone-700 hover:bg-stone-200"
                                          >
                                            Edit
                                          </button>
                                          <button
                                            onClick={() => handleDeleteManualTour(tour,
                                              `Remove the hand-entered tour "${tour.title}" on ${tour.date}? This cannot be undone.`)}
                                            data-testid={`manual-delete-${tour.id}`}
                                            className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 hover:bg-red-100"
                                          >
                                            Remove
                                          </button>
                                        </>
                                      )}
                                      {tour.is_manual && isAdmin() && tour.possible_duplicate_of && tour.possible_duplicate_of.length > 0 && (
                                        <button
                                          onClick={() => handleDeleteManualTour(tour,
                                            'Bokun is now sending this departure as well. Remove your hand-entered copy and keep the synced one?')}
                                          data-testid={`manual-dedupe-${tour.id}`}
                                          className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-600 text-white hover:bg-red-700"
                                        >
                                          Same tour — remove mine
                                        </button>
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
                        {(() => {
                          const s = computeItemStats(periodGroup.items);
                          return (
                            <span className="text-xs text-stone-400">
                              {s.activeTours} · {s.pax} PAX
                              {s.cancelledCount > 0 && ` · ${s.cancelledCount} cancelled`}
                            </span>
                          );
                        })()}
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
                      Total: <CountLabel
                        activeTours={totalData.totalTours}
                        cancelledCount={totalData.totalCancelled}
                        pax={totalData.totalParticipants}
                      />
                    </div>
                    {needGuideCount > 0 && (
                      <div className="text-sm font-medium text-terracotta-700" data-testid="need-guide-banner">
                        {needGuideCount === 1
                          ? '1 tour still needs a guide'
                          : `${needGuideCount} tours still need a guide`}
                      </div>
                    )}
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

                {/* Category breakdown — each active departure counted once */}
                {(() => {
                  const visible = CATEGORY_ORDER.filter(cat => categorySummary.buckets[cat]);
                  if (visible.length === 0) return null;
                  return (
                    <div className="mt-3 grid grid-cols-2 md:grid-cols-4 gap-2">
                      {visible.map(cat => {
                        const b = categorySummary.buckets[cat];
                        const isPrivate = PRIVATE_CATEGORIES.has(cat);
                        return (
                          <div
                            key={cat}
                            className={`rounded-tuscan border p-3 ${
                              isPrivate
                                ? 'bg-purple-50 border-purple-200 text-purple-800'
                                : 'bg-white border-stone-200'
                            }`}
                          >
                            <div className={`text-xs font-medium ${isPrivate ? 'text-purple-700' : 'text-stone-500'}`}>
                              {cat}
                            </div>
                            <div className={`mt-0.5 text-2xl font-bold leading-none ${isPrivate ? 'text-purple-800' : 'text-stone-900'}`}>
                              {b.tours}
                              <span className="ml-1 text-sm font-normal">{b.tours === 1 ? 'tour' : 'tours'}</span>
                            </div>
                            <div className={`mt-1 text-xs ${isPrivate ? 'text-purple-700' : 'text-stone-600'}`}>
                              {b.pax} PAX
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  );
                })()}
              </div>
            </Card>
          )}
        </div>
      </div>

      {/* Step 6.4: add / edit a departure by hand */}
      <ManualTourModal
        isOpen={manualModalOpen}
        onClose={() => { setManualModalOpen(false); setManualEditTour(null); }}
        onSave={handleSaveManualTour}
        guides={guides}
        tour={manualEditTour}
        saving={manualSaving}
      />

      {/* Booking Details Modal */}
      <BookingDetailsModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        ticket={selectedTour}
        onUpdateNotes={handleUpdateNotesFromModal}
      />

      {/* Ask-a-guide (availability request) Modal — reusable component */}
      {askTour && (
        <AskGuideModal
          tour={askTour}
          guides={guides}
          language={getTourLanguage(askTour)}
          time={getBookingTime(askTour)}
          onClose={closeAskGuide}
          onRequested={handleGuideRequested}
        />
      )}

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