import React from 'react';
import {
  FiCalendar,
  FiClock,
  FiUsers,
  FiMapPin,
  FiUser,
  FiGlobe,
  FiExternalLink
} from 'react-icons/fi';

/**
 * TourCard Component
 * A professional card component for displaying tour information
 * with Tuscan styling and smooth animations
 */

// Status configurations with Tuscan colors
const statusConfig = {
  confirmed: {
    label: 'Confirmed',
    bg: 'bg-olive-100',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500'
  },
  pending: {
    label: 'Pending',
    bg: 'bg-gold-100',
    text: 'text-gold-700',
    border: 'border-gold-200',
    dot: 'bg-gold-500'
  },
  cancelled: {
    label: 'Cancelled',
    bg: 'bg-terracotta-100',
    text: 'text-terracotta-700',
    border: 'border-terracotta-200',
    dot: 'bg-terracotta-500'
  }
};

// Payment status configurations
const paymentConfig = {
  paid: {
    label: 'Paid',
    bg: 'bg-olive-50',
    text: 'text-olive-700'
  },
  partial: {
    label: 'Partial',
    bg: 'bg-gold-50',
    text: 'text-gold-700'
  },
  unpaid: {
    label: 'Unpaid',
    bg: 'bg-terracotta-50',
    text: 'text-terracotta-700'
  }
};

// Default tour images by museum type
const defaultImages = {
  uffizi: '/images/uffizi.jpg',
  accademia: '/images/accademia.jpg',
  default: '/images/florence.jpg'
};

