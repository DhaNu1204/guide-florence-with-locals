import React, { useEffect, useState } from 'react';
import { FiRefreshCw, FiSettings, FiCloud, FiActivity } from 'react-icons/fi';
import { usePageTitle } from '../contexts/PageTitleContext';
import { useAuth } from '../contexts/AuthContext';
import BokunSync from '../components/BokunSync';
import BokunMonitor from '../components/BokunMonitor';
import Card from '../components/UI/Card';

const BokunIntegration = () => {
  const { setPageTitle } = usePageTitle();
  const { isAdmin } = useAuth();
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
              </ul>
            </div>
            
            <div>
              <h4 className="font-medium text-gray-900 mb-2">Known Issues</h4>
              <ul className="space-y-2 text-sm text-gray-600">
                <li>• Booking channel permissions pending</li>
                <li>• API returns empty array despite bookings</li>
                <li>• Escalated to Bokun Advanced Technical Team</li>
              </ul>
            </div>
          </div>
          
          <div className="mt-6 p-4 bg-blue-50 rounded-lg">
            <div className="flex items-start gap-3">
              <FiRefreshCw className="w-5 h-5 text-blue-600 mt-0.5" />
              <div>
                <h4 className="font-medium text-blue-900">Auto-Synchronization</h4>
                <p className="text-sm text-blue-700 mt-1">
                  Once Bokun enables booking channel permissions, tours will automatically synchronize 
                  and be available for guide assignment. The system is ready for immediate activation.
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