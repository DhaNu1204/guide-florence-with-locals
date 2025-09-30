import React from 'react';
import { FiEye, FiEyeOff, FiSearch } from 'react-icons/fi';

const Input = ({
  label,
  error,
  type = 'text',
  icon: Icon,
  iconPosition = 'left',
  className = '',
  containerClassName = '',
  required = false,
  fullWidth = true,
  ...props
}) => {
  const [showPassword, setShowPassword] = React.useState(false);

  const baseClasses = `
    block px-3 py-2 border border-gray-300 rounded-lg shadow-sm
    placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
    transition-colors duration-200 disabled:bg-gray-50 disabled:text-gray-500
    ${fullWidth ? 'w-full' : ''}
    ${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : ''}
    ${Icon ? (iconPosition === 'left' ? 'pl-10' : 'pr-10') : ''}
    ${type === 'password' ? 'pr-10' : ''}
  `;

  return (
    <div className={`${fullWidth ? 'w-full' : ''} ${containerClassName}`}>
      {label && (
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {label}
          {required && <span className="text-red-500 ml-1">*</span>}
        </label>
      )}
      
      <div className="relative">
        {Icon && iconPosition === 'left' && (
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <Icon className="h-5 w-5 text-gray-400" />
          </div>
        )}
        
        <input
          type={type === 'password' ? (showPassword ? 'text' : 'password') : type}
          className={`${baseClasses} ${className}`}
          {...props}
        />
        
        {Icon && iconPosition === 'right' && (
          <div className="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
            <Icon className="h-5 w-5 text-gray-400" />
          </div>
        )}
        
        {type === 'password' && (
          <div className="absolute inset-y-0 right-0 pr-3 flex items-center">
            <button
              type="button"
              className="text-gray-400 hover:text-gray-600 focus:outline-none"
              onClick={() => setShowPassword(!showPassword)}
            >
              {showPassword ? (
                <FiEyeOff className="h-5 w-5" />
              ) : (
                <FiEye className="h-5 w-5" />
              )}
            </button>
          </div>
        )}
      </div>
      
      {error && (
        <p className="mt-2 text-sm text-red-600">{error}</p>
      )}
    </div>
  );
};

export const TextArea = ({
  label,
  error,
  className = '',
  containerClassName = '',
  required = false,
  fullWidth = true,
  rows = 3,
  ...props
}) => {
  const baseClasses = `
    block px-3 py-2 border border-gray-300 rounded-lg shadow-sm
    placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
    transition-colors duration-200 disabled:bg-gray-50 disabled:text-gray-500 resize-vertical
    ${fullWidth ? 'w-full' : ''}
    ${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : ''}
  `;

  return (
    <div className={`${fullWidth ? 'w-full' : ''} ${containerClassName}`}>
      {label && (
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {label}
          {required && <span className="text-red-500 ml-1">*</span>}
        </label>
      )}
      
      <textarea
        rows={rows}
        className={`${baseClasses} ${className}`}
        {...props}
      />
      
      {error && (
        <p className="mt-2 text-sm text-red-600">{error}</p>
      )}
    </div>
  );
};

export const Select = ({
  label,
  error,
  options = [],
  placeholder = 'Select an option',
  className = '',
  containerClassName = '',
  required = false,
  fullWidth = true,
  ...props
}) => {
  const baseClasses = `
    block px-3 py-2 border border-gray-300 rounded-lg shadow-sm
    focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500
    transition-colors duration-200 disabled:bg-gray-50 disabled:text-gray-500
    bg-white cursor-pointer
    ${fullWidth ? 'w-full' : ''}
    ${error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : ''}
  `;

  return (
    <div className={`${fullWidth ? 'w-full' : ''} ${containerClassName}`}>
      {label && (
        <label className="block text-sm font-medium text-gray-700 mb-2">
          {label}
          {required && <span className="text-red-500 ml-1">*</span>}
        </label>
      )}
      
      <select
        className={`${baseClasses} ${className}`}
        {...props}
      >
        {placeholder && <option value="">{placeholder}</option>}
        {options.map((option, index) => (
          <option 
            key={option.value || index} 
            value={option.value || option}
          >
            {option.label || option}
          </option>
        ))}
      </select>
      
      {error && (
        <p className="mt-2 text-sm text-red-600">{error}</p>
      )}
    </div>
  );
};

export const SearchInput = ({ onSearch, placeholder = 'Search...', ...props }) => {
  return (
    <Input
      type="text"
      icon={FiSearch}
      iconPosition="left"
      placeholder={placeholder}
      onChange={(e) => onSearch && onSearch(e.target.value)}
      {...props}
    />
  );
};

export const FormGroup = ({ children, className = '' }) => {
  return (
    <div className={`grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 ${className}`}>
      {children}
    </div>
  );
};

export default Input;