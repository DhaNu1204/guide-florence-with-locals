import React from 'react';

// Step 9.1: filters/actions row - wraps instead of scrolling sideways (rule 4), in a surface card.
const Toolbar = ({ className = '', children, ...props }) => (
  <div
    role="toolbar"
    className={`flex flex-wrap items-center gap-2 p-3 bg-surface border border-line rounded-xl ${className}`}
    {...props}
  >
    {children}
  </div>
);

export default Toolbar;
