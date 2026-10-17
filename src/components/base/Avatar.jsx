import React from 'react';

// Step 9.1: 24 px initials avatar ("Maria Rossi" -> "MR", "dhanu" -> "D").
export function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  const first = parts[0].charAt(0);
  const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
  return (first + last).toUpperCase();
}

const Avatar = ({ name, className = '' }) => (
  <span
    title={name || undefined}
    aria-hidden="true"
    className={`inline-flex items-center justify-center w-6 h-6 rounded-full bg-section text-ink-2 text-[11px] font-semibold flex-shrink-0 ${className}`}
  >
    {initials(name)}
  </span>
);

export default Avatar;
