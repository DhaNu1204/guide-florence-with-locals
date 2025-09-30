import React from 'react';
import { useBokunAutoSync } from '../hooks/useBokunAutoSync';

// This component handles automatic Bokun synchronization in the background
const BokunAutoSyncProvider = ({ children }) => {
  // Initialize auto-sync - the hook handles all the logic
  const { syncStatus, lastSyncEvent } = useBokunAutoSync();

  // This component is invisible but provides auto-sync functionality
  // It runs in the background like email sync in email applications

  // Optional: Add debug logging in development
  if (import.meta.env.MODE === 'development') {
    React.useEffect(() => {
      if (lastSyncEvent) {
        console.log('📧 Bokun Auto-Sync Event:', lastSyncEvent);
      }
    }, [lastSyncEvent]);
  }

  return <>{children}</>;
};

export default BokunAutoSyncProvider;