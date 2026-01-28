import axios from 'axios';
import { format } from 'date-fns';

class BokunAutoSyncService {
  constructor() {
    this.syncInterval = null;
    this.lastSyncTime = null;
    this.syncInProgress = false;
    this.listeners = new Set();

    // Auto-sync configuration
    this.config = {
      enabled: true,
      intervalMinutes: 15, // Sync every 15 minutes like most email apps
      onStartupSync: true, // Sync when app loads
      onFocusSync: true,   // Sync when app gets focus
    };

    // Load last sync time from localStorage
    this.lastSyncTime = localStorage.getItem('bokun_last_sync');

    // Listen for app focus events (like email apps do)
    if (typeof window !== 'undefined') {
      window.addEventListener('focus', this.onAppFocus.bind(this));
      window.addEventListener('visibilitychange', this.onVisibilityChange.bind(this));
    }
  }

  // Initialize auto-sync when user logs in
  initialize(userRole) {
    if (userRole !== 'admin') {
      this.stop(); // Only admins can sync
      return;
    }

    console.log('Initializing Bokun Auto-Sync Service');

    // Perform initial sync if enabled AND last sync was > 15 minutes ago
    if (this.config.onStartupSync && this.shouldSyncOnFocus()) {
      setTimeout(() => {
        this.performSync('startup');
      }, 2000); // Delay to let the app fully load
    } else if (this.config.onStartupSync) {
      console.log('Skipping startup sync - last sync was less than 15 minutes ago');
    }

    // Start periodic sync
    this.startPeriodicSync();
  }

  // Start periodic background sync
  startPeriodicSync() {
    if (this.syncInterval) {
      clearInterval(this.syncInterval);
    }

    if (this.config.enabled) {
      this.syncInterval = setInterval(() => {
        this.performSync('periodic');
      }, this.config.intervalMinutes * 60 * 1000);

      console.log(`Scheduled Bokun sync every ${this.config.intervalMinutes} minutes`);
    }
  }

  // Handle app getting focus (like checking email when you open the app)
  onAppFocus() {
    if (this.config.onFocusSync && this.shouldSyncOnFocus()) {
      setTimeout(() => {
        this.performSync('focus');
      }, 1000);
    }
  }

  // Handle app visibility change
  onVisibilityChange() {
    if (!document.hidden && this.config.onFocusSync && this.shouldSyncOnFocus()) {
      setTimeout(() => {
        this.performSync('visibility');
      }, 1000);
    }
  }

  // Check if we should sync on focus (avoid too frequent syncs)
  // Uses 15-minute interval as specified in requirements
  shouldSyncOnFocus() {
    if (!this.lastSyncTime) return true;

    const lastSync = new Date(this.lastSyncTime);
    const now = new Date();
    const minutesSinceLastSync = (now - lastSync) / (1000 * 60);

    // Only sync if last sync was more than 15 minutes ago (as per requirements)
    return minutesSinceLastSync >= 15;
  }

  // Perform the actual sync
  async performSync(trigger = 'manual') {
    if (this.syncInProgress) {
      console.log('Sync already in progress, skipping');
      return;
    }

    try {
      this.syncInProgress = true;
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      // Check both possible token keys for compatibility
      const token = localStorage.getItem('authToken') || localStorage.getItem('token');

      if (!token) {
        console.log('No auth token, skipping sync');
        return;
      }

      console.log(`Starting Bokun sync (trigger: ${trigger})`);
      this.notifyListeners({ type: 'sync_started', trigger });

      // Check if sync is enabled
      const configResponse = await axios.get(`${API_BASE}/bokun_sync.php?action=config`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });

      if (!configResponse.data?.sync_enabled) {
        console.log('Bokun sync is disabled');
        this.notifyListeners({
          type: 'sync_skipped',
          trigger,
          reason: 'Sync is disabled in configuration'
        });
        return;
      }

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

        this.notifyListeners({
          type: 'sync_completed',
          trigger,
          synced_count,
          total_bookings,
          success: true
        });

        // Show subtle notification if new bookings were found
        if (synced_count > 0 && trigger !== 'manual') {
          this.showNewBookingsNotification(synced_count);
        }
      } else {
        console.log('Bokun sync failed:', response.data.error);
        this.notifyListeners({
          type: 'sync_failed',
          trigger,
          error: response.data.error
        });
      }
    } catch (error) {
      console.log('Bokun sync error:', error.message);
      this.notifyListeners({
        type: 'sync_failed',
        trigger,
        error: error.message
      });
    } finally {
      this.syncInProgress = false;
    }
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

  // Stop auto-sync
  stop() {
    if (this.syncInterval) {
      clearInterval(this.syncInterval);
      this.syncInterval = null;
      console.log('Bokun auto-sync stopped');
    }
  }

  // Update configuration
  updateConfig(newConfig) {
    this.config = { ...this.config, ...newConfig };

    if (this.config.enabled) {
      this.startPeriodicSync();
    } else {
      this.stop();
    }
  }

  // Get sync status
  getStatus() {
    return {
      enabled: this.config.enabled,
      lastSyncTime: this.lastSyncTime,
      syncInProgress: this.syncInProgress,
      intervalMinutes: this.config.intervalMinutes
    };
  }

  // Manual sync trigger
  syncNow() {
    return this.performSync('manual');
  }
}

// Export singleton instance
export default new BokunAutoSyncService();