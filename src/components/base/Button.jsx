import React from 'react';
import { TOUCH } from './touch';

// Step 9.1: redesign button (docs/DESIGN.md). The old Tuscan one stays in ../UI/Button.
const VARIANTS = {
  primary: 'bg-accent text-white hover:bg-accent-hover border border-transparent',
  secondary: 'bg-surface text-ink border border-line hover:bg-muted-row',
  ghost: 'bg-transparent text-ink-2 border border-transparent hover:bg-section hover:text-ink',
  'danger-outline': 'bg-surface text-danger border border-danger-border hover:bg-danger-strip',
};

const Button = ({ variant = 'primary', type = 'button', className = '', children, ...props }) => (
  <button
    type={type}
    className={`inline-flex items-center justify-center gap-2 h-9 px-3 rounded-lg text-sm font-medium
      transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1
      disabled:opacity-50 disabled:cursor-not-allowed ${TOUCH}:h-11 ${TOUCH}:px-4
      ${VARIANTS[variant] || VARIANTS.primary} ${className}`}
    {...props}
  >
    {children}
  </button>
);

export default Button;
