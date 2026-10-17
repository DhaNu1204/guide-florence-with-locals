import React from 'react';

// Step 9.1: a section title with an optional count / meta on the right (tabular numbers).
const SectionHeader = ({ title, meta, actions, as: Tag = 'h2', className = '' }) => (
  <div className={`flex items-center justify-between gap-3 min-h-9 px-4 py-2 bg-section text-ink ${className}`}>
    <Tag className="text-[13px] font-semibold">{title}</Tag>
    {(meta || actions) && (
      <div className="flex items-center gap-3 text-[13px] text-ink-2 tabular-nums">
        {meta}
        {actions}
      </div>
    )}
  </div>
);

export default SectionHeader;
