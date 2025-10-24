import React, { useState, useEffect } from 'react';
import { FiX, FiCalendar, FiClock, FiUser, FiUsers, FiTag, FiMail, FiPhone, FiFileText, FiDollarSign, FiCheckCircle, FiSave } from 'react-icons/fi';

// Helper function to extract booking details from bokun_data
const extractBookingDetails = (ticket) => {
  const details = {
    // Default values
    mainContact: {
      firstName: ticket.customer_name?.split(' ')[0] || '',
      lastName: ticket.customer_name?.split(' ').slice(1).join(' ') || '',
      email: ticket.customer_email || '',
      phone: ticket.customer_phone || ''
    },
    participants: {
      adults: 0,
      children: 0,
      total: parseInt(ticket.participants) || 0
    },
    booking: {
      channel: ticket.booking_channel || 'Direct',
      confirmationCode: ticket.bokun_confirmation_code || '',
      externalReference: ticket.external_id || '',
      status: ticket.cancelled ? 'CANCELLED' : 'CONFIRMED',
      totalPrice: '',
      currency: ''
    },
    tour: {
      title: ticket.title || '',
      date: ticket.date || '',
      time: ticket.time || '',
      duration: ticket.duration || '',
      museum: ''
    },
    specialRequests: '',
    notes: ticket.notes || ''
  };

  // Determine museum type from title
  if (ticket.title?.includes('Uffizi')) {
    details.tour.museum = 'Uffizi Gallery';
  } else if (ticket.title?.includes('Accademia')) {
    details.tour.museum = 'Accademia Gallery';
  }

  // Parse bokun_data if available
  try {
    if (ticket.bokun_data) {
      const bokunData = JSON.parse(ticket.bokun_data);

      // Extract main contact from customer object
      if (bokunData.customer) {
        details.mainContact.firstName = bokunData.customer.firstName || details.mainContact.firstName;
        details.mainContact.lastName = bokunData.customer.lastName || details.mainContact.lastName;
        details.mainContact.email = bokunData.customer.email || details.mainContact.email;
        details.mainContact.phone = bokunData.customer.phoneNumber || details.mainContact.phone;
      }

      // Extract participant breakdown
      if (bokunData.productBookings && bokunData.productBookings[0]) {
        const productBooking = bokunData.productBookings[0];

        // Get participant breakdown
        if (productBooking.fields?.priceCategoryBookings) {
          let adults = 0;
          let children = 0;

          productBooking.fields.priceCategoryBookings.forEach(category => {
            const quantity = category.quantity || 0;
            const ticketCategory = category.pricingCategory?.ticketCategory || '';

            if (ticketCategory === 'ADULT') {
              adults += quantity;
            } else if (ticketCategory === 'CHILD') {
              children += quantity;
            }
            // INFANT is intentionally ignored (free)
          });

          details.participants.adults = adults;
          details.participants.children = children;
          details.participants.total = adults + children;
        }

        // Extract special requests
        if (productBooking.specialRequests) {
          details.specialRequests = productBooking.specialRequests.trim();
        }
      }

      // Extract booking references
      details.booking.confirmationCode = bokunData.confirmationCode || details.booking.confirmationCode;
      details.booking.externalReference = bokunData.externalBookingReference || details.booking.externalReference;

      // Extract pricing
      if (bokunData.productBookings?.[0]?.customerInvoice) {
        const invoice = bokunData.productBookings[0].customerInvoice;
        details.booking.totalPrice = invoice.total || '';
        details.booking.currency = invoice.currency || '';
      }

      // Extract status
      if (bokunData.productBookings?.[0]?.status) {
        details.booking.status = bokunData.productBookings[0].status;
      }
    }
  } catch (error) {
    console.error('Error parsing bokun_data:', error);
  }

  return details;
};

