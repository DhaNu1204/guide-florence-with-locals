import React, { useState, useEffect } from 'react';
import { getTickets, addTicket, deleteTicket, updateTicket } from '../services/ticketsService';
import DatePicker from "react-datepicker";
import { format } from "date-fns";
import { it } from "date-fns/locale";
import "react-datepicker/dist/react-datepicker.css";
import { usePageTitle } from '../contexts/PageTitleContext';
import { useAuth } from '../contexts/AuthContext';
import { 
  FiPlus, 
  FiMapPin, 
  FiHash, 
  FiClock, 
  FiTag, 
  FiCalendar, 
  FiEdit2, 
  FiTrash2,
  FiAlertTriangle,
  FiHome
} from 'react-icons/fi';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import Input from '../components/UI/Input';

// Predefined locations
const LOCATION_OPTIONS = [
  'Accademia',
  'Uffizi'
];

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

const Tickets = () => {
  const { setPageTitle } = usePageTitle();
  const [tickets, setTickets] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedDate, setSelectedDate] = useState(null);
  const [showAddForm, setShowAddForm] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState(null);
  const [editingTicket, setEditingTicket] = useState(null);
  const [editSelectedDate, setEditSelectedDate] = useState(null);
  
  // Filter states
  const [filterLocation, setFilterLocation] = useState('all');
  const [filterDate, setFilterDate] = useState(null);
  
  // Add pagination state
  const [currentPage, setCurrentPage] = useState(1);
  const [ticketsPerPage, setTicketsPerPage] = useState(20);
  const ITEMS_PER_PAGE_OPTIONS = [10, 20, 50, 100];

  const [formData, setFormData] = useState({
    location: '',
    code: '',
    date: '',
    time: '',
    quantity: ''
  });

  const [editFormData, setEditFormData] = useState({
    location: '',
    code: '',
    date: '',
    time: '',
    quantity: ''
  });

  const { isAdmin } = useAuth();

  useEffect(() => {
    setPageTitle('Tickets Management');
    fetchTickets();
    
    return () => setPageTitle('');
  }, [setPageTitle]);

  const fetchTickets = async () => {
    try {
      setIsLoading(true);
      setError(null);
      const data = await getTickets();

      if (Array.isArray(data)) {
        setTickets(data);
      } else {
        console.error('Ticket data is not an array:', data);
        setTickets([]);
        setError('Ticket data format is incorrect. Please contact support.');
      }
    } catch (err) {
      console.error('Error fetching tickets:', err);
      setError('Failed to load tickets. Please try again later.');
      setTickets([]);
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

  const handleEditChange = (e) => {
    const { name, value } = e.target;
    setEditFormData({
      ...editFormData,
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

  const handleEditDateChange = (date) => {
    setEditSelectedDate(date);
    const formattedDate = format(date, "yyyy-MM-dd");
    setEditFormData({
      ...editFormData,
      date: formattedDate
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.location || !formData.code || !formData.date || !formData.time || !formData.quantity) return;

    try {
      setIsLoading(true);
      
      const newTicket = await addTicket({
        ...formData,
        quantity: parseInt(formData.quantity)
      });
      
      // Update tickets state with the new ticket
      setTickets(currentTickets => [...currentTickets, newTicket]);
      
      // Reset form
      setFormData({
        location: '',
        code: '',
        date: '',
        time: '',
        quantity: ''
      });
      setSelectedDate(null);
      setShowAddForm(false);
      setError(null);
    } catch (err) {
      console.error('Error adding ticket:', err);
      setError('Failed to add ticket. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleEditSubmit = async (e) => {
    e.preventDefault();
    if (!editFormData.location || !editFormData.code || !editFormData.date || !editFormData.time || !editFormData.quantity) return;

    try {
      setIsLoading(true);
      
      const updatedTicket = await updateTicket(editingTicket.id, {
        ...editFormData,
        quantity: parseInt(editFormData.quantity)
      });
      
      // Update tickets state with the updated ticket
      setTickets(currentTickets => 
        currentTickets.map(ticket => 
          ticket.id === editingTicket.id ? updatedTicket : ticket
        )
      );
      
      // Reset edit form
      setEditingTicket(null);
      setEditFormData({
        location: '',
        code: '',
        date: '',
        time: '',
        quantity: ''
      });
      setEditSelectedDate(null);
      setError(null);
    } catch (err) {
      console.error('Error updating ticket:', err);
      setError('Failed to update ticket. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const startEdit = (ticket) => {
    setEditingTicket(ticket);
    setEditFormData({
      location: ticket.location,
      code: ticket.code,
      date: ticket.date,
      time: ticket.time || '',
      quantity: ticket.quantity.toString()
    });
    setEditSelectedDate(new Date(ticket.date));
  };

  const cancelEdit = () => {
    setEditingTicket(null);
    setEditFormData({
      location: '',
      code: '',
      date: '',
      time: '',
      quantity: ''
    });
    setEditSelectedDate(null);
  };

  const confirmDelete = (ticket) => {
    setDeleteConfirmation(ticket);
  };

  const cancelDelete = () => {
    setDeleteConfirmation(null);
  };

  const handleDelete = async () => {
    if (!deleteConfirmation) return;
    
    try {
      setIsLoading(true);
      await deleteTicket(deleteConfirmation.id);
      setTickets(tickets.filter(ticket => ticket.id !== deleteConfirmation.id));
      setDeleteConfirmation(null);
      setError(null);
    } catch (error) {
      console.error('Error deleting ticket:', error);
      setError('Failed to delete ticket. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  // Helper functions for date display - moved before sorting logic
  const isToday = (dateString) => {
    const today = new Date();
    const ticketDate = new Date(dateString);
    return today.toDateString() === ticketDate.toDateString();
  };

  const isTomorrow = (dateString) => {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const ticketDate = new Date(dateString);
    return tomorrow.toDateString() === ticketDate.toDateString();
  };

  const isPastDate = (dateString) => {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const ticketDate = new Date(dateString);
    ticketDate.setHours(0, 0, 0, 0);
    return ticketDate < today;
  };

  const getDateLabel = (dateString) => {
    if (isToday(dateString)) return 'Today';
    if (isTomorrow(dateString)) return 'Tomorrow';
    if (isPastDate(dateString)) return 'Past';
    return format(new Date(dateString), "EEEE, d MMMM yyyy");
  };

  const getDateColorClass = (dateString) => {
    if (isToday(dateString)) return 'bg-yellow-500 text-white';
    if (isTomorrow(dateString)) return 'bg-green-500 text-white';
    if (isPastDate(dateString)) return 'bg-gray-400 text-white';
    return 'bg-purple-500 text-white';
  };

  // Filter tickets
  let filteredTickets = tickets;
  
  if (filterLocation !== 'all') {
    filteredTickets = filteredTickets.filter(ticket => 
      ticket.location.toLowerCase() === filterLocation.toLowerCase()
    );
  }
  
  if (filterDate) {
    const filterDateStr = format(filterDate, "yyyy-MM-dd");
    filteredTickets = filteredTickets.filter(ticket => ticket.date === filterDateStr);
  }
  
  // Sort tickets by date category first (Today, Tomorrow, Future, Past), then by time
  filteredTickets.sort((a, b) => {
    const dateA = a.date;
    const dateB = b.date;
    
    // Get date priorities
    const getPriority = (dateStr) => {
      if (isToday(dateStr)) return 1;
      if (isTomorrow(dateStr)) return 2;
      if (isPastDate(dateStr)) return 4;
      return 3; // Future dates
    };
    
    const priorityA = getPriority(dateA);
    const priorityB = getPriority(dateB);
    
    // Sort by priority first
    if (priorityA !== priorityB) {
      return priorityA - priorityB;
    }
    
    // If same priority, sort by date (ascending for future dates, descending for past)
    if (priorityA === 3) { // Future dates
      const dateDiff = new Date(dateA) - new Date(dateB);
      if (dateDiff !== 0) return dateDiff;
    } else if (priorityA === 4) { // Past dates
      const dateDiff = new Date(dateB) - new Date(dateA);
      if (dateDiff !== 0) return dateDiff;
    }
    
    // If same date, sort by time
    const timeA = a.time || '00:00';
    const timeB = b.time || '00:00';
    return timeA.localeCompare(timeB);
  });

  // Group tickets by date
  const ticketsByDate = filteredTickets.reduce((acc, ticket) => {
    const date = ticket.date;
    if (!acc[date]) {
      acc[date] = [];
    }
    acc[date].push(ticket);
    return acc;
  }, {});

  // Sort tickets within each date by time
  Object.keys(ticketsByDate).forEach(date => {
    ticketsByDate[date].sort((a, b) => {
      const timeA = a.time || '00:00';
      const timeB = b.time || '00:00';
      return timeA.localeCompare(timeB);
    });
  });

  // Handle page change
  const handlePageChange = (pageNumber) => {
    setCurrentPage(pageNumber);
    // Scroll to top of tickets list
    window.scrollTo({
      top: document.getElementById('tickets-list')?.offsetTop - 100 || 0,
      behavior: 'smooth'
    });
  };

  // Handle items per page change
  const handleItemsPerPageChange = (e) => {
    const value = parseInt(e.target.value, 10);
    setTicketsPerPage(value);
    setCurrentPage(1); // Reset to first page when changing items per page
  };

  return (
    <div className="space-y-6">
      {/* Header Section */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Ticket Management</h1>
          <p className="text-gray-600 mt-1">Manage museum tickets and inventory</p>
        </div>
        {isAdmin() && (
          <Button 
            variant="primary" 
            icon={FiPlus}
            onClick={() => setShowAddForm(true)}
          >
            Add Ticket
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Filters</h2>
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {/* Location Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Filter by Location
            </label>
            <div className="relative">
              <FiMapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
              <select
                value={filterLocation}
                onChange={(e) => {
                  setFilterLocation(e.target.value);
                  setCurrentPage(1);
                }}
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              >
                <option value="all">All Locations</option>
                {LOCATION_OPTIONS.map(location => (
                  <option key={location} value={location.toLowerCase()}>
                    {location}
                  </option>
                ))}
              </select>
            </div>
          </div>
          
          {/* Date Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Filter by Date
            </label>
            <div className="flex gap-2">
              <div className="relative flex-1">
                <div className="flex items-center border border-gray-300 rounded-md shadow-sm bg-white">
                  <span className="pl-3 text-gray-400">
                    <FiCalendar className="h-4 w-4" />
                  </span>
                  <DatePicker
                    selected={filterDate}
                    onChange={(date) => {
                      setFilterDate(date);
                      setCurrentPage(1);
                    }}
                    dateFormat="dd/MM/yyyy"
                    locale={it}
                    renderCustomHeader={CustomHeader}
                    calendarClassName="bg-white shadow-lg border rounded-md" 
                    className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                    placeholderText="Select date to filter"
                  />
                </div>
              </div>
              
              {filterDate && (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setFilterDate(null);
                    setCurrentPage(1);
                  }}
                >
                  Clear
                </Button>
              )}
            </div>
          </div>
        </div>
      </Card>

      {error && (
        <Card className="border-red-200 bg-red-50">
          <div className="flex items-center">
            <FiAlertTriangle className="h-5 w-5 mr-3 text-red-500" />
            <p className="text-red-700">{error}</p>
          </div>
        </Card>
      )}

      {/* Add Ticket Form */}
      {isAdmin() && showAddForm && (
        <Card>
          <h2 className="text-xl font-semibold text-gray-900 mb-6 flex items-center">
            <FiTag className="w-6 h-6 mr-3 text-orange-600" />
            Add New Ticket
          </h2>
          
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Location
                </label>
                <div className="relative">
                  <FiMapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                  <select
                    name="location"
                    value={formData.location}
                    onChange={handleChange}
                    className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    required
                  >
                    <option value="">Select a location</option>
                    {LOCATION_OPTIONS.map((location, index) => (
                      <option key={index} value={location}>
                        {location}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
              
              <div>
                <Input
                  label="Ticket Code"
                  name="code"
                  type="text"
                  value={formData.code}
                  onChange={handleChange}
                  icon={FiHash}
                  placeholder="Enter ticket code"
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
              {/* Date */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Date
                </label>
                <div className="relative">
                  <div className="flex items-center border border-gray-300 rounded-md shadow-sm">
                    <span className="pl-3 text-gray-400">
                      <FiCalendar className="h-4 w-4" />
                    </span>
                    <DatePicker
                      selected={selectedDate}
                      onChange={handleDateChange}
                      dateFormat="dd/MM/yyyy"
                      locale={it}
                      renderCustomHeader={CustomHeader}
                      calendarClassName="bg-white shadow-lg border rounded-md" 
                      className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                      placeholderText="Select date"
                      required
                    />
                  </div>
                </div>
              </div>
              
              {/* Time */}
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Time
                </label>
                <div className="relative">
                  <FiClock className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                  <select
                    name="time"
                    value={formData.time}
                    onChange={handleChange}
                    className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
              
              {/* Quantity */}
              <div>
                <Input
                  label="Quantity"
                  name="quantity"
                  type="number"
                  min="1"
                  value={formData.quantity}
                  onChange={handleChange}
                  icon={FiHash}
                  placeholder="Enter quantity"
                  required
                />
              </div>
            </div>
            
            <div className="flex flex-col sm:flex-row justify-end gap-3 mt-6">
              <Button
                variant="outline"
                onClick={() => setShowAddForm(false)}
              >
                Cancel
              </Button>
              
              <Button
                type="submit"
                variant="primary"
                disabled={isLoading}
                loading={isLoading}
              >
                Save Ticket
              </Button>
            </div>
          </form>
        </Card>
      )}

      {/* Edit Ticket Modal */}
      {editingTicket && (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 p-4">
          <Card className="max-w-2xl w-full mx-auto max-h-[90vh] overflow-y-auto">
            <h2 className="text-xl font-semibold text-gray-900 mb-6 flex items-center">
              <FiEdit2 className="w-6 h-6 mr-3 text-blue-600" />
              Edit Ticket
            </h2>
            
            <form onSubmit={handleEditSubmit} className="space-y-4 md:space-y-6">
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
                <div>
                  <label htmlFor="edit-location" className="block text-sm font-medium text-gray-700 mb-1">
                    Location
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                    </div>
                    <select
                      id="edit-location"
                      name="location"
                      value={editFormData.location}
                      onChange={handleEditChange}
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                      required
                    >
                      <option value="">Select a location</option>
                      {LOCATION_OPTIONS.map((location, index) => (
                        <option key={index} value={location}>
                          {location}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
                
                <div>
                  <label htmlFor="edit-code" className="block text-sm font-medium text-gray-700 mb-1">
                    Code
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                      </svg>
                    </div>
                    <input
                      id="edit-code"
                      name="code"
                      type="text"
                      value={editFormData.code}
                      onChange={handleEditChange}
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                      placeholder="Enter ticket code"
                      required
                    />
                  </div>
                </div>
              </div>

              {/* Date, Time, Quantity - Responsive Layout */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 md:gap-6">
                {/* Date */}
                <div className="col-span-1 md:col-span-1">
                  <label htmlFor="edit-date" className="block text-sm font-medium text-gray-700 mb-1">
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
                        selected={editSelectedDate}
                        onChange={handleEditDateChange}
                        dateFormat="dd/MM/yyyy"
                        locale={it}
                        renderCustomHeader={CustomHeader}
                        calendarClassName="bg-white shadow-lg border rounded-md" 
                        className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                        placeholderText="Select date"
                        required
                      />
                    </div>
                  </div>
                </div>
                
                {/* Time */}
                <div className="col-span-1 md:col-span-1">
                  <label htmlFor="edit-time" className="block text-sm font-medium text-gray-700 mb-1">
                    Time
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </div>
                    <select
                      id="edit-time"
                      name="time"
                      value={editFormData.time}
                      onChange={handleEditChange}
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
                
                {/* Quantity */}
                <div className="col-span-1 md:col-span-1">
                  <label htmlFor="edit-quantity" className="block text-sm font-medium text-gray-700 mb-1">
                    Quantity
                  </label>
                  <div className="relative">
                    <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                      </svg>
                    </div>
                    <input
                      id="edit-quantity"
                      name="quantity"
                      type="number"
                      min="1"
                      value={editFormData.quantity}
                      onChange={handleEditChange}
                      className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                      placeholder="Enter quantity"
                      required
                    />
                  </div>
                </div>
              </div>
              
              <div className="flex flex-col md:flex-row md:justify-end gap-3 mt-6">
                <button
                  type="button"
                  className="w-full md:w-auto px-4 md:px-5 py-2 md:py-2.5 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-400 transition-colors duration-200 shadow-sm"
                  onClick={cancelEdit}
                >
                  Cancel
                </button>
                
                <button
                  type="submit"
                  className="w-full md:w-auto relative px-4 md:px-6 py-2 md:py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-70 disabled:cursor-not-allowed"
                  disabled={isLoading}
                >
                  <span className="relative z-10 flex items-center justify-center">
                    {isLoading ? (
                      <>
                        <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Updating...
                      </>
                    ) : (
                      <>Update Ticket</>
                    )}
                  </span>
                  <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
                </button>
              </div>
            </form>
          </Card>
        </div>
      )}

      {/* Delete Confirmation Modal */}
      {deleteConfirmation && (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-8 max-w-md mx-auto shadow-xl">
            <h2 className="text-xl font-bold mb-4">Delete Ticket</h2>
            <p className="mb-6">
              Are you sure you want to delete this ticket ({deleteConfirmation.code})? This action cannot be undone.
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

      {/* Tickets List */}
      <div id="tickets-list" className="mt-6">
        {isLoading && !showAddForm ? (
          <div className="flex justify-center p-6">
            <svg className="animate-spin h-8 w-8 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
        ) : filteredTickets.length === 0 ? (
          <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
            <div className="flex justify-center items-center">
              <div className="w-12 h-12 text-purple-600">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z" />
                </svg>
              </div>
            </div>
            <h3 className="text-lg font-semibold text-gray-800 mt-2 mb-2">No tickets available</h3>
            <p className="text-gray-500 mb-4">
              {filterDate || filterLocation !== 'all' 
                ? 'No tickets match your current filters' 
                : 'Get started by adding your first ticket'}
            </p>
            {!showAddForm && (
              <button 
                onClick={() => setShowAddForm(true)}
                className="relative px-5 py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
              >
                <span className="relative z-10 flex items-center">
                  <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                  Add Ticket
                </span>
                <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
              </button>
            )}
          </div>
        ) : (
          <>
            {/* Calculate pagination values */}
            {(() => {
              const indexOfLastTicket = currentPage * ticketsPerPage;
              const indexOfFirstTicket = indexOfLastTicket - ticketsPerPage;
              const currentTickets = filteredTickets.slice(indexOfFirstTicket, indexOfLastTicket);
              const totalPages = Math.ceil(filteredTickets.length / ticketsPerPage);
              
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
              
              const paginationRange = getPaginationRange();
              
              // Group current tickets by date for display
              const currentTicketsByDate = currentTickets.reduce((acc, ticket) => {
                const date = ticket.date;
                if (!acc[date]) {
                  acc[date] = [];
                }
                acc[date].push(ticket);
                return acc;
              }, {});
              
              // Sort tickets within each date by time
              Object.keys(currentTicketsByDate).forEach(date => {
                currentTicketsByDate[date].sort((a, b) => {
                  const timeA = a.time || '00:00';
                  const timeB = b.time || '00:00';
                  return timeA.localeCompare(timeB);
                });
              });
              
              return (
                <>
                  {filterDate ? (
                    // When a date is selected, show tickets organized by location in tables
                    <div className="space-y-6">
                      {/* Uffizi Section */}
                      {(() => {
                        const uffiziTickets = currentTickets.filter(ticket => 
                          ticket.location.toLowerCase() === 'uffizi'
                        ).sort((a, b) => {
                          const timeA = a.time || '00:00';
                          const timeB = b.time || '00:00';
                          return timeA.localeCompare(timeB);
                        });

                        return (
                          <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div className="bg-blue-500 text-white px-4 py-3">
                              <h3 className="font-medium text-lg flex items-center">
                                <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                                Uffizi Gallery - {format(filterDate, "dd/MM/yyyy")}
                                <span className="ml-auto text-sm bg-blue-600 px-2 py-1 rounded">
                                  {uffiziTickets.length} ticket{uffiziTickets.length !== 1 ? 's' : ''}
                                </span>
                              </h3>
                            </div>
                            <div className="overflow-x-auto">
                              {uffiziTickets.length === 0 ? (
                                <p className="text-gray-500 text-center py-4">No Uffizi tickets for this date</p>
                              ) : (
                                <table className="min-w-full divide-y divide-gray-200">
                                  <thead className="bg-gray-50">
                                    <tr>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Time
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <span className="hidden sm:inline">Code</span>
                                        <span className="sm:hidden">#</span>
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <span className="hidden sm:inline">Quantity</span>
                                        <span className="sm:hidden">Qty</span>
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                      </th>
                                    </tr>
                                  </thead>
                                  <tbody className="bg-white divide-y divide-gray-200">
                                    {uffiziTickets.map((ticket) => (
                                      <tr key={ticket.id} className="hover:bg-gray-50">
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                          {ticket.time || 'No time'}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                                          {ticket.code}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                          <span className="hidden sm:inline">Quantity: </span>{ticket.quantity}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                          {isAdmin() && (
                                            <>
                                              <button
                                                onClick={() => startEdit(ticket)}
                                                className="text-blue-600 hover:text-blue-900 mr-3"
                                                title="Edit ticket"
                                              >
                                                <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                              </button>
                                              <button
                                                onClick={() => confirmDelete(ticket)}
                                                className="text-red-600 hover:text-red-900"
                                                title="Delete ticket"
                                              >
                                                <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                              </button>
                                            </>
                                          )}
                                        </td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              )}
                            </div>
                          </div>
                        );
                      })()}

                      {/* Accademia Section */}
                      {(() => {
                        const accademiaTickets = currentTickets.filter(ticket => 
                          ticket.location.toLowerCase() === 'accademia'
                        ).sort((a, b) => {
                          const timeA = a.time || '00:00';
                          const timeB = b.time || '00:00';
                          return timeA.localeCompare(timeB);
                        });

                        return (
                          <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div className="bg-green-500 text-white px-4 py-3">
                              <h3 className="font-medium text-lg flex items-center">
                                <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 14l9-5-9-5-9 5 9 5z" />
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 14l9-5-9-5-9 5 9 5z" />
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 14l9-5-9-5-9 5 9 5z" />
                                </svg>
                                Accademia Gallery - {format(filterDate, "dd/MM/yyyy")}
                                <span className="ml-auto text-sm bg-green-600 px-2 py-1 rounded">
                                  {accademiaTickets.length} ticket{accademiaTickets.length !== 1 ? 's' : ''}
                                </span>
                              </h3>
                            </div>
                            <div className="overflow-x-auto">
                              {accademiaTickets.length === 0 ? (
                                <p className="text-gray-500 text-center py-4">No Accademia tickets for this date</p>
                              ) : (
                                <table className="min-w-full divide-y divide-gray-200">
                                  <thead className="bg-gray-50">
                                    <tr>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Time
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <span className="hidden sm:inline">Code</span>
                                        <span className="sm:hidden">#</span>
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <span className="hidden sm:inline">Quantity</span>
                                        <span className="sm:hidden">Qty</span>
                                      </th>
                                      <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                      </th>
                                    </tr>
                                  </thead>
                                  <tbody className="bg-white divide-y divide-gray-200">
                                    {accademiaTickets.map((ticket) => (
                                      <tr key={ticket.id} className="hover:bg-gray-50">
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                          {ticket.time || 'No time'}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                                          {ticket.code}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                          <span className="hidden sm:inline">Quantity: </span>{ticket.quantity}
                                        </td>
                                        <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                          {isAdmin() && (
                                            <>
                                              <button
                                                onClick={() => startEdit(ticket)}
                                                className="text-blue-600 hover:text-blue-900 mr-3"
                                                title="Edit ticket"
                                              >
                                                <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                </svg>
                                              </button>
                                              <button
                                                onClick={() => confirmDelete(ticket)}
                                                className="text-red-600 hover:text-red-900"
                                                title="Delete ticket"
                                              >
                                                <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </svg>
                                              </button>
                                            </>
                                          )}
                                        </td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              )}
                            </div>
                          </div>
                        );
                      })()}
                    </div>
                  ) : (
                    // Original view when no date is selected - group by date in tables
                    <div className="space-y-6">
                      {/* Showing info */}
                      <div className="text-sm text-gray-500 mb-4">
                        Showing {indexOfFirstTicket + 1}-{Math.min(indexOfLastTicket, filteredTickets.length)} of {filteredTickets.length} tickets
                      </div>
                      
                      {Object.entries(currentTicketsByDate)
                        .sort(([dateA], [dateB]) => {
                          // Sort date groups by priority (Today, Tomorrow, Future, Past)
                          const getPriority = (dateStr) => {
                            if (isToday(dateStr)) return 1;
                            if (isTomorrow(dateStr)) return 2;
                            if (isPastDate(dateStr)) return 4;
                            return 3; // Future dates
                          };
                          
                          const priorityA = getPriority(dateA);
                          const priorityB = getPriority(dateB);
                          
                          if (priorityA !== priorityB) {
                            return priorityA - priorityB;
                          }
                          
                          // If same priority, sort by date
                          if (priorityA === 3) { // Future dates - ascending
                            return new Date(dateA) - new Date(dateB);
                          } else if (priorityA === 4) { // Past dates - descending (most recent first)
                            return new Date(dateB) - new Date(dateA);
                          }
                          
                          return 0;
                        })
                        .map(([date, dateTickets]) => (
                          <div key={date} className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div className={`px-4 py-3 ${getDateColorClass(date)}`}>
                              <h3 className="font-medium text-lg">
                                {getDateLabel(date)} - {format(new Date(date), "dd/MM/yyyy")}
                              </h3>
                            </div>
                            <div className="overflow-x-auto">
                              <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                  <tr>
                                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                      Location
                                    </th>
                                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                      Time
                                    </th>
                                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                      <span className="hidden sm:inline">Code</span>
                                      <span className="sm:hidden">#</span>
                                    </th>
                                    <th className="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                      <span className="hidden sm:inline">Quantity</span>
                                      <span className="sm:hidden">Qty</span>
                                    </th>
                                    <th className="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                      Actions
                                    </th>
                                  </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                  {dateTickets.map((ticket) => (
                                    <tr key={ticket.id} className="hover:bg-gray-50">
                                      <td className="px-3 sm:px-6 py-4 whitespace-nowrap">
                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                          ticket.location && ticket.location.toLowerCase() === 'uffizi'
                                            ? 'bg-blue-100 text-blue-800'
                                            : 'bg-green-100 text-green-800'
                                        }`}>
                                          {ticket.location || 'Unknown'}
                                        </span>
                                      </td>
                                      <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {ticket.time || 'No time'}
                                      </td>
                                      <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-mono">
                                        {ticket.code}
                                      </td>
                                      <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <span className="hidden sm:inline">Quantity: </span>{ticket.quantity}
                                      </td>
                                      <td className="px-3 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        {isAdmin() && (
                                          <>
                                            <button
                                              onClick={() => startEdit(ticket)}
                                              className="text-blue-600 hover:text-blue-900 mr-3"
                                              title="Edit ticket"
                                            >
                                              <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                              </svg>
                                            </button>
                                            <button
                                              onClick={() => confirmDelete(ticket)}
                                              className="text-red-600 hover:text-red-900"
                                              title="Delete ticket"
                                            >
                                              <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                              </svg>
                                            </button>
                                          </>
                                        )}
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          </div>
                        ))}
                    </div>
                  )}
                  
                  {/* Pagination UI */}
                  {totalPages > 1 && (
                    <div className="mt-8 flex flex-col sm:flex-row justify-between items-center gap-4">
                      {/* Items per page selector */}
                      <div className="flex items-center space-x-2">
                        <label htmlFor="itemsPerPage" className="text-sm text-gray-600">
                          Tickets per page:
                        </label>
                        <select 
                          id="itemsPerPage" 
                          value={ticketsPerPage} 
                          onChange={handleItemsPerPageChange}
                          className="rounded-md border border-gray-300 text-sm py-1 px-2 bg-white shadow-sm focus:outline-none focus:ring-1 focus:ring-purple-500"
                        >
                          {ITEMS_PER_PAGE_OPTIONS.map(option => (
                            <option key={option} value={option}>{option}</option>
                          ))}
                        </select>
                      </div>
                      
                      {/* Pagination controls */}
                      <nav className="flex items-center justify-center space-x-1" aria-label="Pagination">
                        {/* Previous button */}
                        <button
                          onClick={() => handlePageChange(Math.max(1, currentPage - 1))}
                          disabled={currentPage === 1}
                          className={`relative inline-flex items-center px-2 py-2 rounded-l-md border text-sm font-medium ${
                            currentPage === 1
                              ? 'bg-gray-100 border-gray-300 text-gray-400 cursor-not-allowed'
                              : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                          }`}
                        >
                          <span className="sr-only">Previous</span>
                          <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clipRule="evenodd" />
                          </svg>
                        </button>
                        
                        {/* Page numbers */}
                        {paginationRange.map((page, idx) => {
                          // If it's ellipsis, render a static element
                          if (page === '...') {
                            return (
                              <span key={`ellipsis-${idx}`} className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                ...
                              </span>
                            );
                          }
                          
                          // Otherwise render a page button
                          return (
                            <button
                              key={idx}
                              onClick={() => handlePageChange(page)}
                              className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${
                                currentPage === page
                                  ? 'z-10 bg-purple-50 border-purple-500 text-purple-600'
                                  : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                              }`}
                            >
                              {page}
                            </button>
                          );
                        })}
                        
                        {/* Next button */}
                        <button
                          onClick={() => handlePageChange(Math.min(totalPages, currentPage + 1))}
                          disabled={currentPage === totalPages}
                          className={`relative inline-flex items-center px-2 py-2 rounded-r-md border text-sm font-medium ${
                            currentPage === totalPages
                              ? 'bg-gray-100 border-gray-300 text-gray-400 cursor-not-allowed'
                              : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                          }`}
                        >
                          <span className="sr-only">Next</span>
                          <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
                          </svg>
                        </button>
                      </nav>
                    </div>
                  )}
                </>
              );
            })()}
          </>
        )}
      </div>
    </div>
  );
};

export default Tickets; 