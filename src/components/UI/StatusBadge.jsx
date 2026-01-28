import React from 'react';
import { FiCheck, FiClock, FiX, FiAlertCircle, FiDollarSign } from 'react-icons/fi';

/**
 * StatusBadge Component
 * Versatile badge component for displaying various status types
 * with Tuscan-themed color palette
 */

// Predefined status configurations
const statusConfigs = {
  // Booking/Tour Status
  confirmed: {
    label: 'Confirmed',
    icon: FiCheck,
    bg: 'bg-olive-100',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500',
    iconBg: 'bg-olive-500'
  },
  pending: {
    label: 'Pending',
    icon: FiClock,
    bg: 'bg-gold-100',
    text: 'text-gold-700',
    border: 'border-gold-200',
    dot: 'bg-gold-500',
    iconBg: 'bg-gold-500'
  },
  cancelled: {
    label: 'Cancelled',
    icon: FiX,
    bg: 'bg-terracotta-100',
    text: 'text-terracotta-700',
    border: 'border-terracotta-200',
    dot: 'bg-terracotta-500',
    iconBg: 'bg-terracotta-500'
  },

  // Payment Status
  paid: {
    label: 'Paid',
    icon: FiCheck,
    bg: 'bg-olive-50',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500',
    iconBg: 'bg-olive-500'
  },
  partial: {
    label: 'Partial',
    icon: FiDollarSign,
    bg: 'bg-gold-50',
    text: 'text-gold-700',
    border: 'border-gold-200',
    dot: 'bg-gold-500',
    iconBg: 'bg-gold-500'
  },
  unpaid: {
    label: 'Unpaid',
    icon: FiAlertCircle,
    bg: 'bg-terracotta-50',
    text: 'text-terracotta-700',
    border: 'border-terracotta-200',
    dot: 'bg-terracotta-500',
    iconBg: 'bg-terracotta-500'
  },

  // Guide Assignment Status
  assigned: {
    label: 'Assigned',
    icon: FiCheck,
    bg: 'bg-olive-100',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500',
    iconBg: 'bg-olive-500'
  },
  unassigned: {
    label: 'Unassigned',
    icon: FiAlertCircle,
    bg: 'bg-gold-100',
    text: 'text-gold-700',
    border: 'border-gold-200',
    dot: 'bg-gold-500',
    iconBg: 'bg-gold-500'
  },

  // Sync Status
  synced: {
    label: 'Synced',
    icon: FiCheck,
    bg: 'bg-olive-100',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500',
    iconBg: 'bg-olive-500'
  },
  syncing: {
    label: 'Syncing',
    icon: FiClock,
    bg: 'bg-renaissance-100',
    text: 'text-renaissance-700',
    border: 'border-renaissance-200',
    dot: 'bg-renaissance-500',
    iconBg: 'bg-renaissance-500'
  },
  error: {
    label: 'Error',
    icon: FiAlertCircle,
    bg: 'bg-terracotta-100',
    text: 'text-terracotta-700',
    border: 'border-terracotta-200',
    dot: 'bg-terracotta-500',
    iconBg: 'bg-terracotta-500'
  },

  // Generic
  success: {
    label: 'Success',
    icon: FiCheck,
    bg: 'bg-olive-100',
    text: 'text-olive-700',
    border: 'border-olive-200',
    dot: 'bg-olive-500',
    iconBg: 'bg-olive-500'
  },
  warning: {
    label: 'Warning',
    icon: FiAlertCircle,
    bg: 'bg-gold-100',
    text: 'text-gold-700',
    border: 'border-gold-200',
    dot: 'bg-gold-500',
    iconBg: 'bg-gold-500'
  },
  danger: {
    label: 'Danger',
    icon: FiX,
    bg: 'bg-terracotta-100',
    text: 'text-terracotta-700',
    border: 'border-terracotta-200',
    dot: 'bg-terracotta-500',
    iconBg: 'bg-terracotta-500'
  },
  info: {
    label: 'Info',
    icon: FiAlertCircle,
    bg: 'bg-renaissance-50',
    text: 'text-renaissance-700',
    border: 'border-renaissance-200',
    dot: 'bg-renaissance-500',
    iconBg: 'bg-renaissance-500'
  },
  neutral: {
    label: 'Neutral',
    icon: null,
    bg: 'bg-stone-100',
    text: 'text-stone-700',
    border: 'border-stone-200',
    dot: 'bg-stone-500',
    iconBg: 'bg-stone-500'
  }
};

