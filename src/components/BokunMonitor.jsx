import React, { useState, useEffect } from 'react';
import { FiCheckCircle, FiXCircle, FiAlertCircle, FiRefreshCw, FiActivity, FiShield, FiDatabase, FiSearch } from 'react-icons/fi';

const BokunMonitor = () => {
  const [diagnostics, setDiagnostics] = useState(null);
  const [loading, setLoading] = useState(false);
  const [autoRefresh, setAutoRefresh] = useState(false);
  const [lastCheck, setLastCheck] = useState(null);

  const runDiagnostics = async () => {
    setLoading(true);
    try {
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await fetch(`${API_BASE_URL}/bokun_diagnostics.php`);
      const data = await response.json();
      setDiagnostics(data);
      setLastCheck(new Date());
    } catch (error) {
      console.error('Diagnostics failed:', error);
      setDiagnostics({
        error: true,
        message: error.message
      });
    }
    setLoading(false);
  };

  useEffect(() => {
    runDiagnostics();
  }, []);

  useEffect(() => {
    if (autoRefresh) {
      const interval = setInterval(runDiagnostics, 30000); // Every 30 seconds
      return () => clearInterval(interval);
    }
  }, [autoRefresh]);

  const getStatusIcon = (status) => {
    switch (status) {
      case 'success':
        return <FiCheckCircle className="text-green-500" />;
      case 'failed':
        return <FiXCircle className="text-red-500" />;
      case 'partial':
        return <FiAlertCircle className="text-yellow-500" />;
      default:
        return <FiAlertCircle className="text-gray-500" />;
    }
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'success':
        return 'bg-green-100 text-green-800 border-green-200';
      case 'failed':
        return 'bg-red-100 text-red-800 border-red-200';
      case 'partial':
        return 'bg-yellow-100 text-yellow-800 border-yellow-200';
      default:
        return 'bg-gray-100 text-gray-800 border-gray-200';
    }
  };

  if (!diagnostics && !loading) {
    return (
      <div className="p-6 bg-white rounded-lg shadow">
        <button
          onClick={runDiagnostics}
          className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 flex items-center gap-2"
        >
          <FiActivity /> Run Diagnostics
        </button>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="p-6 bg-white rounded-lg shadow">
        <div className="flex items-center gap-3">
          <FiRefreshCw className="animate-spin text-blue-600" />
          <span>Running diagnostics...</span>
        </div>
      </div>
    );
  }

  if (diagnostics?.error) {
    return (
      <div className="p-6 bg-red-50 rounded-lg shadow border border-red-200">
        <h3 className="text-red-800 font-semibold mb-2">Diagnostics Error</h3>
        <p className="text-red-600">{diagnostics.message}</p>
        <button
          onClick={runDiagnostics}
          className="mt-4 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="bg-white rounded-lg shadow p-6">
        <div className="flex justify-between items-center mb-4">
          <h2 className="text-2xl font-bold flex items-center gap-2">
            <FiActivity className="text-blue-600" />
            Bokun API Monitor
          </h2>
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="rounded"
              />
              <span className="text-sm">Auto-refresh</span>
            </label>
            <button
              onClick={runDiagnostics}
              disabled={loading}
              className="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 flex items-center gap-2 disabled:opacity-50"
            >
              <FiRefreshCw className={loading ? 'animate-spin' : ''} />
              Refresh
            </button>
          </div>
        </div>
        
        {lastCheck && (
          <p className="text-sm text-gray-600">
            Last checked: {lastCheck.toLocaleString()}
          </p>
        )}

        {/* Overall Status */}
        <div className={`mt-4 p-4 rounded-lg border ${diagnostics?.diagnosis?.integration_ready ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`}>
          <div className="flex items-center gap-3">
            {diagnostics?.diagnosis?.integration_ready ? (
              <>
                <FiCheckCircle className="text-green-600 text-2xl" />
                <div>
                  <h3 className="font-semibold text-green-800">Integration Ready</h3>
                  <p className="text-sm text-green-600">All requirements met. Bokun API is fully accessible.</p>
                </div>
              </>
            ) : (
              <>
                <FiXCircle className="text-red-600 text-2xl" />
                <div>
                  <h3 className="font-semibold text-red-800">Integration Not Ready</h3>
                  <p className="text-sm text-red-600">Missing permissions or configuration. See details below.</p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Authentication Status */}
      {diagnostics?.authentication && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <FiShield className="text-blue-600" />
            Authentication
          </h3>
          <div className={`p-4 rounded-lg border ${getStatusColor(diagnostics.authentication.status)}`}>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                {getStatusIcon(diagnostics.authentication.status)}
                <span className="font-medium">{diagnostics.authentication.details?.message || 'Checking...'}</span>
              </div>
              {diagnostics.authentication.details?.vendor_confirmed && (
                <span className="text-sm bg-green-100 text-green-800 px-2 py-1 rounded">
                  Vendor ID: 96929 ✓
                </span>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Permissions */}
      {diagnostics?.permissions && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <FiShield className="text-blue-600" />
            API Permissions
          </h3>
          <div className="space-y-3">
            {diagnostics.permissions.required_permissions.map(perm => (
              <div key={perm} className="flex items-center justify-between p-3 rounded-lg bg-gray-50">
                <span className="font-medium">{perm}</span>
                {diagnostics.permissions.permissions_detected?.includes(perm) ? (
                  <span className="flex items-center gap-2 text-green-600">
                    <FiCheckCircle /> Enabled
                  </span>
                ) : (
                  <span className="flex items-center gap-2 text-red-600">
                    <FiXCircle /> Missing
                  </span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Booking Channels */}
      {diagnostics?.booking_channels && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <FiDatabase className="text-blue-600" />
            Booking Channels
          </h3>
          {diagnostics.booking_channels.channels?.length > 0 ? (
            <div className="space-y-2">
              {diagnostics.booking_channels.channels.map((channel, idx) => (
                <div key={idx} className="flex items-center justify-between p-3 rounded-lg bg-gray-50">
                  <span>{channel.name} (ID: {channel.id})</span>
                  <span className={`px-2 py-1 rounded text-sm ${channel.active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'}`}>
                    {channel.active ? 'Active' : 'Inactive'}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <div className="p-4 bg-red-50 rounded-lg border border-red-200">
              <p className="text-red-800">No booking channels accessible</p>
              <p className="text-sm text-red-600 mt-1">API key needs to be associated with booking channels</p>
            </div>
          )}
        </div>
      )}

      {/* Search Tests */}
      {diagnostics?.search_parameters?.tests && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
            <FiSearch className="text-blue-600" />
            Search Parameter Tests
          </h3>
          <div className="space-y-3">
            {diagnostics.search_parameters.tests.map((test, idx) => (
              <div key={idx} className="p-3 rounded-lg bg-gray-50">
                <div className="flex items-center justify-between">
                  <span className="font-medium">{test.name}</span>
                  <div className="flex items-center gap-3">
                    {test.has_results ? (
                      <span className="text-green-600 flex items-center gap-1">
                        <FiCheckCircle /> {test.count} results
                      </span>
                    ) : (
                      <span className="text-red-600 flex items-center gap-1">
                        <FiXCircle /> No results
                      </span>
                    )}
                  </div>
                </div>
                {test.params && Object.keys(test.params).length > 0 && (
                  <div className="mt-2 text-sm text-gray-600">
                    Parameters: {JSON.stringify(test.params)}
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Endpoint Tests */}
      {diagnostics?.endpoints?.endpoints && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4">API Endpoints Status</h3>
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="text-left py-2">Endpoint</th>
                  <th className="text-left py-2">HTTP Code</th>
                  <th className="text-left py-2">Status</th>
                  <th className="text-left py-2">Data</th>
                </tr>
              </thead>
              <tbody>
                {diagnostics.endpoints.endpoints.map((endpoint, idx) => (
                  <tr key={idx} className="border-b">
                    <td className="py-2">{endpoint.name}</td>
                    <td className="py-2">
                      <span className={`px-2 py-1 rounded text-sm ${
                        endpoint.http_code === 200 ? 'bg-green-100 text-green-800' :
                        endpoint.http_code === 303 ? 'bg-yellow-100 text-yellow-800' :
                        'bg-red-100 text-red-800'
                      }`}>
                        {endpoint.http_code}
                      </span>
                    </td>
                    <td className="py-2">{endpoint.status}</td>
                    <td className="py-2">
                      {endpoint.has_data ? (
                        <FiCheckCircle className="text-green-500" />
                      ) : (
                        <FiXCircle className="text-red-500" />
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Recommendations */}
      {diagnostics?.recommendations && diagnostics.recommendations.length > 0 && (
        <div className="bg-white rounded-lg shadow p-6">
          <h3 className="text-lg font-semibold mb-4 text-red-600">Action Required</h3>
          <div className="space-y-3">
            {diagnostics.recommendations.map((rec, idx) => (
              <div key={idx} className={`p-4 rounded-lg border ${
                rec.priority === 'critical' ? 'bg-red-50 border-red-200' :
                rec.priority === 'high' ? 'bg-yellow-50 border-yellow-200' :
                'bg-blue-50 border-blue-200'
              }`}>
                <div className="flex items-start gap-3">
                  <FiAlertCircle className={`mt-1 ${
                    rec.priority === 'critical' ? 'text-red-600' :
                    rec.priority === 'high' ? 'text-yellow-600' :
                    'text-blue-600'
                  }`} />
                  <div className="flex-1">
                    <h4 className="font-semibold">{rec.issue}</h4>
                    <p className="text-sm mt-1">{rec.action}</p>
                  </div>
                  <span className={`px-2 py-1 rounded text-xs font-medium ${
                    rec.priority === 'critical' ? 'bg-red-100 text-red-800' :
                    rec.priority === 'high' ? 'bg-yellow-100 text-yellow-800' :
                    'bg-blue-100 text-blue-800'
                  }`}>
                    {rec.priority.toUpperCase()}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Support Message Template */}
      {diagnostics?.recommendations?.some(r => r.priority === 'critical') && (
        <div className="bg-blue-50 rounded-lg shadow p-6 border border-blue-200">
          <h3 className="text-lg font-semibold mb-3 text-blue-800">Message for Bokun Support</h3>
          <div className="bg-white p-4 rounded border border-blue-300 font-mono text-sm">
            <p>Dear Bokun Support,</p>
            <br />
            <p>Following up on our previous correspondence about API access for Vendor ID 96929.</p>
            <br />
            <p>Our diagnostics show:</p>
            <ul className="list-disc ml-6 mt-2">
              <li>✅ Authentication: Working correctly</li>
              <li>❌ BOOKINGS_READ permission: Not enabled</li>
              <li>❌ Booking Channel Access: No channels accessible</li>
              <li>⚠️ HTTP 303 redirects on /booking.json/search endpoint</li>
            </ul>
            <br />
            <p>Please enable:</p>
            <ol className="list-decimal ml-6 mt-2">
              <li>BOOKINGS_READ scope for our API key</li>
              <li>BOOKING_CHANNELS_READ scope</li>
              <li>Associate our API key with booking channels for Vendor ID 96929</li>
            </ol>
            <br />
            <p>API Key: ff866a2c-6a77-482b-8b07-e95e51c73b89</p>
            <p>Vendor ID: 96929</p>
            <br />
            <p>Thank you for your assistance.</p>
          </div>
          <button
            onClick={() => {
              const text = document.querySelector('.font-mono').innerText;
              navigator.clipboard.writeText(text);
              alert('Copied to clipboard!');
            }}
            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
          >
            Copy Message
          </button>
        </div>
      )}
    </div>
  );
};

export default BokunMonitor;