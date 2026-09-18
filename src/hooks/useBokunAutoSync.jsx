import { useEffect, useState, useContext, createContext } from 'react';
import { useAuth } from '../contexts/AuthContext';
import bokunAutoSync from '../services/bokunAutoSync';
import { getSyncInfo } from '../services/mysqlDB';

// Create context for Bokun sync state
const BokunSyncContext = createContext(null);

// Step 4.0: the browser never starts a sync on its own (the server cron runs every
// 15 minutes). The hook only refreshes the "last sync" label from the server with a
// light sync-info call, at most once every 5 minutes across all hook instances.
const SYNC_INFO_INTERVAL_MS = 5 * 60 * 1000;
const STORAGE_KEY = 'bokun_last_sync';

let lastInfoFetchAt = 0;
let lastInfoValue = null;

// Latest completed server sync (cron / webhook / manual) as a Date, or null.
const fetchServerLastSync = async () => {
  const now = Date.now();
  if (lastInfoFetchAt && now - lastInfoFetchAt < SYNC_INFO_INTERVAL_MS) {
    return lastInfoValue;
  }
  lastInfoFetchAt = now;
  try {
    const info = await getSyncInfo();
    const iso = info && info.last_sync && info.last_sync.completed_at;
    const parsed = iso ? new Date(iso) : null;
    lastInfoValue = parsed && !isNaN(parsed.getTime()) ? parsed : null;
  } catch (error) {
    // keep the previous value; the label is informational
  }
  return lastInfoValue;
};

// Test helper: forget the shared sync-info throttle state.
export const __resetSyncInfoThrottle = () => {
  lastInfoFetchAt = 0;
  lastInfoValue = null;
};

/**
 * Custom hook for the Bokun sync state
 * - Never syncs by itself (no startup, focus or periodic sync)
 * - "Sync now" (admin) runs a manual sync
 * - lastSync follows the server (sync-info), refreshed at most every 5 minutes
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

  // Perform sync and update state
  const performSync = async (trigger = 'manual') => {
    if (isSyncing) {
      console.log('Sync already in progress, skipping');
      return;
    }

    try {
      setIsSyncing(true);
      setSyncError(null);

      const synced = await bokunAutoSync.performSync(trigger);

      // Step 1.1: only a completed sync moves lastSync; skipped/403/failed do not.
      if (synced) {
        const newSyncTime = new Date();
        setLastSync(newSyncTime);
        localStorage.setItem(STORAGE_KEY, newSyncTime.toISOString());
      }

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
    if (isAuthenticated) {
      // Role fallback for the manual sync gate; starts nothing (step 4.0)
      bokunAutoSync.initialize(userRole);
    }
  }, [isAuthenticated, userRole]);

  // Refresh the "last sync" label from the server: light sync-info call, no action=sync
  useEffect(() => {
    if (!isAuthenticated) return undefined;
    let cancelled = false;

    const refresh = async () => {
      const serverLastSync = await fetchServerLastSync();
      if (!cancelled && serverLastSync) {
        setLastSync((prev) => (prev && new Date(prev) > serverLastSync ? prev : serverLastSync));
      }
    };

    refresh();
    const infoInterval = setInterval(refresh, SYNC_INFO_INTERVAL_MS);

    return () => {
      cancelled = true;
      clearInterval(infoInterval);
    };
  }, [isAuthenticated]);

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
