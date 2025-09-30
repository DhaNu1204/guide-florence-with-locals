import React from 'react';

const Card = ({ 
  children, 
  className = '', 
  padding = 'p-6', 
  shadow = 'shadow-md', 
  hover = false,
  gradient = false,
  borderColor = '',
  ...props 
}) => {
  return (
    <div 
      className={`
        bg-white rounded-xl ${shadow} border border-gray-100 transition-all duration-200
        ${hover ? 'hover:shadow-lg hover:-translate-y-1' : ''}
        ${gradient ? 'bg-gradient-to-br from-white to-gray-50' : ''}
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

// Specialized Card variants
export const StatsCard = ({ title, value, icon: Icon, color = 'blue', trend = null, ...props }) => {
  const colors = {
    blue: {
      bg: 'bg-blue-50',
      text: 'text-blue-600',
      iconBg: 'bg-blue-100'
    },
    green: {
      bg: 'bg-green-50',
      text: 'text-green-600',
      iconBg: 'bg-green-100'
    },
    purple: {
      bg: 'bg-purple-50',
      text: 'text-purple-600',
      iconBg: 'bg-purple-100'
    },
    orange: {
      bg: 'bg-orange-50',
      text: 'text-orange-600',
      iconBg: 'bg-orange-100'
    },
    red: {
      bg: 'bg-red-50',
      text: 'text-red-600',
      iconBg: 'bg-red-100'
    }
  };

  return (
    <Card className={`${colors[color].bg} border-l-4 border-l-${color}-500`} hover {...props}>
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium text-gray-600 mb-1">{title}</p>
          <p className={`text-2xl font-bold ${colors[color].text}`}>{value}</p>
          {trend && (
            <p className={`text-xs mt-1 ${trend.positive ? 'text-green-600' : 'text-red-600'}`}>
              {trend.positive ? '↗' : '↘'} {trend.value}
            </p>
          )}
        </div>
        {Icon && (
          <div className={`w-12 h-12 ${colors[color].iconBg} rounded-xl flex items-center justify-center`}>
            <Icon className={`text-xl ${colors[color].text}`} />
          </div>
        )}
      </div>
    </Card>
  );
};

export const ActionCard = ({ title, description, icon: Icon, onClick, color = 'blue', ...props }) => {
  const colors = {
    blue: 'hover:bg-blue-50 hover:border-blue-200',
    green: 'hover:bg-green-50 hover:border-green-200',
    purple: 'hover:bg-purple-50 hover:border-purple-200',
    orange: 'hover:bg-orange-50 hover:border-orange-200'
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
          <div className="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
            <Icon className="text-lg text-gray-600" />
          </div>
        )}
        <div>
          <h3 className="font-semibold text-gray-800 mb-1">{title}</h3>
          <p className="text-sm text-gray-600">{description}</p>
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
    if (tour.cancelled) return 'border-l-red-500 bg-red-50';
    
    const now = new Date();
    const tourDate = new Date(`${tour.date}T${tour.time}`);
    const diffDays = Math.ceil((tourDate - now) / (1000 * 60 * 60 * 24));
    
    if (tourDate < now) return 'border-l-gray-400 bg-gray-50';
    if (diffDays === 0) return 'border-l-yellow-500 bg-yellow-50';
    if (diffDays === 1) return 'border-l-green-500 bg-green-50';
    if (diffDays === 2) return 'border-l-blue-500 bg-blue-50';
    return 'border-l-purple-500 bg-purple-50';
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
      className={`border-l-4 ${getStatusColor(tour)} transition-all duration-200 hover:shadow-md ${className}`}
      padding="p-0"
      {...props}
    >
      <div className="p-4 sm:p-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4">
          <div className="mb-2 sm:mb-0">
            <h3 className="text-lg font-semibold text-gray-900 mb-1">{tour.title}</h3>
            <div className="flex items-center space-x-4 text-sm text-gray-600">
              <span className="font-medium">{formatDate(tour.date)}</span>
              <span>{tour.time}</span>
              {tour.duration && <span>{tour.duration}</span>}
            </div>
          </div>
          {tour.booking_channel && (
            <div className="inline-block px-3 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded-full">
              {tour.booking_channel}
            </div>
          )}
        </div>

        {/* Guide and Customer Info */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <p className="text-sm text-gray-600">Guide</p>
            <p className="font-medium text-gray-900">{tour.guide_name || 'Unassigned'}</p>
          </div>
          {tour.customer_name && (
            <div>
              <p className="text-sm text-gray-600">Customer</p>
              <p className="font-medium text-gray-900">{tour.customer_name}</p>
              {tour.participants && (
                <p className="text-xs text-gray-500">{tour.participants} participants</p>
              )}
            </div>
          )}
        </div>

        {/* Description */}
        {tour.description && (
          <p className="text-sm text-gray-600 mb-4 line-clamp-2">{tour.description}</p>
        )}

        {/* Payment Status */}
        <div className="flex items-center justify-between mb-4">
          <div className="flex items-center space-x-2">
            <span className="text-sm text-gray-600">Payment:</span>
            <button
              onClick={() => onTogglePaid && onTogglePaid(tour.id)}
              className={`px-2 py-1 text-xs font-medium rounded-full transition-colors ${
                tour.paid 
                  ? 'bg-green-100 text-green-700 hover:bg-green-200' 
                  : 'bg-red-100 text-red-700 hover:bg-red-200'
              }`}
            >
              {tour.paid ? 'Paid' : 'Unpaid'}
            </button>
          </div>
          
          {tour.cancelled && (
            <span className="px-2 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-full">
              Cancelled
            </span>
          )}
        </div>

        {/* Actions */}
        {showActions && (
          <div className="flex flex-wrap gap-2 pt-4 border-t border-gray-200">
            {tour.needs_guide_assignment && onAutoAssign && (
              <button
                onClick={() => onAutoAssign(tour.id)}
                className="px-3 py-1 bg-cyan-100 text-cyan-700 text-xs font-medium rounded-md hover:bg-cyan-200 transition-colors"
              >
                Auto-Assign
              </button>
            )}
            {onEdit && (
              <button
                onClick={() => onEdit(tour)}
                className="px-3 py-1 bg-blue-100 text-blue-700 text-xs font-medium rounded-md hover:bg-blue-200 transition-colors"
              >
                Edit
              </button>
            )}
            {!tour.cancelled && onCancel && (
              <button
                onClick={() => onCancel(tour.id)}
                className="px-3 py-1 bg-orange-100 text-orange-700 text-xs font-medium rounded-md hover:bg-orange-200 transition-colors"
              >
                Cancel
              </button>
            )}
            {onDelete && (
              <button
                onClick={() => onDelete(tour.id)}
                className="px-3 py-1 bg-red-100 text-red-700 text-xs font-medium rounded-md hover:bg-red-200 transition-colors"
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