const BookingDetailsModal = ({ isOpen, onClose, ticket, onUpdateNotes }) => {
  const [notes, setNotes] = useState('');
  const [isSaving, setIsSaving] = useState(false);
  const [details, setDetails] = useState(null);

  useEffect(() => {
    if (isOpen && ticket) {
      const extractedDetails = extractBookingDetails(ticket);
      setDetails(extractedDetails);
      setNotes(extractedDetails.notes);
    }
  }, [isOpen, ticket]);

  // Handle ESC key to close modal
  useEffect(() => {
    const handleEsc = (e) => {
      if (e.key === 'Escape') {
        onClose();
      }
    };

    if (isOpen) {
      document.addEventListener('keydown', handleEsc);
      // Prevent body scroll when modal is open
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.removeEventListener('keydown', handleEsc);
      document.body.style.overflow = 'unset';
    };
  }, [isOpen, onClose]);

  const handleSaveNotes = async () => {
    setIsSaving(true);
    try {
      await onUpdateNotes(ticket.id, notes);
      // Update local details state
      setDetails(prev => ({ ...prev, notes }));
    } catch (error) {
      console.error('Error saving notes:', error);
    } finally {
      setIsSaving(false);
    }
  };

  if (!isOpen || !details) return null;

  const formatDate = (dateStr) => {
    try {
      const date = new Date(dateStr);
      return date.toLocaleDateString('en-GB', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
      });
    } catch (error) {
      return dateStr;
    }
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'CONFIRMED': return 'bg-green-100 text-green-800';
      case 'CANCELLED': return 'bg-red-100 text-red-800';
      case 'PENDING': return 'bg-yellow-100 text-yellow-800';
      default: return 'bg-gray-100 text-gray-800';
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
        onClick={onClose}
      />

      {/* Modal */}
      <div className="flex min-h-screen items-center justify-center p-4">
        <div
          className="relative bg-white rounded-xl shadow-2xl w-full max-w-3xl mx-auto transform transition-all"
          onClick={(e) => e.stopPropagation()}
        >
          {/* Header */}
          <div className="flex items-center justify-between p-6 border-b border-gray-200 bg-gradient-to-r from-blue-600 to-purple-600">
            <h2 className="text-2xl font-bold text-white flex items-center">
              <FiFileText className="mr-3" />
              Booking Details
            </h2>
            <button
              onClick={onClose}
              className="text-white hover:text-gray-200 transition-colors p-2 hover:bg-white hover:bg-opacity-20 rounded-lg"
            >
              <FiX className="text-2xl" />
            </button>
          </div>

          {/* Content */}
          <div className="p-6 max-h-[calc(100vh-200px)] overflow-y-auto">
            <div className="space-y-6">
              {/* Tour Information */}
              <section>
                <div className="flex items-center mb-3">
                  <FiCalendar className="text-blue-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-gray-900">Tour Information</h3>
                </div>
                <div className="bg-gray-50 rounded-lg p-4 space-y-2">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <span className="text-sm font-medium text-gray-600">Museum:</span>
                      <p className="text-gray-900 font-medium">{details.tour.museum || 'N/A'}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-gray-600">Date:</span>
                      <p className="text-gray-900 font-medium">{formatDate(details.tour.date)}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-gray-600">Time:</span>
                      <p className="text-gray-900 font-medium">{details.tour.time}</p>
                    </div>
                    {details.tour.duration && (
                      <div>
                        <span className="text-sm font-medium text-gray-600">Duration:</span>
                        <p className="text-gray-900 font-medium">{details.tour.duration}</p>
                      </div>
                    )}
                  </div>
                  <div className="pt-2 border-t border-gray-200">
                    <span className="text-sm font-medium text-gray-600">Tour:</span>
                    <p className="text-gray-900">{details.tour.title}</p>
                  </div>
                </div>
              </section>

              {/* Main Contact */}
              <section>
                <div className="flex items-center mb-3">
                  <FiUser className="text-purple-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-gray-900">Main Contact</h3>
                </div>
                <div className="bg-gray-50 rounded-lg p-4 space-y-3">
                  <div>
                    <span className="text-sm font-medium text-gray-600">Name:</span>
                    <p className="text-gray-900 font-medium">
                      {details.mainContact.firstName} {details.mainContact.lastName}
                    </p>
                  </div>
                  {details.mainContact.email && (
                    <div className="flex items-center">
                      <FiMail className="text-gray-400 mr-2" />
                      <div className="flex-1">
                        <span className="text-sm font-medium text-gray-600">Email:</span>
                        <p className="text-gray-900 break-all">{details.mainContact.email}</p>
                      </div>
                    </div>
                  )}
                  {details.mainContact.phone && (
                    <div className="flex items-center">
                      <FiPhone className="text-gray-400 mr-2" />
                      <div className="flex-1">
                        <span className="text-sm font-medium text-gray-600">Phone:</span>
                        <p className="text-gray-900">{details.mainContact.phone}</p>
                      </div>
                    </div>
                  )}
                </div>
              </section>

              {/* Participants */}
              <section>
                <div className="flex items-center mb-3">
                  <FiUsers className="text-green-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-gray-900">Participants</h3>
                </div>
                <div className="bg-gray-50 rounded-lg p-4">
                  <div className="grid grid-cols-3 gap-4 mb-3">
                    <div className="text-center p-3 bg-white rounded-lg">
                      <p className="text-sm text-gray-600">Adults</p>
                      <p className="text-2xl font-bold text-gray-900">{details.participants.adults}</p>
                    </div>
                    <div className="text-center p-3 bg-white rounded-lg">
                      <p className="text-sm text-gray-600">Children</p>
                      <p className="text-2xl font-bold text-gray-900">{details.participants.children}</p>
                    </div>
                    <div className="text-center p-3 bg-blue-50 rounded-lg">
                      <p className="text-sm text-blue-600">Total</p>
                      <p className="text-2xl font-bold text-blue-700">{details.participants.total}</p>
                    </div>
                  </div>
                  <p className="text-xs text-gray-500 italic flex items-center">
                    <FiFileText className="mr-1" />
                    Individual names not provided by {details.booking.channel}
                  </p>
                </div>
              </section>

              {/* Booking Details */}
              <section>
                <div className="flex items-center mb-3">
                  <FiTag className="text-orange-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-gray-900">Booking Details</h3>
                </div>
                <div className="bg-gray-50 rounded-lg p-4 space-y-3">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <span className="text-sm font-medium text-gray-600">Channel:</span>
                      <p className="text-gray-900 font-medium">{details.booking.channel}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-gray-600">Status:</span>
                      <span className={`inline-block px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(details.booking.status)}`}>
                        {details.booking.status}
                      </span>
                    </div>
                    {details.booking.confirmationCode && (
                      <div>
                        <span className="text-sm font-medium text-gray-600">Confirmation Code:</span>
                        <p className="text-gray-900 font-mono text-sm">{details.booking.confirmationCode}</p>
                      </div>
                    )}
                    {details.booking.externalReference && (
                      <div>
                        <span className="text-sm font-medium text-gray-600">External Reference:</span>
                        <p className="text-gray-900 font-mono text-sm">{details.booking.externalReference}</p>
                      </div>
                    )}
                    {details.booking.totalPrice && (
                      <div>
                        <span className="text-sm font-medium text-gray-600">Total Price:</span>
                        <p className="text-gray-900 font-medium">
                          {details.booking.currency} {details.booking.totalPrice}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              </section>

              {/* Special Requests */}
              {details.specialRequests && (
                <section>
                  <div className="flex items-center mb-3">
                    <FiFileText className="text-yellow-600 text-xl mr-2" />
                    <h3 className="text-lg font-semibold text-gray-900">Special Requests</h3>
                  </div>
                  <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <p className="text-gray-900 whitespace-pre-wrap">{details.specialRequests}</p>
                  </div>
                </section>
              )}

              {/* Internal Notes */}
              <section>
                <div className="flex items-center mb-3">
                  <FiFileText className="text-indigo-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-gray-900">Internal Notes</h3>
                </div>
                <div className="bg-gray-50 rounded-lg p-4">
                  <textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none"
                    rows="4"
                    placeholder="Add internal notes here..."
                  />
                  <button
                    onClick={handleSaveNotes}
                    disabled={isSaving || notes === details.notes}
                    className="mt-3 flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors"
                  >
                    <FiSave className="mr-2" />
                    {isSaving ? 'Saving...' : 'Save Notes'}
                  </button>
                </div>
              </section>
            </div>
          </div>

          {/* Footer */}
          <div className="flex justify-end p-6 border-t border-gray-200 bg-gray-50">
            <button
              onClick={onClose}
              className="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default BookingDetailsModal;
