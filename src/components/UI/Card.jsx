import React from 'react';

const Card = ({
  children,
  className = '',
  padding = 'p-6',
  shadow = 'shadow-tuscan',
  hover = false,
  gradient = false,
  borderColor = '',
  variant = 'default', // 'default', 'tuscan', 'terracotta', 'olive', 'gold'
  ...props
}) => {
  const variantStyles = {
    default: 'bg-white border-stone-200/50',
    tuscan: 'bg-tuscan-gradient border-stone-200/50',
    terracotta: 'bg-terracotta-gradient border-terracotta-200/50',
    olive: 'bg-olive-gradient border-olive-200/50',
    gold: 'bg-gold-gradient border-gold-200/50'
  };

  return (
    <div
      className={`
        rounded-tuscan-xl ${shadow} border transition-all duration-300
        ${variantStyles[variant]}
        ${hover ? 'hover:shadow-card-hover hover:-translate-y-0.5' : ''}
        ${gradient ? 'bg-gradient-to-br from-white to-stone-50' : ''}
        ${borderColor}
        ${padding}
        ${className}
      `}
      {...props}
    >
      {children}
    </div>
  );
};

// Specialized Card variants with Tuscan colors
export const StatsCard = ({ title, value, icon: Icon, color = 'terracotta', trend = null, ...props }) => {
  const colors = {
    terracotta: {
      bg: 'bg-gradient-to-br from-terracotta-50 to-terracotta-100/50',
      text: 'text-terracotta-700',
      iconBg: 'bg-terracotta-200/50',
      border: 'border-terracotta-200/50'
    },
    gold: {
      bg: 'bg-gradient-to-br from-gold-50 to-gold-100/50',
      text: 'text-gold-700',
      iconBg: 'bg-gold-200/50',
      border: 'border-gold-200/50'
    },
    olive: {
      bg: 'bg-gradient-to-br from-olive-50 to-olive-100/50',
      text: 'text-olive-700',
      iconBg: 'bg-olive-200/50',
      border: 'border-olive-200/50'
    },
    stone: {
      bg: 'bg-gradient-to-br from-stone-50 to-stone-100/50',
      text: 'text-stone-700',
      iconBg: 'bg-stone-200/50',
      border: 'border-stone-200/50'
    },
    renaissance: {
      bg: 'bg-gradient-to-br from-renaissance-50 to-renaissance-100/50',
      text: 'text-renaissance-700',
      iconBg: 'bg-renaissance-200/50',
      border: 'border-renaissance-200/50'
    }
  };

  const colorConfig = colors[color] || colors.terracotta;

  return (
    <Card
      className={`${colorConfig.bg} border ${colorConfig.border}`}
      shadow="shadow-tuscan"
      hover
      {...props}
    >
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-sm font-medium text-stone-600 mb-2">{title}</p>
          <p className={`text-4xl font-bold text-stone-900 tracking-tight`}>{value}</p>
          {trend && (
            <p className={`text-xs mt-2 flex items-center ${trend.positive ? 'text-olive-600' : 'text-terracotta-600'}`}>
              {trend.positive ? '↗' : '↘'} {trend.value}
            </p>
          )}
        </div>
        {Icon && (
          <div className={`w-14 h-14 ${colorConfig.iconBg} rounded-tuscan-lg flex items-center justify-center`}>
            <Icon className={`text-2xl ${colorConfig.text}`} />
          </div>
        )}
      </div>
    </Card>
  );
};

export const ActionCard = ({ title, description, icon: Icon, onClick, color = 'terracotta', ...props }) => {
  const colors = {
    terracotta: 'hover:bg-terracotta-50 hover:border-terracotta-200',
    olive: 'hover:bg-olive-50 hover:border-olive-200',
    renaissance: 'hover:bg-renaissance-50 hover:border-renaissance-200',
    gold: 'hover:bg-gold-50 hover:border-gold-200'
  };

  return (
    <Card
      className={`cursor-pointer ${colors[color]}`}
      hover
      onClick={onClick}
      {...props}
    >
      <div className="flex items-center space-x-4">
        {Icon && (
          <div className="w-10 h-10 bg-stone-100 rounded-tuscan-lg flex items-center justify-center">
            <Icon className="text-lg text-stone-600" />
          </div>
        )}
        <div>
          <h3 className="font-semibold text-stone-800 mb-1">{title}</h3>
          <p className="text-sm text-stone-600">{description}</p>
        </div>
      </div>
    </Card>
  );
};

