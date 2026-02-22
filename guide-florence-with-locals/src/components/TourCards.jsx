import React, { useEffect } from 'react';
import { format } from 'date-fns';
import { Link } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

// This is a component for displaying tour cards with Tuscan theme
const TourCards = ({
  tours,
  onPaidToggle,
  onDelete,
  onCancelTour,
  isPastTour,
  isToday,
  isTomorrow,
  isDayAfterTomorrow,
  isFutureDate,
  calculatePrice
}) => {
  const { isAdmin } = useAuth();

  // Enhanced logging for troubleshooting
  useEffect(() => {
    console.log('TourCards component mounted');
    console.log('TourCards received tours:', tours);
    console.log('Tours length:', tours.length);

    // Force any parent tables to be hidden
    const style = document.createElement('style');
    style.innerHTML = `
      #tours-list table,
      #tours-list thead,
      #tours-list tbody,
      #tours-list tr,
      #tours-list th,
      #tours-list td {
        display: none !important;
      }

      .card-view-container {
        display: block !important;
      }
    `;
    document.head.appendChild(style);

    return () => {
      // Clean up when component unmounts
      document.head.removeChild(style);
    };
  }, [tours]);

  // Function to determine card class based on tour date/time (Tuscan colors)
  const getCardClass = (dateString, timeString, isCancelled) => {
    if (isCancelled) {
      return "bg-terracotta-50 border-terracotta-200";
    } else if (isPastTour(dateString, timeString)) {
      return "bg-stone-200 border-stone-300";
    } else if (isToday(dateString, timeString)) {
      return "bg-gold-100 border-gold-300";
    } else if (isTomorrow(dateString)) {
      return "bg-olive-100 border-olive-300";
    } else if (isDayAfterTomorrow(dateString)) {
      return "bg-olive-50 border-olive-200";
    } else if (isFutureDate(dateString)) {
      return "bg-renaissance-50 border-renaissance-200";
    }
    return "bg-white";
  };

  // Function to determine header class based on tour date/time (Tuscan colors)
  const getHeaderClass = (dateString, timeString, isCancelled) => {
    if (isCancelled) {
      return "bg-terracotta-400 text-white";
    } else if (isPastTour(dateString, timeString)) {
      return "bg-stone-400 text-white";
    } else if (isToday(dateString, timeString)) {
      return "bg-gold-500 text-white";
    } else if (isTomorrow(dateString)) {
      return "bg-olive-500 text-white";
    } else if (isDayAfterTomorrow(dateString)) {
      return "bg-olive-400 text-white";
    } else if (isFutureDate(dateString)) {
      return "bg-renaissance-500 text-white";
    }
    return "bg-stone-100 text-stone-800";
  };

  if (!tours || tours.length === 0) {
    return (
      <div className="text-center py-8 bg-white rounded-tuscan-lg border border-stone-200 shadow-tuscan">
        <div className="flex justify-center items-center">
          <div className="w-12 h-12 text-terracotta-500">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7" />
            </svg>
          </div>
        </div>
        <h3 className="text-lg font-semibold text-stone-800 mt-2 mb-2">No tours available</h3>
        <p className="text-stone-500 mb-4">No tours match your current filters</p>
      </div>
    );
  }

  // Debug: log render phase
  console.log('TourCards rendering grid with tours:', tours.length);

  // Force this to be a card layout with a grid
  return (
    <div className="grid grid-cols-1 gap-3">
      {tours.map((tour) => {
        // Check if this is a completed unpaid tour (excluding cancelled tours)
        const isCompletedUnpaid = isPastTour(tour.date, tour.time) && !tour.paid && !tour.cancelled;
        const tourPrice = calculatePrice(tour.duration);

        return (
          <div
            key={tour.id}
            className={`rounded-tuscan-lg border overflow-hidden shadow-tuscan ${getCardClass(tour.date, tour.time, tour.cancelled)} ${isCompletedUnpaid ? 'completed-unpaid-tour' : ''}`}
          >
            <div className={`px-3 py-2 ${getHeaderClass(tour.date, tour.time, tour.cancelled)}`}>
              <div className="flex justify-between items-center">
                <h3 className="font-medium text-sm">
                  {format(new Date(tour.date), "dd/MM/yyyy")} • {tour.time}
                </h3>
                <div className="flex items-center space-x-2">
                  {!tour.cancelled && (
                    <button
                      onClick={() => onPaidToggle(tour.id, tour.paid)}
                      className={`text-xs px-2 py-1 rounded-full flex items-center transition-colors duration-200 ${
                        tour.paid
                          ? 'bg-olive-100 text-olive-800 hover:bg-terracotta-100 hover:text-terracotta-800'
                          : 'bg-terracotta-100 text-terracotta-800 hover:bg-olive-100 hover:text-olive-800'
                      }`}
                    >
                      <span className={`w-2 h-2 rounded-full mr-1.5 ${tour.paid ? 'bg-olive-500' : 'bg-terracotta-500'}`}></span>
                      {tour.paid ? 'Paid' : 'Unpaid'}
                    </button>
                  )}

                  {tour.cancelled && (
                    <span className="text-xs px-2 py-1 rounded-full flex items-center bg-terracotta-100 text-terracotta-800">
                      <span className="w-2 h-2 rounded-full mr-1.5 bg-terracotta-500"></span>
                      Cancelled
                    </span>
                  )}
                </div>
              </div>
            </div>
            <div className="p-3">
              {tour.cancelled && (
                <div className="bg-terracotta-50 border-l-4 border-terracotta-400 text-terracotta-700 p-2 mb-2 rounded-tuscan">
                  <div className="flex">
                    <svg className="h-4 w-4 text-terracotta-400 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <p className="text-sm">This tour has been cancelled.</p>
                  </div>
                </div>
              )}

              {/* Desktop: Horizontal layout, Mobile: Vertical */}
              <div className="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
                {/* Left side: Title and Description */}
                <div className="flex-1 min-w-0">
                  <h2 className="text-stone-900 font-medium text-base mb-1">{tour.title}</h2>
                  {tour.description && (
                    <p className="text-stone-600 text-sm">{tour.description}</p>
                  )}
                </div>

                {/* Right side: Details */}
                <div className="flex flex-col md:flex-col lg:flex-row lg:gap-8 md:flex-shrink-0 text-sm">
                  <div className="space-y-1">
                    <div className="flex items-center">
                      <svg className="h-3 w-3 mr-1.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                      </svg>
                      <span className="text-stone-700">{tour.guide_name}</span>
                    </div>
                    <div className="flex items-center">
                      <svg className="h-3 w-3 mr-1.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span className="text-stone-700">{tour.duration}</span>
                    </div>
                  </div>

                  <div className="space-y-1 mt-1 md:mt-0">
                    {tour.booking_channel && (
                      <div className="flex items-center">
                        <svg className="h-3 w-3 mr-1.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="text-renaissance-600 font-medium">{tour.booking_channel}</span>
                      </div>
                    )}
                    <div className="flex items-center">
                      <svg className="h-3 w-3 mr-1.5 text-stone-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <span className="font-medium text-stone-900">{tourPrice} EUR</span>
                    </div>
                  </div>
                </div>
              </div>
              <div className="flex justify-end space-x-2 mt-2">
                {isAdmin() && (
                  <>
                    {!tour.cancelled && isFutureDate(tour.date) && (
                      <button
                        onClick={() => onCancelTour(tour.id)}
                        className="px-3 py-1 text-sm text-gold-700 hover:text-gold-900 hover:bg-gold-50 rounded-tuscan transition-colors duration-200"
                      >
                        Cancel Tour
                      </button>
                    )}
                    <button
                      onClick={() => onDelete(tour)}
                      className="px-3 py-1 text-sm text-terracotta-600 hover:text-terracotta-800 hover:bg-terracotta-50 rounded-tuscan transition-colors duration-200"
                    >
                      Delete
                    </button>
                    <Link
                      to={`/tours/${tour.id}/edit`}
                      className="px-3 py-1 text-sm text-olive-600 hover:text-olive-800 hover:bg-olive-50 rounded-tuscan transition-colors duration-200"
                    >
                      Edit
                    </Link>
                  </>
                )}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default TourCards;
