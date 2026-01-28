import React from 'react';
import { FiLoader } from 'react-icons/fi';

const Button = ({
  children,
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  icon: Icon,
  iconPosition = 'left',
  className = '',
  fullWidth = false,
  ...props
}) => {
  const baseClasses = `
    inline-flex items-center justify-center font-medium rounded-tuscan-lg
    transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2
    disabled:opacity-50 disabled:cursor-not-allowed
    touch-manipulation active:scale-[0.98]
    ${fullWidth ? 'w-full' : ''}
  `;

  // Tuscan-themed button variants
  const variants = {
    primary: 'bg-terracotta-500 hover:bg-terracotta-600 active:bg-terracotta-700 text-white focus:ring-terracotta-500 shadow-tuscan hover:shadow-tuscan-md',
    secondary: 'bg-stone-600 hover:bg-stone-700 active:bg-stone-800 text-white focus:ring-stone-500 shadow-tuscan hover:shadow-tuscan-md',
    outline: 'border-2 border-stone-300 hover:border-stone-400 active:bg-stone-100 text-stone-700 hover:bg-stone-50 focus:ring-stone-500',
    ghost: 'text-stone-600 hover:text-stone-900 hover:bg-stone-100 active:bg-stone-200 focus:ring-stone-500',
    success: 'bg-olive-500 hover:bg-olive-600 active:bg-olive-700 text-white focus:ring-olive-500 shadow-tuscan hover:shadow-tuscan-md',
    warning: 'bg-gold-500 hover:bg-gold-600 active:bg-gold-700 text-white focus:ring-gold-500 shadow-tuscan hover:shadow-tuscan-md',
    danger: 'bg-terracotta-600 hover:bg-terracotta-700 active:bg-terracotta-800 text-white focus:ring-terracotta-500 shadow-tuscan hover:shadow-tuscan-md',
    renaissance: 'bg-renaissance-600 hover:bg-renaissance-700 active:bg-renaissance-800 text-white focus:ring-renaissance-500 shadow-tuscan hover:shadow-tuscan-md',
    olive: 'bg-olive-600 hover:bg-olive-700 active:bg-olive-800 text-white focus:ring-olive-500 shadow-tuscan hover:shadow-tuscan-md',
    gold: 'bg-gold-400 hover:bg-gold-500 active:bg-gold-600 text-stone-900 focus:ring-gold-400 shadow-tuscan hover:shadow-tuscan-md'
  };

  // Sizes with 44px minimum height for mobile touch targets
  const sizes = {
    sm: 'px-3 py-2 text-sm min-h-[44px]',
    md: 'px-4 py-2.5 text-sm min-h-[44px]',
    lg: 'px-6 py-3 text-base min-h-[48px]',
    xl: 'px-8 py-4 text-lg min-h-[52px]'
  };

  return (
    <button
      className={`${baseClasses} ${variants[variant]} ${sizes[size]} ${className}`}
      disabled={disabled || loading}
      {...props}
    >
      {loading && (
        <FiLoader className="animate-spin mr-2" />
      )}

      {!loading && Icon && iconPosition === 'left' && (
        <Icon className={`${children ? 'mr-2' : ''}`} />
      )}

      {children}

      {!loading && Icon && iconPosition === 'right' && (
        <Icon className={`${children ? 'ml-2' : ''}`} />
      )}
    </button>
  );
};

// Specialized button components with 44px minimum touch targets
export const IconButton = ({ icon: Icon, size = 'md', variant = 'ghost', ...props }) => {
  const sizes = {
    sm: 'p-2 min-h-[44px] min-w-[44px]',
    md: 'p-2.5 min-h-[44px] min-w-[44px]',
    lg: 'p-3 min-h-[48px] min-w-[48px]'
  };

  return (
    <Button
      variant={variant}
      className={`${sizes[size]} rounded-tuscan-lg`}
      {...props}
    >
      <Icon className="text-lg" />
    </Button>
  );
};

export const FloatingActionButton = ({ icon: Icon, ...props }) => {
  return (
    <button
      className="fixed bottom-6 right-6 w-14 h-14 min-h-[56px] min-w-[56px] bg-terracotta-500 hover:bg-terracotta-600 active:bg-terracotta-700 text-white rounded-full shadow-tuscan-lg hover:shadow-tuscan-xl active:shadow-tuscan transition-all duration-200 flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:ring-offset-2 z-30 touch-manipulation active:scale-95"
      {...props}
    >
      <Icon className="text-xl" />
    </button>
  );
};

export default Button;