const TourCard = ({
  tour,
  onClick,
  onAssignGuide,
  showImage = true,
  variant = 'default', // 'default', 'compact', 'detailed'
  className = ''
}) => {
  // Determine status
  const getStatus = () => {
    if (tour.cancelled) return 'cancelled';
    if (tour.confirmed || tour.status === 'CONFIRMED') return 'confirmed';
    return 'pending';
  };

  // Determine payment status
  const getPaymentStatus = () => {
    if (tour.payment_status === 'paid' || tour.paid) return 'paid';
    if (tour.payment_status === 'partial') return 'partial';
    return 'unpaid';
  };

  // Get appropriate image
  const getImage = () => {
    if (tour.image) return tour.image;
    if (tour.title?.toLowerCase().includes('uffizi')) return defaultImages.uffizi;
    if (tour.title?.toLowerCase().includes('accademia')) return defaultImages.accademia;
    return defaultImages.default;
  };

  // Check if guide is assigned
  const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';

  const status = getStatus();
  const paymentStatus = getPaymentStatus();
  const statusStyle = statusConfig[status];
  const paymentStyle = paymentConfig[paymentStatus];

  // Format date nicely
  const formatDate = (dateStr) => {
    try {
      const date = new Date(dateStr);
      return date.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short'
      });
    } catch {
      return dateStr;
    }
  };

  // Compact variant
  if (variant === 'compact') {
    return (
      <div
        onClick={onClick}
        className={`
          group flex items-center gap-3 p-3
          bg-white rounded-tuscan-lg border border-stone-200/50
          shadow-tuscan-sm hover:shadow-tuscan transition-all duration-200
          cursor-pointer touch-manipulation
          ${status === 'cancelled' ? 'opacity-60' : ''}
          ${className}
        `}
      >
        {/* Status dot */}
        <div className={`w-2 h-2 rounded-full ${statusStyle.dot} flex-shrink-0`} />

        {/* Main info */}
        <div className="flex-1 min-w-0">
          <h4 className="font-medium text-stone-800 truncate group-hover:text-terracotta-600 transition-colors">
            {tour.title}
          </h4>
          <div className="flex items-center gap-2 text-sm text-stone-500 mt-0.5">
            <span>{formatDate(tour.date)}</span>
            <span>•</span>
            <span>{tour.time}</span>
          </div>
        </div>

        {/* Guide badge */}
        <span className={`text-xs font-medium px-2 py-1 rounded-full ${
          hasGuide ? 'bg-olive-100 text-olive-700' : 'bg-gold-100 text-gold-700'
        }`}>
          {hasGuide ? tour.guide_name : 'Unassigned'}
        </span>
      </div>
    );
  }

  // Default and detailed variants
  return (
    <div
      onClick={onClick}
      className={`
        group bg-white rounded-tuscan-xl border border-stone-200/50
        shadow-tuscan hover:shadow-card-hover
        transition-all duration-300 overflow-hidden
        cursor-pointer touch-manipulation
        ${status === 'cancelled' ? 'opacity-70' : ''}
        ${className}
      `}
    >
      {/* Image section with hover zoom */}
      {showImage && (
        <div className="relative h-40 md:h-48 overflow-hidden bg-stone-100">
          <img
            src={getImage()}
            alt={tour.title}
            className="w-full h-full object-cover transform group-hover:scale-105 transition-transform duration-500"
            onError={(e) => {
              e.target.src = defaultImages.default;
            }}
          />
          {/* Gradient overlay */}
          <div className="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent" />

          {/* Status badge on image */}
          <div className="absolute top-3 left-3">
            <span className={`
              inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
              ${statusStyle.bg} ${statusStyle.text} ${statusStyle.border} border
              backdrop-blur-sm
            `}>
              <span className={`w-1.5 h-1.5 rounded-full ${statusStyle.dot} mr-1.5`} />
              {statusStyle.label}
            </span>
          </div>

          {/* Booking channel badge */}
          {tour.booking_channel && (
            <div className="absolute top-3 right-3">
              <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-white/90 text-stone-700 backdrop-blur-sm">
                <FiExternalLink className="mr-1" size={10} />
                {tour.booking_channel}
              </span>
            </div>
          )}

          {/* Date overlay on image */}
          <div className="absolute bottom-3 left-3 right-3">
            <div className="flex items-center gap-3 text-white text-sm">
              <span className="flex items-center">
                <FiCalendar className="mr-1.5" />
                {formatDate(tour.date)}
              </span>
              <span className="flex items-center">
                <FiClock className="mr-1.5" />
                {tour.time}
              </span>
            </div>
          </div>
        </div>
      )}

      {/* Content section */}
      <div className="p-4 md:p-5">
        {/* Title */}
        <h3 className="font-semibold text-lg text-stone-900 group-hover:text-terracotta-600 transition-colors line-clamp-2">
          {tour.title}
        </h3>

        {/* Info row */}
        <div className="flex flex-wrap items-center gap-3 mt-3 text-sm text-stone-600">
          {/* Participants */}
          <span className="flex items-center">
            <FiUsers className="mr-1.5 text-stone-400" />
            {tour.participants || 1} {parseInt(tour.participants) === 1 ? 'guest' : 'guests'}
          </span>

          {/* Language */}
          {tour.language && (
            <span className="flex items-center">
              <FiGlobe className="mr-1.5 text-stone-400" />
              {tour.language}
            </span>
          )}

          {/* Museum/Location */}
          {tour.museum && (
            <span className="flex items-center">
              <FiMapPin className="mr-1.5 text-stone-400" />
              {tour.museum}
            </span>
          )}
        </div>

        {/* Divider */}
        <div className="border-t border-stone-100 my-3" />

        {/* Bottom row - Guide and Payment */}
        <div className="flex items-center justify-between">
          {/* Guide assignment */}
          <div className="flex items-center">
            <div className={`
              w-8 h-8 rounded-full flex items-center justify-center mr-2
              ${hasGuide ? 'bg-olive-100' : 'bg-gold-100'}
            `}>
              <FiUser className={hasGuide ? 'text-olive-600' : 'text-gold-600'} size={14} />
            </div>
            <div>
              <p className={`text-sm font-medium ${hasGuide ? 'text-stone-800' : 'text-gold-700'}`}>
                {hasGuide ? tour.guide_name : 'Unassigned'}
              </p>
              {!hasGuide && onAssignGuide && (
                <button
                  onClick={(e) => {
                    e.stopPropagation();
                    onAssignGuide(tour);
                  }}
                  className="text-xs text-terracotta-600 hover:text-terracotta-700 font-medium"
                >
                  Assign guide →
                </button>
              )}
            </div>
          </div>

          {/* Payment status */}
          <span className={`
            inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
            ${paymentStyle.bg} ${paymentStyle.text}
          `}>
            {paymentStyle.label}
          </span>
        </div>

        {/* Detailed variant extras */}
        {variant === 'detailed' && (
          <>
            {/* Notes */}
            {tour.notes && (
              <div className="mt-3 p-3 bg-stone-50 rounded-tuscan text-sm text-stone-600">
                <p className="font-medium text-stone-700 mb-1">Notes:</p>
                <p className="line-clamp-2">{tour.notes}</p>
              </div>
            )}

            {/* Customer info */}
            {tour.customer_name && (
              <div className="mt-3 flex items-center text-sm text-stone-600">
                <span className="font-medium text-stone-700 mr-2">Customer:</span>
                <span>{tour.customer_name}</span>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
};

// Mini card for lists
export const TourCardMini = ({ tour, onClick }) => (
  <TourCard tour={tour} onClick={onClick} variant="compact" showImage={false} />
);

// Full card with all details
export const TourCardDetailed = ({ tour, onClick, onAssignGuide }) => (
  <TourCard tour={tour} onClick={onClick} onAssignGuide={onAssignGuide} variant="detailed" />
);

export default TourCard;
