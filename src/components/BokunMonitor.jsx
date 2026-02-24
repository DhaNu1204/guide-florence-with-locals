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
        return <FiCheckCircle className="text-olive-500" />;
      case 'failed':
        return <FiXCircle className="text-terracotta-500" />;
      case 'partial':
        return <FiAlertCircle className="text-gold-500" />;
      default:
        return <FiAlertCircle className="text-stone-500" />;
    }
  };

  const getStatusColor = (status) => {
    switch (status) {
      case 'success':
        return 'bg-olive-100 text-olive-800 border-olive-200';
      case 'failed':
        return 'bg-terracotta-100 text-terracotta-800 border-terracotta-200';
      case 'partial':
        return 'bg-gold-100 text-gold-800 border-gold-200';
      default:
        return 'bg-stone-100 text-stone-800 border-stone-200';
    }
  };

  if (!diagnostics && !loading) {
    return (
      <div className="p-6 bg-white rounded-tuscan-lg shadow-tuscan">
        <button
          onClick={runDiagnostics}
          className="px-4 py-2 bg-terracotta-600 text-white rounded-tuscan hover:bg-terracotta-700 flex items-center gap-2"
        >
          <FiActivity /> Run Diagnostics
        </button>
      </div>
    );
  }

  if (loading) {
    return (
      <div className="p-6 bg-white rounded-tuscan-lg shadow-tuscan">
        <div className="flex items-center gap-3">
          <FiRefreshCw className="animate-spin text-terracotta-600" />
          <span className="text-stone-700">Running diagnostics...</span>
        </div>
      </div>
    );
  }

  if (diagnostics?.error) {
    return (
      <div className="p-6 bg-terracotta-50 rounded-tuscan-lg shadow-tuscan border border-terracotta-200">
        <h3 className="text-terracotta-800 font-semibold mb-2">Diagnostics Error</h3>
        <p className="text-terracotta-600">{diagnostics.message}</p>
        <button
          onClick={runDiagnostics}
          className="mt-4 px-4 py-2 bg-terracotta-600 text-white rounded-tuscan hover:bg-terracotta-700"
        >
          Retry
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
        <div className="flex flex-col md:flex-row md:justify-between md:items-center gap-3 mb-4">
          <h2 className="text-lg md:text-2xl font-bold flex items-center gap-2 text-stone-900">
            <FiActivity className="text-terracotta-600" />
            <span className="hidden md:inline">Bokun API Monitor</span>
            <span className="md:hidden">API Monitor</span>
          </h2>
          <div className="flex items-center gap-3">
            <label className="flex items-center gap-2 text-stone-700">
              <input
                type="checkbox"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="rounded text-terracotta-500 focus:ring-terracotta-500 border-stone-300"
              />
              <span className="text-sm">Auto-refresh</span>
            </label>
            <button
              onClick={runDiagnostics}
              disabled={loading}
              className="min-h-[44px] px-3 md:px-4 py-2 bg-terracotta-600 text-white text-sm rounded-tuscan hover:bg-terracotta-700 flex items-center gap-2 disabled:opacity-50 touch-manipulation"
            >
              <FiRefreshCw className={loading ? 'animate-spin' : ''} />
              Refresh
            </button>
          </div>
        </div>

        {lastCheck && (
          <p className="text-sm text-stone-600">
            Last checked: {lastCheck.toLocaleString()}
          </p>
        )}

        {/* Overall Status */}
        <div className={`mt-4 p-4 rounded-tuscan-lg border ${diagnostics?.diagnosis?.integration_ready ? 'bg-olive-50 border-olive-200' : 'bg-terracotta-50 border-terracotta-200'}`}>
          <div className="flex items-center gap-3">
            {diagnostics?.diagnosis?.integration_ready ? (
              <>
                <FiCheckCircle className="text-olive-600 text-2xl" />
                <div>
                  <h3 className="font-semibold text-olive-800">Integration Ready</h3>
                  <p className="text-sm text-olive-600">All requirements met. Bokun API is fully accessible.</p>
                </div>
              </>
            ) : (
              <>
                <FiXCircle className="text-terracotta-600 text-2xl" />
                <div>
                  <h3 className="font-semibold text-terracotta-800">Integration Not Ready</h3>
                  <p className="text-sm text-terracotta-600">Missing permissions or configuration. See details below.</p>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Authentication Status */}
      {diagnostics?.authentication && (
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 flex items-center gap-2 text-stone-900">
            <FiShield className="text-terracotta-600" />
            Authentication
          </h3>
          <div className={`p-4 rounded-tuscan-lg border ${getStatusColor(diagnostics.authentication.status)}`}>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                {getStatusIcon(diagnostics.authentication.status)}
                <span className="font-medium">{diagnostics.authentication.details?.message || 'Checking...'}</span>
              </div>
              {diagnostics.authentication.details?.vendor_confirmed && (
                <span className="text-sm bg-olive-100 text-olive-800 px-2 py-1 rounded-tuscan">
                  Vendor ID: 96929 ✓
                </span>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Permissions */}
      {diagnostics?.permissions && (
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 flex items-center gap-2 text-stone-900">
            <FiShield className="text-terracotta-600" />
            API Permissions
          </h3>
          <div className="space-y-3">
            {diagnostics.permissions.required_permissions.map(perm => (
              <div key={perm} className="flex items-center justify-between p-3 rounded-tuscan-lg bg-stone-50">
                <span className="font-medium text-stone-800">{perm}</span>
                {diagnostics.permissions.permissions_detected?.includes(perm) ? (
                  <span className="flex items-center gap-2 text-olive-600">
                    <FiCheckCircle /> Enabled
                  </span>
                ) : (
                  <span className="flex items-center gap-2 text-terracotta-600">
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
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 flex items-center gap-2 text-stone-900">
            <FiDatabase className="text-terracotta-600" />
            Booking Channels
          </h3>
          {diagnostics.booking_channels.channels?.length > 0 ? (
            <div className="space-y-2">
              {diagnostics.booking_channels.channels.map((channel, idx) => (
                <div key={idx} className="flex items-center justify-between p-3 rounded-tuscan-lg bg-stone-50">
                  <span className="text-stone-800">{channel.name} (ID: {channel.id})</span>
                  <span className={`px-2 py-1 rounded-tuscan text-sm ${channel.active ? 'bg-olive-100 text-olive-800' : 'bg-stone-100 text-stone-600'}`}>
                    {channel.active ? 'Active' : 'Inactive'}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <div className="p-4 bg-terracotta-50 rounded-tuscan-lg border border-terracotta-200">
              <p className="text-terracotta-800">No booking channels accessible</p>
              <p className="text-sm text-terracotta-600 mt-1">API key needs to be associated with booking channels</p>
            </div>
          )}
        </div>
      )}

      {/* Search Tests */}
      {diagnostics?.search_parameters?.tests && (
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 flex items-center gap-2 text-stone-900">
            <FiSearch className="text-terracotta-600" />
            Search Parameter Tests
          </h3>
          <div className="space-y-3">
            {diagnostics.search_parameters.tests.map((test, idx) => (
              <div key={idx} className="p-3 rounded-tuscan-lg bg-stone-50">
                <div className="flex items-center justify-between">
                  <span className="font-medium text-stone-800">{test.name}</span>
                  <div className="flex items-center gap-3">
                    {test.has_results ? (
                      <span className="text-olive-600 flex items-center gap-1">
                        <FiCheckCircle /> {test.count} results
                      </span>
                    ) : (
                      <span className="text-terracotta-600 flex items-center gap-1">
                        <FiXCircle /> No results
                      </span>
                    )}
                  </div>
                </div>
                {test.params && Object.keys(test.params).length > 0 && (
                  <div className="mt-2 text-sm text-stone-600">
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
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 text-stone-900">API Endpoints Status</h3>
          {/* Desktop Table */}
          <div className="hidden md:block overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b border-stone-200">
                  <th className="text-left py-2 text-stone-700">Endpoint</th>
                  <th className="text-left py-2 text-stone-700">HTTP Code</th>
                  <th className="text-left py-2 text-stone-700">Status</th>
                  <th className="text-left py-2 text-stone-700">Data</th>
                </tr>
              </thead>
              <tbody>
                {diagnostics.endpoints.endpoints.map((endpoint, idx) => (
                  <tr key={idx} className="border-b border-stone-100">
                    <td className="py-2 text-stone-800">{endpoint.name}</td>
                    <td className="py-2">
                      <span className={`px-2 py-1 rounded-tuscan text-sm ${
                        endpoint.http_code === 200 ? 'bg-olive-100 text-olive-800' :
                        endpoint.http_code === 303 ? 'bg-gold-100 text-gold-800' :
                        'bg-terracotta-100 text-terracotta-800'
                      }`}>
                        {endpoint.http_code}
                      </span>
                    </td>
                    <td className="py-2 text-stone-800">{endpoint.status}</td>
                    <td className="py-2">
                      {endpoint.has_data ? (
                        <FiCheckCircle className="text-olive-500" />
                      ) : (
                        <FiXCircle className="text-terracotta-500" />
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {/* Mobile Cards */}
          <div className="md:hidden divide-y divide-stone-100">
            {diagnostics.endpoints.endpoints.map((endpoint, idx) => (
              <div key={idx} className="py-3 flex items-center justify-between">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-stone-800 truncate">{endpoint.name}</p>
                  <p className="text-xs text-stone-500 mt-0.5">{endpoint.status}</p>
                </div>
                <div className="flex items-center gap-2 flex-shrink-0 ml-3">
                  <span className={`px-2 py-0.5 rounded-tuscan text-xs ${
                    endpoint.http_code === 200 ? 'bg-olive-100 text-olive-800' :
                    endpoint.http_code === 303 ? 'bg-gold-100 text-gold-800' :
                    'bg-terracotta-100 text-terracotta-800'
                  }`}>
                    {endpoint.http_code}
                  </span>
                  {endpoint.has_data ? (
                    <FiCheckCircle className="text-olive-500" />
                  ) : (
                    <FiXCircle className="text-terracotta-500" />
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Recommendations */}
      {diagnostics?.recommendations && diagnostics.recommendations.length > 0 && (
        <div className="bg-white rounded-tuscan-lg shadow-tuscan p-4 md:p-6">
          <h3 className="text-base md:text-lg font-semibold mb-4 text-terracotta-600">Action Required</h3>
          <div className="space-y-3">
            {diagnostics.recommendations.map((rec, idx) => (
              <div key={idx} className={`p-4 rounded-tuscan-lg border ${
                rec.priority === 'critical' ? 'bg-terracotta-50 border-terracotta-200' :
                rec.priority === 'high' ? 'bg-gold-50 border-gold-200' :
                'bg-renaissance-50 border-renaissance-200'
              }`}>
                <div className="flex items-start gap-3">
                  <FiAlertCircle className={`mt-1 ${
                    rec.priority === 'critical' ? 'text-terracotta-600' :
                    rec.priority === 'high' ? 'text-gold-600' :
                    'text-renaissance-600'
                  }`} />
                  <div className="flex-1">
                    <h4 className="font-semibold text-stone-900">{rec.issue}</h4>
                    <p className="text-sm mt-1 text-stone-600">{rec.action}</p>
                  </div>
                  <span className={`px-2 py-1 rounded-tuscan text-xs font-medium ${
                    rec.priority === 'critical' ? 'bg-terracotta-100 text-terracotta-800' :
                    rec.priority === 'high' ? 'bg-gold-100 text-gold-800' :
                    'bg-renaissance-100 text-renaissance-800'
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
        <div className="bg-renaissance-50 rounded-tuscan-lg shadow-tuscan p-6 border border-renaissance-200">
          <h3 className="text-lg font-semibold mb-3 text-renaissance-800">Message for Bokun Support</h3>
          <div className="bg-white p-4 rounded-tuscan border border-renaissance-300 font-mono text-sm text-stone-800">
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
            className="mt-4 px-4 py-2 bg-renaissance-600 text-white rounded-tuscan hover:bg-renaissance-700"
          >
            Copy Message
          </button>
        </div>
      )}
    </div>
  );
};

export default BokunMonitor;