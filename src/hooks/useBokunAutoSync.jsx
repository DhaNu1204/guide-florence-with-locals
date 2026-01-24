import { useEffect, useState, useContext, createContext } from 'react';
import { useAuth } from '../contexts/AuthContext';
import bokunAutoSync from '../services/bokunAutoSync';

// Create context for Bokun sync state
const BokunSyncContext = createContext(null);

// Constants for sync timing
const SYNC_INTERVAL_MS = 15 * 60 * 1000; // 15 minutes in milliseconds
const MIN_SYNC_INTERVAL_MS = 15 * 60 * 1000; // Minimum 15 minutes between syncs
const STORAGE_KEY = 'bokun_last_sync';

/**
 * Custom hook for Bokun auto-sync
 * - Syncs on app load (if last sync > 15 minutes ago)
 * - Syncs every 15 minutes while app is active
 * - Stores last sync time in localStorage
 * - Provides: { lastSync, isSyncing, syncNow, syncError }
 */
export const useBokunAutoSync = () => {
  const { isAuthenticated, userRole } = useAuth();
  const [lastSync, setLastSync] = useState(() => {
    const stored = localStorage.getItem(STORAGE_KEY);
    return stored ? new Date(stored) : null;
  });
  const [isSyncing, setIsSyncing] = useState(false);
  const [syncError, setSyncError] = useState(null);
  const [syncStatus, setSyncStatus] = useState(bokunAutoSync.getStatus());
  const [lastSyncEvent, setLastSyncEvent] = useState(null);

  // Check if sync is needed based on last sync time
  const shouldSync = () => {
    if (!lastSync) return true;
    const now = new Date();
    const timeSinceLastSync = now.getTime() - new Date(lastSync).getTime();
    return timeSinceLastSync >= MIN_SYNC_INTERVAL_MS;
  };

  // Perform sync and update state
  const performSync = async (trigger = 'manual') => {
    if (isSyncing) {
      console.log('Sync already in progress, skipping');
      return;
    }

    try {
      setIsSyncing(true);
      setSyncError(null);

      await bokunAutoSync.performSync(trigger);

      const newSyncTime = new Date();
      setLastSync(newSyncTime);
      localStorage.setItem(STORAGE_KEY, newSyncTime.toISOString());

      setSyncStatus(bokunAutoSync.getStatus());
    } catch (error) {
      console.error('Bokun sync error:', error);
      setSyncError(error.message || 'Sync failed');
    } finally {
      setIsSyncing(false);
    }
  };

  // Manual sync trigger
  const syncNow = async () => {
    return performSync('manual');
  };

  useEffect(() => {
    if (isAuthenticated && userRole === 'admin') {
      // Initialize auto-sync service when authenticated as admin
      bokunAutoSync.initialize(userRole);

      // Perform initial sync if needed (last sync > 15 minutes ago)
      if (shouldSync()) {
        const delay = setTimeout(() => {
          performSync('startup');
        }, 2000); // Small delay to let the app fully load

        return () => clearTimeout(delay);
      }
    } else {
      // Stop sync when not authenticated or not admin
      bokunAutoSync.stop();
    }
  }, [isAuthenticated, userRole]);

  // Set up periodic sync interval
  useEffect(() => {
    if (!isAuthenticated || userRole !== 'admin') return;

    // Set up 15-minute sync interval
    const syncInterval = setInterval(() => {
      if (shouldSync()) {
        performSync('periodic');
      }
    }, SYNC_INTERVAL_MS);

    return () => clearInterval(syncInterval);
  }, [isAuthenticated, userRole, lastSync]);

  // Listen for sync events from the service
  useEffect(() => {
    if (!isAuthenticated || userRole !== 'admin') return;

    const unsubscribe = bokunAutoSync.addListener((event) => {
      setLastSyncEvent(event);
      setSyncStatus(bokunAutoSync.getStatus());

      // Update local state based on events
      if (event.type === 'sync_started') {
        setIsSyncing(true);
        setSyncError(null);
      } else if (event.type === 'sync_completed') {
        setIsSyncing(false);
        const newSyncTime = new Date();
        setLastSync(newSyncTime);
        localStorage.setItem(STORAGE_KEY, newSyncTime.toISOString());
      } else if (event.type === 'sync_failed') {
        setIsSyncing(false);
        setSyncError(event.error);
      }
    });

    // Update status periodically
    const statusInterval = setInterval(() => {
      setSyncStatus(bokunAutoSync.getStatus());
    }, 30000); // Update every 30 seconds

    return () => {
      unsubscribe();
      clearInterval(statusInterval);
    };
  }, [isAuthenticated, userRole]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      bokunAutoSync.stop();
    };
  }, []);

  return {
    // Primary API (as specified in requirements)
    lastSync,
    isSyncing,
    syncNow,
    error: syncError,

    // Extended API for backward compatibility and additional features
    syncStatus,
    lastSyncEvent,
    syncError,
    updateConfig: (config) => bokunAutoSync.updateConfig(config),
    service: bokunAutoSync
  };
};

// Provider component for the Bokun sync context
export const BokunSyncProvider = ({ children }) => {
  const syncState = useBokunAutoSync();

  return (
    <BokunSyncContext.Provider value={syncState}>
      {children}
    </BokunSyncContext.Provider>
  );
};

/**
 * Hook for other components to access sync status
 * Returns: { lastSync, isSyncing, syncNow, error, lastSyncEvent }
 */
export const useBokunSync = () => {
  const context = useContext(BokunSyncContext);

  if (context === null) {
    // Return default values if used outside provider (backward compatibility)
    return {
      lastSync: null,
      isSyncing: false,
      syncNow: () => Promise.resolve(),
      error: null,
      lastSyncEvent: null
    };
  }

  return {
    lastSync: context.lastSync,
    isSyncing: context.isSyncing,
    syncNow: context.syncNow,
    error: context.error,
    lastSyncEvent: context.lastSyncEvent
  };
};

export default useBokunAutoSync;
