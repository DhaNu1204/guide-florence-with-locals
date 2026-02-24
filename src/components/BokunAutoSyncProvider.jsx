import React, { useEffect, useState } from 'react';
import { BokunSyncProvider, useBokunSync } from '../hooks/useBokunAutoSync';

/**
 * Non-intrusive sync status indicator component
 * Shows a small indicator in the corner when syncing
 */
const SyncStatusIndicator = () => {
  const { lastSync, isSyncing, error, lastSyncEvent } = useBokunSync();
  const [showStatus, setShowStatus] = useState(false);
  const [statusMessage, setStatusMessage] = useState('');
  const [isManualSync, setIsManualSync] = useState(false);

  // Track whether current sync is manual
  useEffect(() => {
    if (lastSyncEvent?.type === 'sync_started') {
      setIsManualSync(lastSyncEvent.trigger === 'manual');
    }
  }, [lastSyncEvent]);

  useEffect(() => {
    // Only show status indicator for manual syncs
    if (isSyncing && isManualSync) {
      setShowStatus(true);
      setStatusMessage('Syncing bookings...');
    } else if (!isSyncing && isManualSync && error) {
      setShowStatus(true);
      setStatusMessage('Sync failed');
      const timer = setTimeout(() => setShowStatus(false), 5000);
      return () => clearTimeout(timer);
    } else if (!isSyncing && isManualSync && showStatus) {
      setStatusMessage('Sync complete');
      const timer = setTimeout(() => {
        setShowStatus(false);
        setIsManualSync(false);
      }, 2000);
      return () => clearTimeout(timer);
    }
  }, [isSyncing, error, isManualSync]);

  // Format last sync time
  const formatLastSync = () => {
    if (!lastSync) return 'Never';
    const date = new Date(lastSync);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    return date.toLocaleDateString();
  };

  if (!showStatus) return null;

  return (
    <div
      className={`fixed bottom-4 right-4 z-50 transition-all duration-300 transform ${
        showStatus ? 'translate-y-0 opacity-100' : 'translate-y-2 opacity-0'
      }`}
    >
      <div
        className={`flex items-center gap-2 px-3 py-2 rounded-lg shadow-lg text-sm ${
          error
            ? 'bg-red-100 text-red-800 border border-red-200'
            : isSyncing
            ? 'bg-blue-100 text-blue-800 border border-blue-200'
            : 'bg-green-100 text-green-800 border border-green-200'
        }`}
      >
        {isSyncing ? (
          <svg
            className="w-4 h-4 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle
              className="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              strokeWidth="4"
            />
            <path
              className="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
          </svg>
        ) : error ? (
          <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
            <path
              fillRule="evenodd"
              d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
              clipRule="evenodd"
            />
          </svg>
        ) : (
          <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
            <path
              fillRule="evenodd"
              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
              clipRule="evenodd"
            />
          </svg>
        )}
        <span>{statusMessage}</span>
      </div>
    </div>
  );
};

/**
 * BokunAutoSyncProvider Component
 *
 * Wraps the application and provides Bokun sync context.
 * Features:
 * - Background sync without blocking UI
 * - Non-intrusive sync status indicator
 * - Context for other components to access sync status
 *
 * Usage:
 * ```jsx
 * // In App.jsx
 * <BokunAutoSyncProvider>
 *   <YourApp />
 * </BokunAutoSyncProvider>
 *
 * // In any component
 * import { useBokunSync } from '../hooks/useBokunAutoSync';
 * const { lastSync, isSyncing, syncNow, error } = useBokunSync();
 * ```
 */
const BokunAutoSyncProvider = ({ children }) => {
  return (
    <BokunSyncProvider>
      <SyncStatusContent>{children}</SyncStatusContent>
    </BokunSyncProvider>
  );
};

// Internal component that has access to the sync context
const SyncStatusContent = ({ children }) => {
  const { lastSyncEvent } = useBokunSync();

  // Development logging
  useEffect(() => {
    if (import.meta.env.MODE === 'development' && lastSyncEvent) {
      console.log('Bokun Auto-Sync Event:', lastSyncEvent);
    }
  }, [lastSyncEvent]);

  return (
    <>
      {children}
      <SyncStatusIndicator />
    </>
  );
};

export default BokunAutoSyncProvider;

// Re-export the hook for convenience
export { useBokunSync } from '../hooks/useBokunAutoSync';
