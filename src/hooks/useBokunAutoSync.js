import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import bokunAutoSync from '../services/bokunAutoSync';

export const useBokunAutoSync = () => {
  const { isAuthenticated, userRole } = useAuth();
  const [syncStatus, setSyncStatus] = useState(bokunAutoSync.getStatus());
  const [lastSyncEvent, setLastSyncEvent] = useState(null);

  useEffect(() => {
    if (isAuthenticated && userRole) {
      // Initialize auto-sync service when authenticated
      bokunAutoSync.initialize(userRole);

      // Listen for sync events
      const unsubscribe = bokunAutoSync.addListener((event) => {
        setLastSyncEvent(event);
        setSyncStatus(bokunAutoSync.getStatus());
      });

      // Update status periodically
      const statusInterval = setInterval(() => {
        setSyncStatus(bokunAutoSync.getStatus());
      }, 30000); // Update every 30 seconds

      return () => {
        unsubscribe();
        clearInterval(statusInterval);
      };
    } else {
      // Stop sync when not authenticated
      bokunAutoSync.stop();
    }

    return () => {
      bokunAutoSync.stop();
    };
  }, [isAuthenticated, userRole]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      bokunAutoSync.stop();
    };
  }, []);

  return {
    syncStatus,
    lastSyncEvent,
    syncNow: () => bokunAutoSync.syncNow(),
    updateConfig: (config) => bokunAutoSync.updateConfig(config),
    service: bokunAutoSync
  };
};