import React from 'react';
import { useLastCheckAt } from '../hooks/useLiveRefresh';
import { formatShownAt } from '../services/netPolicy';

// Step 4.11: "Updated HH:MM" - the newer of the page's own last load and the last successful
// change check (an unchanged check means the data on screen was still current at that time).
export default function UpdatedStamp({ loadedAt, className = '' }) {
  const checkedAt = useLastCheckAt();
  const at = Math.max(Number(loadedAt) || 0, Number(checkedAt) || 0);
  if (!at) return null;
  return (
    <span className={`text-xs text-stone-500 tabular-nums ${className}`} data-testid="updated-stamp">
      Updated {formatShownAt(at)}
    </span>
  );
}