const StatusBadge = ({
  status, // predefined status key
  label, // custom label (overrides predefined)
  icon: CustomIcon, // custom icon (overrides predefined)
  size = 'md', // 'sm', 'md', 'lg'
  variant = 'filled', // 'filled', 'outlined', 'dot', 'icon'
  showIcon = false,
  showDot = false,
  pulse = false,
  className = ''
}) => {
  const config = statusConfigs[status] || statusConfigs.neutral;
  const displayLabel = label || config.label;
  const IconComponent = CustomIcon || config.icon;

  // Size configurations
  const sizeClasses = {
    sm: 'text-xs px-2 py-0.5',
    md: 'text-xs px-2.5 py-1',
    lg: 'text-sm px-3 py-1.5'
  };

  const iconSizes = {
    sm: 10,
    md: 12,
    lg: 14
  };

  const dotSizes = {
    sm: 'w-1.5 h-1.5',
    md: 'w-2 h-2',
    lg: 'w-2.5 h-2.5'
  };

  // Variant styles
  const getVariantClasses = () => {
    switch (variant) {
      case 'outlined':
        return `bg-transparent border ${config.border} ${config.text}`;
      case 'dot':
        return `bg-transparent ${config.text}`;
      case 'icon':
        return `bg-transparent ${config.text}`;
      default: // filled
        return `${config.bg} ${config.text}`;
    }
  };

  // Dot-only variant
  if (variant === 'dot') {
    return (
      <span className={`inline-flex items-center gap-1.5 ${sizeClasses[size]} ${className}`}>
        <span className={`
          ${dotSizes[size]} rounded-full ${config.dot}
          ${pulse ? 'animate-pulse' : ''}
        `} />
        <span className={`font-medium ${config.text}`}>{displayLabel}</span>
      </span>
    );
  }

  // Icon-only variant
  if (variant === 'icon' && IconComponent) {
    return (
      <span
        className={`
          inline-flex items-center justify-center
          w-6 h-6 rounded-full
          ${config.iconBg} text-white
          ${pulse ? 'animate-pulse' : ''}
          ${className}
        `}
        title={displayLabel}
      >
        <IconComponent size={iconSizes[size]} />
      </span>
    );
  }

  return (
    <span
      className={`
        inline-flex items-center gap-1.5 rounded-full font-medium
        ${sizeClasses[size]}
        ${getVariantClasses()}
        ${className}
      `}
    >
      {/* Optional dot */}
      {showDot && (
        <span className={`
          ${dotSizes[size]} rounded-full ${config.dot}
          ${pulse ? 'animate-pulse' : ''}
        `} />
      )}

      {/* Optional icon */}
      {showIcon && IconComponent && (
        <IconComponent size={iconSizes[size]} className={pulse ? 'animate-pulse' : ''} />
      )}

      {/* Label */}
      {displayLabel}
    </span>
  );
};

// Convenience components for common statuses
export const ConfirmedBadge = (props) => <StatusBadge status="confirmed" {...props} />;
export const PendingBadge = (props) => <StatusBadge status="pending" {...props} />;
export const CancelledBadge = (props) => <StatusBadge status="cancelled" {...props} />;
export const PaidBadge = (props) => <StatusBadge status="paid" {...props} />;
export const PartialBadge = (props) => <StatusBadge status="partial" {...props} />;
export const UnpaidBadge = (props) => <StatusBadge status="unpaid" {...props} />;
export const AssignedBadge = (props) => <StatusBadge status="assigned" {...props} />;
export const UnassignedBadge = (props) => <StatusBadge status="unassigned" {...props} />;

// Smart badge that determines status from tour object
export const TourStatusBadge = ({ tour, showIcon = true, ...props }) => {
  const getStatus = () => {
    if (tour.cancelled) return 'cancelled';
    if (tour.confirmed || tour.status === 'CONFIRMED') return 'confirmed';
    return 'pending';
  };

  return <StatusBadge status={getStatus()} showIcon={showIcon} {...props} />;
};

// Smart badge for payment status
export const PaymentStatusBadge = ({ tour, showIcon = true, ...props }) => {
  const getStatus = () => {
    if (tour.payment_status === 'paid' || tour.paid) return 'paid';
    if (tour.payment_status === 'partial') return 'partial';
    return 'unpaid';
  };

  return <StatusBadge status={getStatus()} showIcon={showIcon} {...props} />;
};

// Smart badge for guide assignment
export const GuideStatusBadge = ({ tour, ...props }) => {
  const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';
  return (
    <StatusBadge
      status={hasGuide ? 'assigned' : 'unassigned'}
      label={hasGuide ? tour.guide_name : 'Unassigned'}
      {...props}
    />
  );
};

export default StatusBadge;
