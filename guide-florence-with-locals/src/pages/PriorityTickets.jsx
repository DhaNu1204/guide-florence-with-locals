import React, { useState, useEffect, useMemo } from 'react';
import { FiCalendar, FiUsers, FiTag, FiAlertCircle, FiSave, FiX, FiChevronLeft, FiChevronRight } from 'react-icons/fi';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import BookingDetailsModal from '../components/BookingDetailsModal';
import { getTours, updateTour } from '../services/mysqlDB';
import { filterTicketsOnly } from '../utils/tourFilters';
import { format } from 'date-fns';

// Helper function to extract participant breakdown (adults/children) from bokun_data
// NOTE: INFANT tickets are FREE and not counted (they don't need museum tickets)
const getParticipantBreakdown = (tour) => {
  try {
    if (tour.bokun_data) {
      const bokunData = JSON.parse(tour.bokun_data);
      if (bokunData.productBookings && bokunData.productBookings[0] && bokunData.productBookings[0].fields) {
        const priceCategoryBookings = bokunData.productBookings[0].fields.priceCategoryBookings;

        if (priceCategoryBookings && Array.isArray(priceCategoryBookings)) {
          let adults = 0;
          let children = 0;

          priceCategoryBookings.forEach(category => {
            const quantity = category.quantity || 0;
            const ticketCategory = category.pricingCategory?.ticketCategory || '';

            if (ticketCategory === 'ADULT') {
              adults += quantity;
            } else if (ticketCategory === 'CHILD') {
              children += quantity;
            }
          });

          return { adults, children, total: adults + children };
        }

        const total = bokunData.productBookings[0].fields.totalParticipants || parseInt(tour.participants) || 0;
        return { adults: total, children: 0, total };
      }
    }

    const total = parseInt(tour.participants) || 0;
    return { adults: total, children: 0, total };
  } catch (error) {
    console.error('Error parsing participant breakdown:', error);
    const total = parseInt(tour.participants) || 0;
    return { adults: total, children: 0, total };
  }
};

// Helper to get today's date in YYYY-MM-DD format (Italian timezone)
const getTodayDate = () => {
  const now = new Date();
  const italianDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(now);
  return italianDate;
};

// Helper to format date for display
const formatDateHeader = (dateStr) => {
  const today = getTodayDate();
  const tomorrow = new Date();
  tomorrow.setDate(tomorrow.getDate() + 1);
  const tomorrowStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(tomorrow);

  if (dateStr === today) {
    return { label: 'Today', color: 'bg-terracotta-500 text-white' };
  } else if (dateStr === tomorrowStr) {
    return { label: 'Tomorrow', color: 'bg-gold-500 text-white' };
  } else {
    const date = new Date(dateStr + 'T00:00:00');
    const dayName = format(date, 'EEEE');
    const formatted = format(date, 'dd MMM yyyy');
    return { label: `${dayName}, ${formatted}`, color: 'bg-stone-500 text-white' };
  }
};

