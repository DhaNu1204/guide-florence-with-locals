import React, { useState, useEffect, useMemo } from 'react';
import { FiCalendar, FiUsers, FiTag, FiAlertCircle, FiSave, FiX } from 'react-icons/fi';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import BookingDetailsModal from '../components/BookingDetailsModal';
import { getTours, updateTour } from '../services/mysqlDB';
import { filterTicketsOnly } from '../utils/tourFilters';

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
              // Only count CHILD, NOT INFANT (infants are free)
              children += quantity;
            }
            // INFANT is intentionally ignored - they don't need tickets
          });

          return { adults, children, total: adults + children };
        }

        // Fallback to totalParticipants if priceCategoryBookings not available
        const total = bokunData.productBookings[0].fields.totalParticipants || parseInt(tour.participants) || 0;
        return { adults: total, children: 0, total };
      }
    }

    // Final fallback to tour.participants field
    const total = parseInt(tour.participants) || 0;
    return { adults: total, children: 0, total };
  } catch (error) {
    console.error('Error parsing participant breakdown:', error);
    const total = parseInt(tour.participants) || 0;
    return { adults: total, children: 0, total };
  }
};

const PriorityTickets = () => {
  const [ticketBookings, setTicketBookings] = useState([]);
  const [filteredTickets, setFilteredTickets] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [filters, setFilters] = useState({
    date: '', // Empty = show all dates (changed from defaulting to today)
    location: '',
    bookingChannel: ''
  });
  const [editingNotes, setEditingNotes] = useState({});
  const [savingChanges, setSavingChanges] = useState({});
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedBooking, setSelectedBooking] = useState(null);

  useEffect(() => {
    loadTicketBookings();
  }, []);

  useEffect(() => {
    applyFilters();
  }, [ticketBookings, filters]);

  const loadTicketBookings = async (forceRefresh = false) => {
    setLoading(true);
    setError(null);

    try {
      // Fetch upcoming bookings only (from today + 60 days) with higher limit
      // Using upcoming=true filter ensures we get future tickets, not old 2025 data
      const toursResponse = await getTours(forceRefresh, 1, 500, { upcoming: true });

      // Extract tours array from paginated response
      const toursData = toursResponse && toursResponse.data ? toursResponse.data : toursResponse;

      if (toursData) {
        // Filter only ticket products using smart keyword detection from tourFilters utility
        // Keep cancelled bookings to show them with a badge (similar to Tours.jsx)
        const tickets = filterTicketsOnly(toursData);

        // Sort by date and time (earliest first - morning bookings at top)
        const sortedTickets = tickets.sort((a, b) => {
          const dateTimeA = new Date(a.date + ' ' + a.time);
          const dateTimeB = new Date(b.date + ' ' + b.time);
          return dateTimeA - dateTimeB; // Ascending order (earliest first)
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

  // Memoized stats calculation to prevent unnecessary recalculations
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

    return {
      totalTickets,
      upcomingTickets,
      uffiziTickets,
      accademiaTickets
    };
  }, [ticketBookings]);

  const getTicketType = (title) => {
    if (title.includes('Uffizi')) return 'Uffizi Gallery';
    if (title.includes('Accademia')) return 'Accademia Gallery';
    return 'Unknown';
  };

  const getTicketTypeBadgeColor = (title) => {
    if (title.includes('Uffizi')) return 'bg-blue-100 text-blue-700';
    if (title.includes('Accademia')) return 'bg-purple-100 text-purple-700';
    return 'bg-gray-100 text-gray-700';
  };

  const applyFilters = () => {
    let filtered = [...ticketBookings];

    // Filter by date
    if (filters.date) {
      filtered = filtered.filter(ticket => ticket.date === filters.date);
    }

    // Filter by location (museum)
    if (filters.location) {
      filtered = filtered.filter(ticket => {
        const ticketType = getTicketType(ticket.title);
        return ticketType.includes(filters.location);
      });
    }

    // Filter by booking channel
    if (filters.bookingChannel) {
      filtered = filtered.filter(ticket =>
        ticket.booking_channel && ticket.booking_channel.toLowerCase().includes(filters.bookingChannel.toLowerCase())
      );
    }

    setFilteredTickets(filtered);
  };

  const handleFilterChange = (filterName, value) => {
    setFilters(prev => ({
      ...prev,
      [filterName]: value
    }));
  };

  const clearFilters = () => {
    setFilters({
      date: '', // Reset to show all dates (consistent with initial state)
      location: '',
      bookingChannel: ''
    });
  };

  const saveNotes = async (ticketId) => {
    const newNotes = editingNotes[ticketId];

    setSavingChanges(prev => ({ ...prev, [ticketId]: true }));

    try {
      await updateTour(ticketId, { notes: newNotes });

      // Update local state
      setTicketBookings(prev =>
        prev.map(ticket =>
          ticket.id === ticketId ? { ...ticket, notes: newNotes } : ticket
        )
      );

      // Clear editing state
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

    // Update local state
    setTicketBookings(prev =>
      prev.map(ticket =>
        ticket.id === ticketId ? { ...ticket, notes: newNotes } : ticket
      )
    );
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Error Alert */}
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-start">
          <FiAlertCircle className="text-xl mr-3 mt-0.5 flex-shrink-0" />
          <div className="flex-1">
            <p className="font-medium">Error</p>
            <p className="text-sm mt-1">{error}</p>
          </div>
          <button
            onClick={() => setError(null)}
            className="ml-4 text-red-500 hover:text-red-700"
          >
            ×
          </button>
        </div>
      )}

      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Total Tickets</p>
              <p className="text-3xl font-bold text-gray-900">{stats.totalTickets}</p>
            </div>
            <div className="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
              <FiTag className="text-blue-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Upcoming</p>
              <p className="text-3xl font-bold text-gray-900">{stats.upcomingTickets}</p>
            </div>
            <div className="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
              <FiCalendar className="text-green-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Uffizi</p>
              <p className="text-3xl font-bold text-gray-900">{stats.uffiziTickets}</p>
            </div>
            <div className="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
              <FiTag className="text-blue-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Accademia</p>
              <p className="text-3xl font-bold text-gray-900">{stats.accademiaTickets}</p>
            </div>
            <div className="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
              <FiTag className="text-purple-600 text-xl" />
            </div>
          </div>
        </div>
      </div>

      {/* Filters */}
      <Card>
        <div className="flex flex-col sm:flex-row sm:items-end gap-3">
          {/* Date Filter */}
          <div className="flex-1">
            <label className="block text-xs font-medium text-gray-700 mb-1">Date</label>
            <input
              type="date"
              value={filters.date}
              onChange={(e) => handleFilterChange('date', e.target.value)}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            />
          </div>

          {/* Location Filter */}
          <div className="flex-1">
            <label className="block text-xs font-medium text-gray-700 mb-1">Museum</label>
            <select
              value={filters.location}
              onChange={(e) => handleFilterChange('location', e.target.value)}
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
              <option value="">All Museums</option>
              <option value="Uffizi">Uffizi Gallery</option>
              <option value="Accademia">Accademia Gallery</option>
            </select>
          </div>

          {/* Booking Channel Filter */}
          <div className="flex-1">
            <label className="block text-xs font-medium text-gray-700 mb-1">Booking Channel</label>
            <input
              type="text"
              value={filters.bookingChannel}
              onChange={(e) => handleFilterChange('bookingChannel', e.target.value)}
              placeholder="e.g., Viator"
              className="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
              Clear
            </Button>
          </div>
        </div>
      </Card>

      {/* Ticket Bookings List */}
      <Card>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-xl font-semibold text-gray-900">
            Ticket Bookings ({filteredTickets.length} of {ticketBookings.length})
          </h2>
        </div>

        {filteredTickets.length === 0 ? (
          <div className="text-center py-12 text-gray-500">
            <FiTag className="text-4xl mx-auto mb-2 opacity-50" />
            <p>No ticket bookings found</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[100px]">Date</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[70px]">Time</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[130px]">Museum</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[120px]">Customer</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[90px]">Participants</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase w-[120px]">Booking Channel</th>
                  <th className="text-left py-3 px-4 text-xs font-semibold text-gray-600 uppercase">Notes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {filteredTickets.map((ticket) => (
                  <tr
                    key={ticket.id}
                    className={`hover:bg-gray-50 transition-colors cursor-pointer ${ticket.cancelled ? 'bg-red-50' : ''}`}
                    onClick={() => handleRowClick(ticket)}
                  >
                    <td className="py-3 px-4 text-sm text-gray-900 w-[100px]">
                      {new Date(ticket.date).toLocaleDateString('en-GB')}
                    </td>
                    <td className="py-3 px-4 text-sm text-gray-900 w-[70px]">
                      {ticket.time}
                    </td>
                    <td className="py-3 px-4 w-[130px]">
                      <div className="flex flex-col gap-1">
                        <span className={`inline-block px-2 py-1 text-xs font-medium rounded-full ${getTicketTypeBadgeColor(ticket.title)}`}>
                          {getTicketType(ticket.title)}
                        </span>
                        {ticket.cancelled && (
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                            Cancelled
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="py-3 px-4 text-sm text-gray-900 w-[120px]">
                      {ticket.customer_name || 'N/A'}
                    </td>
                    <td className="py-3 px-4 text-sm text-gray-900 w-[90px]">
                      <div className="flex items-center">
                        <FiUsers className="mr-1 text-gray-400" />
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
                        <span className="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded-full">
                          {ticket.booking_channel}
                        </span>
                      ) : (
                        <span className="text-sm text-gray-400">Direct</span>
                      )}
                    </td>
                    <td className="py-3 px-4" onClick={(e) => e.stopPropagation()}>
                      {editingNotes[ticket.id] !== undefined ? (
                        <div className="flex items-start space-x-2">
                          <textarea
                            value={editingNotes[ticket.id]}
                            onChange={(e) => handleNotesChange(ticket.id, e.target.value)}
                            className="flex-1 px-2 py-1 text-sm border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-h-[60px]"
                            rows="2"
                          />
                          <div className="flex flex-col space-y-1">
                            <button
                              onClick={() => saveNotes(ticket.id)}
                              disabled={savingChanges[ticket.id]}
                              className="p-1 text-green-600 hover:bg-green-50 rounded disabled:opacity-50"
                              title="Save notes"
                            >
                              <FiSave className="text-lg" />
                            </button>
                            <button
                              onClick={() => cancelNotesEdit(ticket.id)}
                              disabled={savingChanges[ticket.id]}
                              className="p-1 text-red-600 hover:bg-red-50 rounded disabled:opacity-50"
                              title="Cancel"
                            >
                              <FiX className="text-lg" />
                            </button>
                          </div>
                        </div>
                      ) : (
                        <div
                          onClick={() => handleNotesChange(ticket.id, ticket.notes || '')}
                          className="text-sm text-gray-600 cursor-pointer hover:bg-gray-100 p-2 rounded min-h-[40px]"
                          title="Click to edit notes"
                        >
                          {ticket.notes || <span className="text-gray-400 italic">Click to add notes...</span>}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
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
