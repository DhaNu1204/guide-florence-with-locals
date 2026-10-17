import React from 'react';

// Step 9.1: status pills (rule 2 - colour marks exceptions only). Colours from docs/DESIGN.md.
export const PILL_TONES = {
  need: 'bg-danger-pill text-danger-strong',
  resched: 'bg-warn-bg text-warn-ink',
  full: 'bg-danger-pill text-danger',
  manual: 'bg-accent-soft text-accent',
  cancelled: 'bg-canc-bg text-canc-ink',
  lang: 'bg-info-bg text-info-ink',
  note: 'bg-note-bg text-note-ink',
};

const Pill = ({ tone = 'manual', className = '', children, ...props }) => (
  <span
    className={`inline-flex items-center h-5 px-2 rounded-full text-[11px] font-medium leading-none whitespace-nowrap tabular-nums
      ${PILL_TONES[tone] || PILL_TONES.manual} ${className}`}
    {...props}
  >
    {children}
  </span>
);

export default Pill;
