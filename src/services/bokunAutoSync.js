import axios from 'axios';
import { format } from 'date-fns';
import { clearTourCache } from './mysqlDB';
import { notifyForbidden } from './sessionExpiry';
import { isSyncDisabledResponse } from '../utils/bokunConfig';

class BokunAutoSyncService {
  // Step 4.0: the app never starts a Bokun sync on its own. The server cron syncs
  // every 15 minutes and the webhook covers changes; this service only runs the
  // manual "Sync now" (admin) and tells listeners what happened.
  constructor() {
    this.lastSyncTime = null;
    this.syncInProgress = false;
    this.userRole = null;
    this.listeners = new Set();

    // Load last sync time from localStorage
    this.lastSyncTime = localStorage.getItem('bokun_last_sync');
  }

  // Step 1.1: only admins may sync (the API answers 403 otherwise). The stored
  // role (written by AuthContext after the server verified the token) is the
  // source of truth; the role passed to initialize() is the fallback.
  isAdmin() {
    let stored = null;
    try {
      stored = localStorage.getItem('userRole');
    } catch (_) {
      stored = null;
    }
    return (stored || this.userRole) === 'admin';
  }

  // Remember the role verified at login (fallback for isAdmin()). Starts nothing.
  initialize(userRole) {
    this.userRole = userRole;
  }

  // Perform the actual sync. Resolves to true only when a sync really completed
  // (callers use that to stamp lastSync); false when skipped, not allowed or failed.
  async performSync(trigger = 'manual') {
    if (this.syncInProgress) {
      console.log('Sync already in progress, skipping');
      return false;
    }

    if (!this.isAdmin()) {
      // Viewer: no request, no error, lastSync untouched; an explicit click on
      // "Sync now" gets the permission toast.
      if (trigger === 'manual') notifyForbidden();
      this.notifyListeners({ type: 'sync_skipped', trigger, reason: 'not_allowed' });
      return false;
    }

    try {
      this.syncInProgress = true;
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      // Check both possible token keys for compatibility
      const token = localStorage.getItem('authToken') || localStorage.getItem('token');

      if (!token) {
        console.log('No auth token, skipping sync');
        return false;
      }

      console.log(`Starting Bokun sync (trigger: ${trigger})`);
      this.notifyListeners({ type: 'sync_started', trigger });

      // Step 1.2: no config round-trip. action=sync answers {success:false, error:'sync_disabled'}
      // when the server has sync switched off, and that is a skip, not a failure.
      // Perform the sync using GET as specified in the requirements
      // GET /api/bokun_sync.php?action=sync
      // Pass sync type for proper logging (auto/manual/startup/periodic)
      const syncType = trigger === 'manual' ? 'manual' : 'auto';
      const response = await axios.get(`${API_BASE}/bokun_sync.php?action=sync&type=${syncType}&triggered_by=${trigger}`, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });

      if (response.data.success) {
        const { synced_count, total_bookings } = response.data;
        this.lastSyncTime = new Date().toISOString();
        localStorage.setItem('bokun_last_sync', this.lastSyncTime);

        console.log(`Bokun sync completed: ${synced_count} synced bookings (${total_bookings} total)`);

        // Clear stale tour cache and tell open pages to reload fresh data
        // so new/changed bookings appear without logging out
        clearTourCache();
        if (typeof window !== 'undefined') {
          window.dispatchEvent(new CustomEvent('florence:bookings-updated', {
            detail: { trigger, synced_count, total_bookings }
          }));
        }

        this.notifyListeners({
          type: 'sync_completed',
          trigger,
          synced_count,
          total_bookings,
          success: true
        });

        // Show subtle notification only for manual sync with new bookings
        if (synced_count > 0 && trigger === 'manual') {
          this.showNewBookingsNotification(synced_count);
        }
        return true;
      } else if (isSyncDisabledResponse(response.data)) {
        console.log('Bokun sync is disabled on the server');
        this.notifyListeners({ type: 'sync_skipped', trigger, reason: 'sync_disabled' });
        return false;
      } else {
        console.log('Bokun sync failed:', response.data.error);
        this.notifyListeners({
          type: 'sync_failed',
          trigger,
          error: response.data.error
        });
      }
    } catch (error) {
      if (error?.response?.status === 403) {
        // Not allowed (viewer or role changed server-side): not a failure, lastSync untouched.
        this.notifyListeners({ type: 'sync_skipped', trigger, reason: 'not_allowed' });
        return false;
      }
      console.log('Bokun sync error:', error.message);
      this.notifyListeners({
        type: 'sync_failed',
        trigger,
        error: error.message
      });
    } finally {
      this.syncInProgress = false;
    }
    return false;
  }

  // Show a subtle notification for new bookings (like email notifications)
  showNewBookingsNotification(count) {
    // Create a subtle toast notification
    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 transition-all duration-300 transform translate-x-full';
    notification.innerHTML = `
      <div class="flex items-center gap-2">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
          <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/>
          <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/>
        </svg>
        <span>${count} new booking${count > 1 ? 's' : ''} synced</span>
      </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
      notification.classList.remove('translate-x-full');
    }, 100);

    // Auto-remove after 3 seconds
    setTimeout(() => {
      notification.classList.add('translate-x-full');
      setTimeout(() => {
        if (notification.parentNode) {
          notification.parentNode.removeChild(notification);
        }
      }, 300);
    }, 3000);
  }

  // Add listener for sync events
  addListener(callback) {
    this.listeners.add(callback);
    return () => this.listeners.delete(callback);
  }

  // Notify all listeners
  notifyListeners(event) {
    this.listeners.forEach(callback => {
      try {
        callback(event);
      } catch (error) {
        console.error('Error in sync listener:', error);
      }
    });
  }

  // Get sync status
  getStatus() {
    return {
      lastSyncTime: this.lastSyncTime,
      syncInProgress: this.syncInProgress
    };
  }

  // Manual sync trigger
  syncNow() {
    return this.performSync('manual');
  }
}

// Export singleton instance
export default new BokunAutoSyncService();