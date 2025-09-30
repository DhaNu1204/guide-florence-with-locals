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
    inline-flex items-center justify-center font-medium rounded-lg
    transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2
    disabled:opacity-50 disabled:cursor-not-allowed
    ${fullWidth ? 'w-full' : ''}
  `;

  const variants = {
    primary: 'bg-blue-600 hover:bg-blue-700 text-white focus:ring-blue-500 shadow-sm hover:shadow-md',
    secondary: 'bg-gray-600 hover:bg-gray-700 text-white focus:ring-gray-500 shadow-sm hover:shadow-md',
    outline: 'border-2 border-gray-300 hover:border-gray-400 text-gray-700 hover:bg-gray-50 focus:ring-gray-500',
    ghost: 'text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus:ring-gray-500',
    success: 'bg-green-600 hover:bg-green-700 text-white focus:ring-green-500 shadow-sm hover:shadow-md',
    warning: 'bg-orange-600 hover:bg-orange-700 text-white focus:ring-orange-500 shadow-sm hover:shadow-md',
    danger: 'bg-red-600 hover:bg-red-700 text-white focus:ring-red-500 shadow-sm hover:shadow-md',
    purple: 'bg-purple-600 hover:bg-purple-700 text-white focus:ring-purple-500 shadow-sm hover:shadow-md',
    cyan: 'bg-cyan-600 hover:bg-cyan-700 text-white focus:ring-cyan-500 shadow-sm hover:shadow-md'
  };

  const sizes = {
    sm: 'px-3 py-2 text-sm',
    md: 'px-4 py-2 text-sm',
    lg: 'px-6 py-3 text-base',
    xl: 'px-8 py-4 text-lg'
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

// Specialized button components
export const IconButton = ({ icon: Icon, size = 'md', variant = 'ghost', ...props }) => {
  const sizes = {
    sm: 'p-1',
    md: 'p-2',
    lg: 'p-3'
  };

  return (
    <Button
      variant={variant}
      className={`${sizes[size]} rounded-lg`}
      {...props}
    >
      <Icon className="text-lg" />
    </Button>
  );
};

export const FloatingActionButton = ({ icon: Icon, ...props }) => {
  return (
    <button
      className="fixed bottom-6 right-6 w-14 h-14 bg-blue-600 hover:bg-blue-700 text-white rounded-full shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 z-30"
      {...props}
    >
      <Icon className="text-xl" />
    </button>
  );
};

export default Button;