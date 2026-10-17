import React from 'react';
import { TOUCH } from './touch';

// Step 9.1: quiet icon control (rule 6). 32 px hit area with a mouse, 44 px touch; the label is required.
const IconButton = ({ icon: Icon, label, type = 'button', className = '', children, ...props }) => {
  if (!label && import.meta.env.DEV) console.warn('IconButton needs a label (aria-label)');
  return (
    <button
      type={type}
      aria-label={label}
      title={label}
      className={`inline-flex items-center justify-center w-8 h-8 rounded-lg text-ink-2
        hover:bg-section hover:text-ink transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent
        disabled:opacity-50 disabled:cursor-not-allowed ${TOUCH}:w-11 ${TOUCH}:h-11 ${className}`}
      {...props}
    >
      {Icon ? <Icon aria-hidden="true" className="w-4 h-4" /> : children}
    </button>
  );
};

export default IconButton;
