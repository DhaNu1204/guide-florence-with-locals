import React, { useState, useEffect } from 'react';
import axios from 'axios';
import { format } from 'date-fns';

const BokunSync = () => {
  const [config, setConfig] = useState({
    access_key: '',
    secret_key: '',
    vendor_id: '',
    sync_enabled: false,
    auto_assign_guides: false
  });
  const [unassignedTours, setUnassignedTours] = useState([]);
  const [syncing, setSyncing] = useState(false);
  const [showConfig, setShowConfig] = useState(false);
  const [message, setMessage] = useState('');
  const API_BASE = import.meta.env.VITE_API_URL || '/api';

  useEffect(() => {
    loadConfig();
    loadUnassignedTours();
  }, []);

  const loadConfig = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await axios.get(`${API_BASE}/bokun_sync.php?action=config`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (response.data && response.data.configured !== false) {
        setConfig(response.data);
      }
    } catch (error) {
      console.error('Error loading Bokun config:', error);
    }
  };

  const loadUnassignedTours = async () => {
    try {
      const token = localStorage.getItem('token');
      const response = await axios.get(`${API_BASE}/bokun_sync.php?action=unassigned`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      setUnassignedTours(response.data);
    } catch (error) {
      console.error('Error loading unassigned tours:', error);
    }
  };

  const saveConfig = async () => {
    try {
      const token = localStorage.getItem('token');
      await axios.post(`${API_BASE}/bokun_sync.php?action=config`, config, {
        headers: { 
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      setMessage('Configuration saved successfully');
      setShowConfig(false);
      setTimeout(() => setMessage(''), 3000);
    } catch (error) {
      setMessage('Error saving configuration');
      console.error('Error saving config:', error);
    }
  };

  const testConnection = async () => {
    setMessage('Testing Bokun API connection...');
    
    try {
      const token = localStorage.getItem('token');
      const response = await axios.get(`${API_BASE}/bokun_sync.php?action=test`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      
      console.log('Bokun test response:', response.data);
      
      if (response.data.success) {
        setMessage(`✅ Bokun API connection successful! (${response.data.base_url})`);
      } else {
        let errorMsg = '❌ Connection failed: ' + (response.data.error || 'Unknown error');
        
        // Add debug information if available
        if (response.data.debug_info) {
          console.log('Bokun debug info:', response.data.debug_info);
        }
        if (response.data.debug) {
          console.log('Bokun system debug:', response.data.debug);
        }
        if (response.data.error_code) {
          errorMsg += ` (Code: ${response.data.error_code})`;
        }
        
        // Add solution if provided
        if (response.data.solution) {
          errorMsg += `\n\n💡 Solution: ${response.data.solution}`;
        }
        
        setMessage(errorMsg);
      }
    } catch (error) {
      console.error('Error testing connection:', error);
      console.error('Response data:', error.response?.data);
      
      let errorMsg = '❌ Connection test error: ' + error.message;
      if (error.response?.status) {
        errorMsg += ` (HTTP ${error.response.status})`;
      }
      
      setMessage(errorMsg);
    }
    
    setTimeout(() => setMessage(''), 10000); // Show error longer
  };

  const syncBookings = async () => {
    setSyncing(true);
    setMessage('Syncing bookings from Bokun...');
    
    try {
      const token = localStorage.getItem('token');
      const response = await axios.post(`${API_BASE}/bokun_sync.php?action=sync`, {
        start_date: format(new Date(), 'yyyy-MM-dd'),
        end_date: format(new Date(Date.now() + 14 * 24 * 60 * 60 * 1000), 'yyyy-MM-dd')
      }, {
        headers: { 
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.data.success) {
        const { synced_count, total_bookings, start_date, end_date, errors } = response.data;
        let message = `✅ Synced ${synced_count} bookings`;
        if (total_bookings > 0) {
          message += ` (${total_bookings} found in Bokun)`;
        }
        message += `\n📅 Date range: ${start_date} to ${end_date}`;
        if (errors && errors.length > 0) {
          message += `\n⚠️ ${errors.length} errors occurred`;
        }
        setMessage(message);
        loadUnassignedTours();
      } else {
        setMessage(response.data.error || 'Sync failed');
      }
    } catch (error) {
      setMessage('Error syncing bookings');
      console.error('Error syncing:', error);
    } finally {
      setSyncing(false);
      setTimeout(() => setMessage(''), 5000);
    }
  };

  const autoAssignGuide = async (tourId) => {
    try {
      const token = localStorage.getItem('token');
      const response = await axios.post(`${API_BASE}/bokun_sync.php?action=auto-assign`, {
        tour_id: tourId
      }, {
        headers: { 
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        }
      });
      
      if (response.data.success) {
        setMessage(`Assigned guide: ${response.data.guide.name}`);
        loadUnassignedTours();
      } else {
        setMessage(response.data.error || 'Auto-assignment failed');
      }
    } catch (error) {
      setMessage('Error assigning guide');
      console.error('Error:', error);
    }
    setTimeout(() => setMessage(''), 3000);
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6 mb-6">
      <div className="flex justify-between items-center mb-4">
        <h2 className="text-xl font-semibold text-gray-800">Bokun Integration</h2>
        <div className="flex gap-2">
          <button
            onClick={() => setShowConfig(!showConfig)}
            className="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700"
          >
            {showConfig ? 'Hide' : 'Configure'}
          </button>
          {config.access_key && (
            <button
              onClick={testConnection}
              className="px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700"
            >
              Test Connection
            </button>
          )}
          <button
            onClick={syncBookings}
            disabled={syncing || !config.sync_enabled}
            className={`px-4 py-2 rounded text-white ${
              syncing || !config.sync_enabled
                ? 'bg-gray-400 cursor-not-allowed'
                : 'bg-blue-600 hover:bg-blue-700'
            }`}
          >
            {syncing ? 'Syncing...' : 'Sync Now'}
          </button>
        </div>
      </div>

      {message && (
        <div className={`p-3 rounded mb-4 ${
          message.includes('Error') ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'
        }`}>
          {message}
        </div>
      )}

      {showConfig && (
        <div className="bg-gray-50 p-4 rounded mb-4">
          <h3 className="font-semibold mb-3">Bokun API Configuration</h3>
          <div className="space-y-3">
            <div>
              <label className="block text-sm font-medium text-gray-700">Access Key</label>
              <input
                type="text"
                value={config.access_key}
                onChange={(e) => setConfig({...config, access_key: e.target.value})}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                placeholder="Your Bokun Access Key"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Secret Key</label>
              <input
                type="password"
                value={config.secret_key}
                onChange={(e) => setConfig({...config, secret_key: e.target.value})}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                placeholder="Your Bokun Secret Key"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-700">Vendor ID</label>
              <input
                type="text"
                value={config.vendor_id}
                onChange={(e) => setConfig({...config, vendor_id: e.target.value})}
                className="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                placeholder="Your Bokun Vendor ID"
              />
            </div>
            <div className="flex items-center space-x-4">
              <label className="flex items-center">
                <input
                  type="checkbox"
                  checked={config.sync_enabled}
                  onChange={(e) => setConfig({...config, sync_enabled: e.target.checked})}
                  className="mr-2"
                />
                Enable Sync
              </label>
              <label className="flex items-center">
                <input
                  type="checkbox"
                  checked={config.auto_assign_guides}
                  onChange={(e) => setConfig({...config, auto_assign_guides: e.target.checked})}
                  className="mr-2"
                />
                Auto-Assign Guides
              </label>
            </div>
            <button
              onClick={saveConfig}
              className="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700"
            >
              Save Configuration
            </button>
          </div>
        </div>
      )}

      {Array.isArray(unassignedTours) && unassignedTours.length > 0 && (
        <div>
          <h3 className="font-semibold mb-3">
            Unassigned Bokun Tours ({unassignedTours.length})
          </h3>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tour</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Participants</th>
                  <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {unassignedTours.map((tour) => (
                  <tr key={tour.id}>
                    <td className="px-4 py-2 whitespace-nowrap text-sm">
                      {format(new Date(tour.date), 'd MMM yyyy')}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm">{tour.time}</td>
                    <td className="px-4 py-2 text-sm">{tour.title}</td>
                    <td className="px-4 py-2 text-sm">
                      {tour.customer_name}
                      {tour.customer_email && (
                        <div className="text-xs text-gray-500">{tour.customer_email}</div>
                      )}
                    </td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm">{tour.participants || '-'}</td>
                    <td className="px-4 py-2 whitespace-nowrap text-sm">
                      <button
                        onClick={() => autoAssignGuide(tour.id)}
                        className="text-blue-600 hover:text-blue-800"
                      >
                        Auto-Assign
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {config.sync_enabled && Array.isArray(unassignedTours) && unassignedTours.length === 0 && (
        <p className="text-gray-500 text-center py-4">
          All Bokun tours have been assigned guides! 🎉
        </p>
      )}

      {!Array.isArray(unassignedTours) && config.sync_enabled && (
        <p className="text-gray-500 text-center py-4">
          Loading unassigned tours...
        </p>
      )}
    </div>
  );
};

export default BokunSync;