export const TourCard = ({
  tour,
  onEdit,
  onDelete,
  onCancel,
  onTogglePaid,
  onAutoAssign,
  showActions = true,
  className = '',
  ...props
}) => {
  const getStatusColor = (tour) => {
    if (tour.cancelled) return 'border-l-terracotta-500 bg-terracotta-50';

    const now = new Date();
    const tourDate = new Date(`${tour.date}T${tour.time}`);
    const diffDays = Math.ceil((tourDate - now) / (1000 * 60 * 60 * 24));

    if (tourDate < now) return 'border-l-stone-400 bg-stone-50';
    if (diffDays === 0) return 'border-l-gold-500 bg-gold-50';
    if (diffDays === 1) return 'border-l-olive-500 bg-olive-50';
    if (diffDays === 2) return 'border-l-olive-400 bg-olive-50/50';
    return 'border-l-renaissance-500 bg-renaissance-50';
  };

  const formatDate = (dateStr) => {
    return new Date(dateStr).toLocaleDateString('en-GB', {
      weekday: 'short',
      month: 'short',
      day: 'numeric'
    });
  };

  return (
    <Card
      className={`border-l-4 ${getStatusColor(tour)} transition-all duration-200 hover:shadow-tuscan-md ${className}`}
      padding="p-0"
      {...props}
    >
      <div className="p-4 sm:p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
          <div className="mb-2 sm:mb-0">
            <h3 className="text-lg font-semibold text-stone-900 mb-1">{tour.title}</h3>
            <div className="flex items-center space-x-4 text-sm text-stone-600">
              <span className="font-medium">{formatDate(tour.date)}</span>
              <span>{tour.time}</span>
              {tour.duration && <span>{tour.duration}</span>}
            </div>
          </div>
          {tour.booking_channel && (
            <div className="inline-block px-3 py-1 bg-renaissance-100 text-renaissance-700 text-xs font-medium rounded-full">
              {tour.booking_channel}
            </div>
          )}
        </div>

        {/* Guide and Customer Info */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <p className="text-sm text-stone-600">Guide</p>
            <p className="font-medium text-stone-900">{tour.guide_name || 'Unassigned'}</p>
          </div>
          {tour.customer_name && (
            <div>
              <p className="text-sm text-stone-600">Customer</p>
              <p className="font-medium text-stone-900">{tour.customer_name}</p>
              {tour.participants && (
                <p className="text-xs text-stone-500">{tour.participants} participants</p>
              )}
            </div>
          )}
        </div>

        {/* Description */}
        {tour.description && (
          <p className="text-sm text-stone-600 mb-4 line-clamp-2">{tour.description}</p>
        )}

        {/* Payment Status */}
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-2">
            <span className="text-sm text-stone-600">Payment:</span>
            <button
              onClick={() => onTogglePaid && onTogglePaid(tour.id)}
              className={`px-2 py-1 text-xs font-medium rounded-full transition-colors ${
                tour.paid
                  ? 'bg-olive-100 text-olive-700 hover:bg-olive-200'
                  : 'bg-terracotta-100 text-terracotta-700 hover:bg-terracotta-200'
              }`}
            >
              {tour.paid ? 'Paid' : 'Unpaid'}
            </button>
          </div>

          {tour.cancelled && (
            <span className="px-2 py-1 bg-terracotta-100 text-terracotta-700 text-xs font-medium rounded-full">
              Cancelled
            </span>
          )}
        </div>

        {/* Actions */}
        {showActions && (
          <div className="flex flex-wrap gap-2 pt-4 border-t border-stone-200">
            {tour.needs_guide_assignment && onAutoAssign && (
              <button
                onClick={() => onAutoAssign(tour.id)}
                className="px-3 py-1 bg-renaissance-100 text-renaissance-700 text-xs font-medium rounded-tuscan hover:bg-renaissance-200 transition-colors"
              >
                Auto-Assign
              </button>
            )}
            {onEdit && (
              <button
                onClick={() => onEdit(tour)}
                className="px-3 py-1 bg-olive-100 text-olive-700 text-xs font-medium rounded-tuscan hover:bg-olive-200 transition-colors"
              >
                Edit
              </button>
            )}
            {!tour.cancelled && onCancel && (
              <button
                onClick={() => onCancel(tour.id)}
                className="px-3 py-1 bg-gold-100 text-gold-700 text-xs font-medium rounded-tuscan hover:bg-gold-200 transition-colors"
              >
                Cancel
              </button>
            )}
            {onDelete && (
              <button
                onClick={() => onDelete(tour.id)}
                className="px-3 py-1 bg-terracotta-100 text-terracotta-700 text-xs font-medium rounded-tuscan hover:bg-terracotta-200 transition-colors"
              >
                Delete
              </button>
            )}
          </div>
        )}
      </div>
    </Card>
  );
};

export default Card;