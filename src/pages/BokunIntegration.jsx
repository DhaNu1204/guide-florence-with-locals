import React, { useEffect, useState } from 'react';
import { FiRefreshCw, FiSettings, FiCloud, FiActivity, FiClock, FiCheck } from 'react-icons/fi';
import { usePageTitle } from '../contexts/PageTitleContext';
import { useAuth } from '../contexts/AuthContext';
import { useBokunAutoSync } from '../hooks/useBokunAutoSync';
import BokunSync from '../components/BokunSync';
import BokunMonitor from '../components/BokunMonitor';
import Card from '../components/UI/Card';
import { format } from 'date-fns';

const BokunIntegration = () => {
  const { setPageTitle } = usePageTitle();
  const { isAdmin } = useAuth();
  const { syncStatus, lastSyncEvent, syncNow } = useBokunAutoSync();
  const [activeTab, setActiveTab] = useState('sync');

  useEffect(() => {
    setPageTitle('Bokun Integration');
    return () => setPageTitle('');
  }, [setPageTitle]);

  if (!isAdmin()) {
    return (
      <div className="space-y-6">
        <Card className="border-red-200 bg-red-50">
          <div className="flex items-center">
            <FiSettings className="h-5 w-5 mr-3 text-red-500" />
            <p className="text-red-700">Access denied. Admin privileges required to access Bokun Integration.</p>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <div className="p-3 bg-blue-100 rounded-lg">
          <FiCloud className="w-8 h-8 text-blue-600" />
        </div>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Bokun Integration</h1>
          <p className="text-gray-600 mt-1">Manage automatic booking synchronization with Bokun API</p>
        </div>
      </div>

      {/* Auto-Sync Status */}
      {syncStatus && (
        <Card className="border-blue-200 bg-blue-50">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className={`p-2 rounded-full ${syncStatus.syncInProgress ? 'bg-yellow-100' : 'bg-green-100'}`}>
                {syncStatus.syncInProgress ? (
                  <FiRefreshCw className="w-4 h-4 text-yellow-600 animate-spin" />
                ) : (
                  <FiCheck className="w-4 h-4 text-green-600" />
                )}
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <h3 className="font-medium text-blue-900">Auto-Sync Status</h3>
                  <span className={`px-2 py-1 text-xs rounded-full ${
                    syncStatus.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'
                  }`}>
                    {syncStatus.enabled ? 'Enabled' : 'Disabled'}
                  </span>
                </div>
                <p className="text-sm text-blue-700">
                  {syncStatus.syncInProgress
                    ? 'Syncing bookings...'
                    : syncStatus.lastSyncTime
                      ? `Last sync: ${format(new Date(syncStatus.lastSyncTime), 'MMM d, HH:mm')}`
                      : 'No sync performed yet'
                  }
                  {syncStatus.enabled && (
                    <span className="ml-2 text-blue-600">
                      • Auto-sync every {syncStatus.intervalMinutes} minutes
                    </span>
                  )}
                </p>
              </div>
            </div>
            <button
              onClick={syncNow}
              disabled={syncStatus.syncInProgress}
              className={`px-3 py-2 text-sm rounded-lg transition-colors ${
                syncStatus.syncInProgress
                  ? 'bg-gray-200 text-gray-400 cursor-not-allowed'
                  : 'bg-blue-600 text-white hover:bg-blue-700'
              }`}
            >
              {syncStatus.syncInProgress ? 'Syncing...' : 'Sync Now'}
            </button>
          </div>
        </Card>
      )}

      {/* Tab Navigation */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-8">
          <button
            onClick={() => setActiveTab('sync')}
            className={`py-2 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'sync'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            <div className="flex items-center gap-2">
              <FiRefreshCw />
              Synchronization
            </div>
          </button>
          <button
            onClick={() => setActiveTab('monitor')}
            className={`py-2 px-1 border-b-2 font-medium text-sm ${
              activeTab === 'monitor'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
            }`}
          >
            <div className="flex items-center gap-2">
              <FiActivity />
              API Monitor
            </div>
          </button>
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'sync' ? (
        <>
          {/* Integration Status & Controls */}
          <BokunSync />

          {/* Information Card */}
      <Card>
        <div className="space-y-4">
          <div className="flex items-center gap-3">
            <FiSettings className="w-5 h-5 text-gray-600" />
            <h3 className="text-lg font-semibold text-gray-900">Integration Information</h3>
          </div>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Current Status</h4>
              <ul className="space-y-2 text-sm text-gray-600">
                <li>• API Connection: Active</li>
                <li>• Authentication: HMAC-SHA1</li>
                <li>• Vendor ID: 96929</li>
                <li>• Rate Limit: 400 requests/minute</li>
                <li>• Auto-Sync: {syncStatus?.enabled ? 'Enabled' : 'Disabled'}</li>
              </ul>
            </div>

            <div>
              <h4 className="font-medium text-gray-900 mb-2">Sync Features</h4>
              <ul className="space-y-2 text-sm text-gray-600">
                <li>• Automatic sync on app startup</li>
                <li>• Background sync every {syncStatus?.intervalMinutes || 15} minutes</li>
                <li>• Smart sync when app regains focus</li>
                <li>• Real-time notifications for new bookings</li>
                <li>• Instant sync with manual trigger</li>
              </ul>
            </div>
          </div>
          
          <div className="mt-6 p-4 bg-green-50 rounded-lg">
            <div className="flex items-start gap-3">
              <FiCheck className="w-5 h-5 text-green-600 mt-0.5" />
              <div>
                <h4 className="font-medium text-green-900">Email-Style Auto-Synchronization</h4>
                <p className="text-sm text-green-700 mt-1">
                  The system now works like modern email applications - automatically checking for new bookings
                  in the background, syncing on app startup, and showing notifications for new arrivals.
                  You'll always have the latest booking information without manual intervention.
                </p>
              </div>
            </div>
          </div>
        </div>
      </Card>
        </>
      ) : (
        <BokunMonitor />
      )}
    </div>
  );
};

export default BokunIntegration;