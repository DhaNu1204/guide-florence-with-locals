import React, { useState, useEffect, useRef, useCallback } from 'react';
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
  const [isMobile, setIsMobile] = useState(false);

  // Swipe to close state
  const [touchStart, setTouchStart] = useState(null);
  const [touchDelta, setTouchDelta] = useState(0);
  const modalRef = useRef(null);

  // Detect mobile viewport
  useEffect(() => {
    const checkMobile = () => setIsMobile(window.innerWidth < 768);
    checkMobile();
    window.addEventListener('resize', checkMobile);
    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  useEffect(() => {
    if (isOpen && ticket) {
      const extractedDetails = extractBookingDetails(ticket);
      setDetails(extractedDetails);
      setNotes(extractedDetails.notes);
      setTouchDelta(0); // Reset swipe state
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

  // Swipe to close handlers (mobile only)
  const handleTouchStart = useCallback((e) => {
    if (!isMobile) return;
    setTouchStart(e.touches[0].clientY);
  }, [isMobile]);

  const handleTouchMove = useCallback((e) => {
    if (!isMobile || touchStart === null) return;
    const currentTouch = e.touches[0].clientY;
    const delta = currentTouch - touchStart;
    // Only allow downward swipe
    if (delta > 0) {
      setTouchDelta(delta);
    }
  }, [isMobile, touchStart]);

  const handleTouchEnd = useCallback(() => {
    if (!isMobile) return;
    // Close if swiped down more than 100px
    if (touchDelta > 100) {
      onClose();
    }
    setTouchStart(null);
    setTouchDelta(0);
  }, [isMobile, touchDelta, onClose]);

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
      case 'CONFIRMED': return 'bg-olive-100 text-olive-800';
      case 'CANCELLED': return 'bg-terracotta-100 text-terracotta-800';
      case 'PENDING': return 'bg-gold-100 text-gold-800';
      default: return 'bg-stone-100 text-stone-800';
    }
  };

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      {/* Backdrop */}
      <div
        className={`fixed inset-0 bg-black transition-opacity duration-300 ${isMobile ? 'bg-opacity-60' : 'bg-opacity-50'}`}
        onClick={onClose}
      />

      {/* Modal Container - Different layout for mobile vs desktop */}
      <div className={`
        ${isMobile
          ? 'fixed inset-x-0 bottom-0 flex flex-col'
          : 'flex min-h-screen items-center justify-center p-4'
        }
      `}>
        <div
          ref={modalRef}
          className={`
            relative bg-white shadow-2xl transform transition-all duration-300 ease-out
            ${isMobile
              ? 'w-full rounded-t-2xl max-h-[90vh] animate-slide-in-bottom'
              : 'rounded-xl w-full max-w-3xl mx-auto'
            }
          `}
          style={isMobile && touchDelta > 0 ? {
            transform: `translateY(${touchDelta}px)`,
            transition: 'none'
          } : {}}
          onClick={(e) => e.stopPropagation()}
          onTouchStart={handleTouchStart}
          onTouchMove={handleTouchMove}
          onTouchEnd={handleTouchEnd}
        >
          {/* Mobile Swipe Handle */}
          {isMobile && (
            <div className="flex justify-center pt-3 pb-1 touch-manipulation">
              <div className="w-12 h-1.5 bg-stone-300 rounded-full" />
            </div>
          )}

          {/* Header */}
          <div className={`
            flex items-center justify-between border-b border-stone-200 bg-gradient-to-r from-terracotta-500 to-terracotta-700
            ${isMobile ? 'p-4' : 'p-6'}
          `}>
            <h2 className={`font-bold text-white flex items-center ${isMobile ? 'text-lg' : 'text-2xl'}`}>
              <FiFileText className={isMobile ? 'mr-2' : 'mr-3'} />
              Booking Details
            </h2>
            <button
              onClick={onClose}
              className="text-white hover:text-stone-200 transition-colors p-3 min-h-[44px] min-w-[44px] hover:bg-white hover:bg-opacity-20 rounded-tuscan-lg touch-manipulation active:bg-white active:bg-opacity-30 flex items-center justify-center"
            >
              <FiX className="text-2xl" />
            </button>
          </div>

          {/* Content - Scrollable area */}
          <div className={`
            overflow-y-auto overscroll-contain
            ${isMobile
              ? 'p-4 max-h-[calc(90vh-180px)]'
              : 'p-6 max-h-[calc(100vh-200px)]'
            }
          `}>
            <div className={isMobile ? 'space-y-4' : 'space-y-6'}>
              {/* Tour Information */}
              <section>
                <div className="flex items-center mb-3">
                  <FiCalendar className="text-terracotta-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-stone-900">Tour Information</h3>
                </div>
                <div className="bg-stone-50 rounded-tuscan-lg p-4 space-y-2">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <span className="text-sm font-medium text-stone-600">Museum:</span>
                      <p className="text-stone-900 font-medium">{details.tour.museum || 'N/A'}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-stone-600">Date:</span>
                      <p className="text-stone-900 font-medium">{formatDate(details.tour.date)}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-stone-600">Time:</span>
                      <p className="text-stone-900 font-medium">{details.tour.time}</p>
                    </div>
                    {details.tour.duration && (
                      <div>
                        <span className="text-sm font-medium text-stone-600">Duration:</span>
                        <p className="text-stone-900 font-medium">{details.tour.duration}</p>
                      </div>
                    )}
                  </div>
                  <div className="pt-2 border-t border-stone-200">
                    <span className="text-sm font-medium text-stone-600">Tour:</span>
                    <p className="text-stone-900">{details.tour.title}</p>
                  </div>
                </div>
              </section>

              {/* Main Contact */}
              <section>
                <div className="flex items-center mb-3">
                  <FiUser className="text-renaissance-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-stone-900">Main Contact</h3>
                </div>
                <div className="bg-stone-50 rounded-tuscan-lg p-4 space-y-3">
                  <div>
                    <span className="text-sm font-medium text-stone-600">Name:</span>
                    <p className="text-stone-900 font-medium">
                      {details.mainContact.firstName} {details.mainContact.lastName}
                    </p>
                  </div>
                  {details.mainContact.email && (
                    <div className="flex items-center">
                      <FiMail className="text-stone-400 mr-2" />
                      <div className="flex-1">
                        <span className="text-sm font-medium text-stone-600">Email:</span>
                        <p className="text-stone-900 break-all">{details.mainContact.email}</p>
                      </div>
                    </div>
                  )}
                  {details.mainContact.phone && (
                    <div className="flex items-center">
                      <FiPhone className="text-stone-400 mr-2" />
                      <div className="flex-1">
                        <span className="text-sm font-medium text-stone-600">Phone:</span>
                        <p className="text-stone-900">{details.mainContact.phone}</p>
                      </div>
                    </div>
                  )}
                </div>
              </section>

              {/* Participants */}
              <section>
                <div className="flex items-center mb-3">
                  <FiUsers className="text-olive-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-stone-900">Participants</h3>
                </div>
                <div className="bg-stone-50 rounded-tuscan-lg p-4">
                  <div className="grid grid-cols-3 gap-4 mb-3">
                    <div className="text-center p-3 bg-white rounded-tuscan-lg shadow-tuscan-sm">
                      <p className="text-sm text-stone-600">Adults</p>
                      <p className="text-2xl font-bold text-stone-900">{details.participants.adults}</p>
                    </div>
                    <div className="text-center p-3 bg-white rounded-tuscan-lg shadow-tuscan-sm">
                      <p className="text-sm text-stone-600">Children</p>
                      <p className="text-2xl font-bold text-stone-900">{details.participants.children}</p>
                    </div>
                    <div className="text-center p-3 bg-terracotta-50 rounded-tuscan-lg shadow-tuscan-sm">
                      <p className="text-sm text-terracotta-600">Total</p>
                      <p className="text-2xl font-bold text-terracotta-700">{details.participants.total}</p>
                    </div>
                  </div>
                  <p className="text-xs text-stone-500 italic flex items-center">
                    <FiFileText className="mr-1" />
                    Individual names not provided by {details.booking.channel}
                  </p>
                </div>
              </section>

              {/* Booking Details */}
              <section>
                <div className="flex items-center mb-3">
                  <FiTag className="text-gold-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-stone-900">Booking Details</h3>
                </div>
                <div className="bg-stone-50 rounded-tuscan-lg p-4 space-y-3">
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <div>
                      <span className="text-sm font-medium text-stone-600">Channel:</span>
                      <p className="text-stone-900 font-medium">{details.booking.channel}</p>
                    </div>
                    <div>
                      <span className="text-sm font-medium text-stone-600">Status:</span>
                      <span className={`inline-block px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(details.booking.status)}`}>
                        {details.booking.status}
                      </span>
                    </div>
                    {details.booking.confirmationCode && (
                      <div>
                        <span className="text-sm font-medium text-stone-600">Confirmation Code:</span>
                        <p className="text-stone-900 font-mono text-sm">{details.booking.confirmationCode}</p>
                      </div>
                    )}
                    {details.booking.externalReference && (
                      <div>
                        <span className="text-sm font-medium text-stone-600">External Reference:</span>
                        <p className="text-stone-900 font-mono text-sm">{details.booking.externalReference}</p>
                      </div>
                    )}
                    {details.booking.totalPrice && (
                      <div>
                        <span className="text-sm font-medium text-stone-600">Total Price:</span>
                        <p className="text-stone-900 font-medium">
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
                    <FiFileText className="text-gold-600 text-xl mr-2" />
                    <h3 className="text-lg font-semibold text-stone-900">Special Requests</h3>
                  </div>
                  <div className="bg-gold-50 border border-gold-200 rounded-tuscan-lg p-4">
                    <p className="text-stone-900 whitespace-pre-wrap">{details.specialRequests}</p>
                  </div>
                </section>
              )}

              {/* Internal Notes */}
              <section>
                <div className="flex items-center mb-3">
                  <FiFileText className="text-renaissance-600 text-xl mr-2" />
                  <h3 className="text-lg font-semibold text-stone-900">Internal Notes</h3>
                </div>
                <div className="bg-stone-50 rounded-tuscan-lg p-4">
                  <textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    className="w-full px-3 py-2 border border-stone-300 rounded-tuscan-lg focus:ring-2 focus:ring-terracotta-500 focus:border-terracotta-500 resize-none"
                    rows="4"
                    placeholder="Add internal notes here..."
                  />
                  <button
                    onClick={handleSaveNotes}
                    disabled={isSaving || notes === details.notes}
                    className="mt-3 flex items-center px-4 py-3 min-h-[44px] bg-terracotta-500 text-white rounded-tuscan-lg hover:bg-terracotta-600 active:bg-terracotta-700 disabled:bg-stone-300 disabled:cursor-not-allowed transition-colors touch-manipulation font-medium shadow-tuscan"
                  >
                    <FiSave className="mr-2" />
                    {isSaving ? 'Saving...' : 'Save Notes'}
                  </button>
                </div>
              </section>
            </div>
          </div>

          {/* Footer */}
          <div className={`
            flex justify-end border-t border-stone-200 bg-stone-50
            ${isMobile ? 'p-4 pb-safe' : 'p-6'}
          `}>
            <button
              onClick={onClose}
              className="px-6 py-3 min-h-[44px] bg-stone-600 text-white rounded-tuscan-lg hover:bg-stone-700 active:bg-stone-800 transition-colors touch-manipulation font-medium shadow-tuscan"
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