const PriorityTickets = () => {
  const [ticketBookings, setTicketBookings] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState({
    date: '',
    location: '',
    bookingChannel: ''
  });
  const [editingNotes, setEditingNotes] = useState({});
  const [savingChanges, setSavingChanges] = useState({});
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedBooking, setSelectedBooking] = useState(null);

  // Pagination state
  const [currentPage, setCurrentPage] = useState(1);
  const ticketsPerPage = 50;

  useEffect(() => {
    loadTicketBookings();
  }, []);

  // Reset to page 1 when filters change
  useEffect(() => {
    setCurrentPage(1);
  }, [filters]);

  const loadTicketBookings = async (forceRefresh = false) => {
    setLoading(true);
    setError(null);

    try {
      const toursResponse = await getTours(forceRefresh, 1, 500, { upcoming: true });
      const toursData = toursResponse && toursResponse.data ? toursResponse.data : toursResponse;

      if (toursData) {
        const tickets = filterTicketsOnly(toursData);

        // Sort by date and time (earliest first)
        const sortedTickets = tickets.sort((a, b) => {
          const dateTimeA = new Date(a.date + ' ' + a.time);
          const dateTimeB = new Date(b.date + ' ' + b.time);
          return dateTimeA - dateTimeB;
        });

        setTicketBookings(sortedTickets);
      }
    } catch (err) {
      console.error('Error loading ticket bookings:', err);
      setError('Failed to load ticket bookings. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  // FIXED: Use useMemo for filtering instead of useEffect to avoid closure issues
  const filteredTickets = useMemo(() => {
    let filtered = [...ticketBookings];

    // Filter by date
    if (filters.date) {
      filtered = filtered.filter(ticket => ticket.date === filters.date);
    }

    // Filter by location (museum)
    if (filters.location) {
      filtered = filtered.filter(ticket => {
        if (!ticket.title) return false;
        if (filters.location === 'Uffizi') return ticket.title.includes('Uffizi');
        if (filters.location === 'Accademia') return ticket.title.includes('Accademia');
        return true;
      });
    }

    // Filter by booking channel
    if (filters.bookingChannel) {
      filtered = filtered.filter(ticket =>
        ticket.booking_channel && ticket.booking_channel.toLowerCase().includes(filters.bookingChannel.toLowerCase())
      );
    }

    return filtered;
  }, [ticketBookings, filters]);

  // Group tickets by date for day-wise display
  const ticketsByDate = useMemo(() => {
    const grouped = {};
    filteredTickets.forEach(ticket => {
      const date = ticket.date;
      if (!grouped[date]) {
        grouped[date] = [];
      }
      grouped[date].push(ticket);
    });

    // Sort tickets within each date by time
    Object.keys(grouped).forEach(date => {
      grouped[date].sort((a, b) => {
        const timeA = a.time || '00:00';
        const timeB = b.time || '00:00';
        return timeA.localeCompare(timeB);
      });
    });

    return grouped;
  }, [filteredTickets]);

  // Get sorted dates
  const sortedDates = useMemo(() => {
    return Object.keys(ticketsByDate).sort((a, b) => new Date(a) - new Date(b));
  }, [ticketsByDate]);

  // Pagination: get current page tickets (flat list for pagination)
  const paginatedTickets = useMemo(() => {
    const startIndex = (currentPage - 1) * ticketsPerPage;
    const endIndex = startIndex + ticketsPerPage;
    return filteredTickets.slice(startIndex, endIndex);
  }, [filteredTickets, currentPage, ticketsPerPage]);

  // Group paginated tickets by date
  const paginatedTicketsByDate = useMemo(() => {
    const grouped = {};
    paginatedTickets.forEach(ticket => {
      const date = ticket.date;
      if (!grouped[date]) {
        grouped[date] = [];
      }
      grouped[date].push(ticket);
    });
    return grouped;
  }, [paginatedTickets]);

  const paginatedSortedDates = useMemo(() => {
    return Object.keys(paginatedTicketsByDate).sort((a, b) => new Date(a) - new Date(b));
  }, [paginatedTicketsByDate]);

  const totalPages = Math.ceil(filteredTickets.length / ticketsPerPage);

  // Stats calculation
  const stats = useMemo(() => {
    const now = new Date();
    now.setHours(0, 0, 0, 0);

    const totalTickets = ticketBookings.length;

    const upcomingTickets = ticketBookings.filter(ticket => {
      const ticketDate = new Date(ticket.date);
      ticketDate.setHours(0, 0, 0, 0);
      return ticketDate >= now;
    }).length;

    const uffiziTickets = ticketBookings.filter(ticket =>
      ticket.title && ticket.title.includes('Uffizi')
    ).length;

    const accademiaTickets = ticketBookings.filter(ticket =>
      ticket.title && ticket.title.includes('Accademia')
    ).length;

    return { totalTickets, upcomingTickets, uffiziTickets, accademiaTickets };
  }, [ticketBookings]);

  const getTicketType = (title) => {
    if (!title) return 'Unknown';
    if (title.includes('Uffizi')) return 'Uffizi Gallery';
    if (title.includes('Accademia')) return 'Accademia Gallery';
    return 'Unknown';
  };

  const getTicketTypeBadgeColor = (title) => {
    if (!title) return 'bg-stone-100 text-stone-700';
    if (title.includes('Uffizi')) return 'bg-terracotta-100 text-terracotta-700';
    if (title.includes('Accademia')) return 'bg-renaissance-100 text-renaissance-700';
    return 'bg-stone-100 text-stone-700';
  };

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({
      ...prev,
      [filterName]: value
    }));
  };

  const clearFilters = () => {
    setFilters({
      date: '',
      location: '',
      bookingChannel: ''
    });
  };

  // Quick date filter helpers
  const setTodayFilter = () => {
    handleFilterChange('date', getTodayDate());
  };

  const setTomorrowFilter = () => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome' }).format(tomorrow);
    handleFilterChange('date', tomorrowStr);
  };

  const saveNotes = async (ticketId) => {
    const newNotes = editingNotes[ticketId];
    setSavingChanges(prev => ({ ...prev, [ticketId]: true }));

    try {
      await updateTour(ticketId, { notes: newNotes });

      setTicketBookings(prev =>
        prev.map(ticket =>
          ticket.id === ticketId ? { ...ticket, notes: newNotes } : ticket
        )
      );

      setEditingNotes(prev => {
        const updated = { ...prev };
        delete updated[ticketId];
        return updated;
      });

      setError(null);
    } catch (err) {
      console.error('Error saving notes:', err);
      setError('Failed to save notes. Please try again.');
    } finally {
      setSavingChanges(prev => ({ ...prev, [ticketId]: false }));
    }
  };

  const handleNotesChange = (ticketId, value) => {
    setEditingNotes(prev => ({
      ...prev,
      [ticketId]: value
    }));
  };

  const cancelNotesEdit = (ticketId) => {
    setEditingNotes(prev => {
      const updated = { ...prev };
      delete updated[ticketId];
      return updated;
    });
  };

  const handleRowClick = (ticket) => {
    setSelectedBooking(ticket);
    setIsModalOpen(true);
  };

  const handleCloseModal = () => {
    setIsModalOpen(false);
    setSelectedBooking(null);
  };

  const handleUpdateNotesFromModal = async (ticketId, newNotes) => {
    await updateTour(ticketId, { notes: newNotes });

    setTicketBookings(prev =>
      prev.map(ticket =>
        ticket.id === ticketId ? { ...ticket, notes: newNotes } : ticket
      )
    );
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-terracotta-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-4 md:space-y-6 overflow-x-hidden">
      {/* Error Alert */}
      {error && (
        <div className="bg-terracotta-50 border border-terracotta-200 text-terracotta-700 px-4 py-3 rounded-tuscan-lg flex items-start">
          <FiAlertCircle className="text-xl mr-3 mt-0.5 flex-shrink-0" />
          <div className="flex-1">
            <p className="font-medium">Error</p>
            <p className="text-sm mt-1">{error}</p>
          </div>
          <button
            onClick={() => setError(null)}
            className="ml-4 text-terracotta-500 hover:text-terracotta-700"
          >
            ×
          </button>
        </div>
      )}

      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-tuscan-lg border border-stone-200 p-4 hover:shadow-tuscan-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-stone-600 mb-1">Total Tickets</p>
              <p className="text-2xl md:text-3xl font-bold text-stone-900">{stats.totalTickets}</p>
            </div>
            <div className="w-10 h-10 md:w-12 md:h-12 bg-gold-100 rounded-tuscan-lg flex items-center justify-center">
              <FiTag className="text-gold-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-tuscan-lg border border-stone-200 p-4 hover:shadow-tuscan-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-stone-600 mb-1">Upcoming</p>
              <p className="text-2xl md:text-3xl font-bold text-stone-900">{stats.upcomingTickets}</p>
            </div>
            <div className="w-10 h-10 md:w-12 md:h-12 bg-olive-100 rounded-tuscan-lg flex items-center justify-center">
              <FiCalendar className="text-olive-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-tuscan-lg border border-stone-200 p-4 hover:shadow-tuscan-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-stone-600 mb-1">Uffizi</p>
              <p className="text-2xl md:text-3xl font-bold text-stone-900">{stats.uffiziTickets}</p>
            </div>
            <div className="w-10 h-10 md:w-12 md:h-12 bg-terracotta-100 rounded-tuscan-lg flex items-center justify-center">
              <FiTag className="text-terracotta-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-tuscan-lg border border-stone-200 p-4 hover:shadow-tuscan-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-stone-600 mb-1">Accademia</p>
              <p className="text-2xl md:text-3xl font-bold text-stone-900">{stats.accademiaTickets}</p>
            </div>
            <div className="w-10 h-10 md:w-12 md:h-12 bg-renaissance-100 rounded-tuscan-lg flex items-center justify-center">
              <FiTag className="text-renaissance-600 text-xl" />
            </div>
          </div>
        </div>
      </div>

      {/* Filters */}
      <Card>
        <div className="space-y-4">
          {/* Quick Date Filters */}
          <div className="flex flex-wrap gap-2">
            <span className="text-xs font-medium text-stone-600 self-center mr-2">Quick Filters:</span>
            <Button
              variant={filters.date === getTodayDate() ? 'primary' : 'outline'}
              size="sm"
              onClick={setTodayFilter}
            >
              Today
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={setTomorrowFilter}
            >
              Tomorrow
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => handleFilterChange('date', '')}
            >
              All Dates
            </Button>
          </div>

          {/* Filter Inputs */}
          <div className="flex flex-col sm:flex-row sm:items-end gap-3">
            {/* Date Filter */}
            <div className="flex-1">
              <label className="block text-xs font-medium text-stone-700 mb-1">Date</label>
              <input
                type="date"
                value={filters.date}
                onChange={(e) => handleFilterChange('date', e.target.value)}
                className="w-full px-3 py-2 text-sm border border-stone-300 rounded-tuscan focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500"
              />
            </div>

            {/* Location Filter */}
            <div className="flex-1">
              <label className="block text-xs font-medium text-stone-700 mb-1">Museum</label>
              <select
                value={filters.location}
                onChange={(e) => handleFilterChange('location', e.target.value)}
                className="w-full px-3 py-2 text-sm border border-stone-300 rounded-tuscan focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500"
              >
                <option value="">All Museums</option>
                <option value="Uffizi">Uffizi Gallery</option>
                <option value="Accademia">Accademia Gallery</option>
              </select>
            </div>

            {/* Booking Channel Filter */}
            <div className="flex-1">
              <label className="block text-xs font-medium text-stone-700 mb-1">Booking Channel</label>
              <input
                type="text"
                value={filters.bookingChannel}
                onChange={(e) => handleFilterChange('bookingChannel', e.target.value)}
                placeholder="e.g., Viator"
                className="w-full px-3 py-2 text-sm border border-stone-300 rounded-tuscan focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500"
              />
            </div>

            {/* Clear Filters Button */}
            <div className="sm:w-auto w-full">
              <Button
                variant="outline"
                onClick={clearFilters}
                size="sm"
                className="w-full sm:w-auto whitespace-nowrap"
              >
                Clear All
              </Button>
            </div>
          </div>
        </div>
      </Card>

      {/* Ticket Bookings List - Day-wise */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg md:text-xl font-semibold text-stone-900">
            <span className="hidden md:inline">Ticket Bookings ({filteredTickets.length} of {ticketBookings.length})</span>
            <span className="md:hidden">Tickets ({filteredTickets.length}/{ticketBookings.length})</span>
          </h2>
        </div>

        {filteredTickets.length === 0 ? (
          <div className="text-center py-12 text-stone-500">
            <FiTag className="text-4xl mx-auto mb-2 opacity-50" />
            <p>No ticket bookings found</p>
            {(filters.date || filters.location || filters.bookingChannel) && (
              <Button variant="outline" size="sm" onClick={clearFilters} className="mt-4">
                Clear Filters
              </Button>
            )}
          </div>
        ) : (
          <div className="space-y-6">
            {/* Day-wise grouped tickets */}
            {paginatedSortedDates.map(date => {
              const { label, color } = formatDateHeader(date);
              const dayTickets = paginatedTicketsByDate[date];

              return (
                <div key={date} className="border border-stone-200 rounded-tuscan-lg overflow-hidden">
                  {/* Date Header */}
                  <div className={`px-4 py-3 ${color} flex items-center justify-between`}>
                    <div className="flex items-center">
                      <FiCalendar className="mr-2" />
                      <span className="font-semibold">{label}</span>
                      <span className="ml-2 text-sm opacity-90">
                        ({new Date(date + 'T00:00:00').toLocaleDateString('en-GB')})
                      </span>
                    </div>
                    <span className="text-sm font-medium px-2 py-1 rounded-full bg-white/20">
                      {dayTickets.length} ticket{dayTickets.length !== 1 ? 's' : ''}
                    </span>
                  </div>

                  {/* Desktop Table */}
                  <div className="hidden md:block overflow-x-auto">
                    <table className="w-full">
                      <thead className="bg-stone-50 border-b border-stone-200">
                        <tr>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase w-[70px]">Time</th>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase w-[130px]">Museum</th>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase w-[120px]">Customer</th>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase w-[90px]">Participants</th>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase w-[120px]">Channel</th>
                          <th className="text-left py-2 px-4 text-xs font-semibold text-stone-600 uppercase">Notes</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-stone-200">
                        {dayTickets.map((ticket) => (
                          <tr
                            key={ticket.id}
                            className={`hover:bg-stone-50 transition-colors cursor-pointer ${ticket.cancelled ? 'bg-terracotta-50' : ''}`}
                            onClick={() => handleRowClick(ticket)}
                          >
                            <td className="py-3 px-4 text-sm font-medium text-stone-900 w-[70px]">
                              {ticket.time || '-'}
                            </td>
                            <td className="py-3 px-4 w-[130px]">
                              <div className="flex flex-col gap-1">
                                <span className={`inline-block px-2 py-1 text-xs font-medium rounded-full ${getTicketTypeBadgeColor(ticket.title)}`}>
                                  {getTicketType(ticket.title)}
                                </span>
                                {ticket.cancelled && (
                                  <span className="inline-flex items-center px-2 py-0.5 rounded-tuscan text-xs font-medium bg-terracotta-100 text-terracotta-800">
                                    Cancelled
                                  </span>
                                )}
                              </div>
                            </td>
                            <td className="py-3 px-4 text-sm text-stone-900 w-[120px]">
                              {ticket.customer_name || 'N/A'}
                            </td>
                            <td className="py-3 px-4 text-sm text-stone-900 w-[90px]">
                              <div className="flex items-center">
                                <FiUsers className="mr-1 text-stone-400" />
                                {(() => {
                                  const breakdown = getParticipantBreakdown(ticket);
                                  if (breakdown.children > 0) {
                                    return `${breakdown.adults}A / ${breakdown.children}C`;
                                  }
                                  return `${breakdown.adults || breakdown.total || 'N/A'}`;
                                })()}
                              </div>
                            </td>
                            <td className="py-3 px-4 w-[120px]">
                              {ticket.booking_channel ? (
                                <span className="inline-block px-2 py-1 bg-renaissance-100 text-renaissance-700 text-xs font-medium rounded-tuscan">
                                  {ticket.booking_channel}
                                </span>
                              ) : (
                                <span className="text-sm text-stone-400">Direct</span>
                              )}
                            </td>
                            <td className="py-3 px-4" onClick={(e) => e.stopPropagation()}>
                              {editingNotes[ticket.id] !== undefined ? (
                                <div className="flex items-start space-x-2">
                                  <textarea
                                    value={editingNotes[ticket.id]}
                                    onChange={(e) => handleNotesChange(ticket.id, e.target.value)}
                                    className="flex-1 px-2 py-1 text-sm border border-stone-300 rounded-tuscan focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500 min-h-[60px]"
                                    rows="2"
                                  />
                                  <div className="flex flex-col space-y-1">
                                    <button
                                      onClick={() => saveNotes(ticket.id)}
                                      disabled={savingChanges[ticket.id]}
                                      className="p-1 text-olive-600 hover:bg-olive-50 rounded-tuscan disabled:opacity-50"
                                      title="Save notes"
                                    >
                                      <FiSave className="text-lg" />
                                    </button>
                                    <button
                                      onClick={() => cancelNotesEdit(ticket.id)}
                                      disabled={savingChanges[ticket.id]}
                                      className="p-1 text-terracotta-600 hover:bg-terracotta-50 rounded-tuscan disabled:opacity-50"
                                      title="Cancel"
                                    >
                                      <FiX className="text-lg" />
                                    </button>
                                  </div>
                                </div>
                              ) : (
                                <div
                                  onClick={() => handleNotesChange(ticket.id, ticket.notes || '')}
                                  className="text-sm text-stone-600 cursor-pointer hover:bg-stone-100 p-2 rounded-tuscan min-h-[40px]"
                                  title="Click to edit notes"
                                >
                                  {ticket.notes || <span className="text-stone-400 italic">Click to add notes...</span>}
                                </div>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Mobile Cards */}
                  <div className="md:hidden divide-y divide-stone-100">
                    {dayTickets.map((ticket) => (
                      <div
                        key={ticket.id}
                        className={`p-3 active:bg-stone-100 touch-manipulation ${ticket.cancelled ? 'bg-terracotta-50/50' : ''}`}
                        onClick={() => handleRowClick(ticket)}
                      >
                        {/* Row 1: Time + Museum badge + Cancelled */}
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="text-sm font-bold text-stone-900">{ticket.time || '-'}</span>
                          <span className={`inline-block px-2 py-0.5 text-xs font-medium rounded-full ${getTicketTypeBadgeColor(ticket.title)}`}>
                            {getTicketType(ticket.title)}
                          </span>
                          {ticket.cancelled && (
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                              Cancelled
                            </span>
                          )}
                        </div>
                        {/* Row 2: Customer name */}
                        <p className="text-sm text-stone-900 mt-1 line-clamp-1">
                          {ticket.customer_name || 'N/A'}
                        </p>
                        {/* Row 3: PAX + Channel */}
                        <div className="flex items-center justify-between mt-1.5">
                          <div className="flex items-center gap-1 text-sm text-stone-700">
                            <FiUsers size={14} className="text-stone-400" />
                            <span className="font-medium">
                              {(() => {
                                const breakdown = getParticipantBreakdown(ticket);
                                if (breakdown.children > 0) {
                                  return `${breakdown.adults}A / ${breakdown.children}C`;
                                }
                                return `${breakdown.adults || breakdown.total || 'N/A'} PAX`;
                              })()}
                            </span>
                          </div>
                          {ticket.booking_channel ? (
                            <span className="inline-block px-2 py-0.5 bg-renaissance-100 text-renaissance-700 text-xs font-medium rounded-tuscan">
                              {ticket.booking_channel}
                            </span>
                          ) : (
                            <span className="text-xs text-stone-400">Direct</span>
                          )}
                        </div>
                        {/* Row 4: Notes */}
                        <div className="mt-2" onClick={(e) => e.stopPropagation()}>
                          {editingNotes[ticket.id] !== undefined ? (
                            <div className="flex items-start gap-2">
                              <textarea
                                value={editingNotes[ticket.id]}
                                onChange={(e) => handleNotesChange(ticket.id, e.target.value)}
                                className="flex-1 px-2 py-1.5 text-sm border border-stone-300 rounded-tuscan focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500 resize-none"
                                rows="2"
                                placeholder="Add notes..."
                              />
                              <div className="flex flex-col gap-1">
                                <button
                                  onClick={() => saveNotes(ticket.id)}
                                  disabled={savingChanges[ticket.id]}
                                  className="p-1.5 text-olive-600 hover:bg-olive-50 rounded-tuscan disabled:opacity-50 touch-manipulation"
                                >
                                  <FiSave size={16} />
                                </button>
                                <button
                                  onClick={() => cancelNotesEdit(ticket.id)}
                                  disabled={savingChanges[ticket.id]}
                                  className="p-1.5 text-terracotta-600 hover:bg-terracotta-50 rounded-tuscan disabled:opacity-50 touch-manipulation"
                                >
                                  <FiX size={16} />
                                </button>
                              </div>
                            </div>
                          ) : (
                            <p
                              onClick={() => handleNotesChange(ticket.id, ticket.notes || '')}
                              className={`text-xs truncate active:bg-stone-100 px-1 py-0.5 rounded-tuscan ${
                                ticket.notes ? 'text-stone-600' : 'text-stone-400 italic'
                              }`}
                            >
                              {ticket.notes || 'Tap to add notes...'}
                            </p>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              );
            })}

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex flex-col sm:flex-row items-center justify-between pt-4 border-t border-stone-200 gap-3">
                <p className="text-xs md:text-sm text-stone-600">
                  Showing {((currentPage - 1) * ticketsPerPage) + 1} - {Math.min(currentPage * ticketsPerPage, filteredTickets.length)} of {filteredTickets.length}
                </p>
                <div className="flex items-center space-x-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                    disabled={currentPage === 1}
                  >
                    <FiChevronLeft className="w-4 h-4" />
                  </Button>
                  <span className="text-sm text-stone-600">
                    Page {currentPage} of {totalPages}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                    disabled={currentPage === totalPages}
                  >
                    <FiChevronRight className="w-4 h-4" />
                  </Button>
                </div>
              </div>
            )}
          </div>
        )}
      </Card>

      {/* Booking Details Modal */}
      <BookingDetailsModal
        isOpen={isModalOpen}
        onClose={handleCloseModal}
        ticket={selectedBooking}
        onUpdateNotes={handleUpdateNotesFromModal}
      />
    </div>
  );
};

export default PriorityTickets;
