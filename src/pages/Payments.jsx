import React, { useState, useEffect } from 'react';
import { usePageTitle } from '../contexts/PageTitleContext';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import PaymentRecordForm from '../components/PaymentRecordForm';
import DatePicker from "react-datepicker";
import "react-datepicker/dist/react-datepicker.css";
import { format } from "date-fns";
import { FiDollarSign, FiTrendingUp, FiUsers, FiCalendar, FiDownload, FiPlus, FiFilter, FiRefreshCw, FiAlertCircle, FiCheckCircle, FiAlertTriangle, FiX, FiEdit2, FiCheck, FiXCircle, FiTrash2 } from 'react-icons/fi';

const Payments = () => {
  const { setPageTitle } = usePageTitle();
  const [activeTab, setActiveTab] = useState('overview');
  const [paymentOverview, setPaymentOverview] = useState(null);
  const [guidePayments, setGuidePayments] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [selectedGuide, setSelectedGuide] = useState(null);
  const [guideDetails, setGuideDetails] = useState(null);
  const [detailsLoading, setDetailsLoading] = useState(false);
  const [unpaidTours, setUnpaidTours] = useState([]);
  const [showUnpaidAlert, setShowUnpaidAlert] = useState(false);
  const [notification, setNotification] = useState(null);

  // Helper function to show notifications
  const showNotification = (message, type = 'error') => {
    setNotification({ message, type });
    setTimeout(() => setNotification(null), 5000); // Auto-dismiss after 5 seconds
  };

  // Get current Italian date
  const getItalianDate = () => {
    const now = new Date();
    const italianDate = new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Rome'}).format(now);
    return new Date(italianDate);
  };

  // Reports tab state
  const [reportStartDate, setReportStartDate] = useState(getItalianDate());
  const [reportEndDate, setReportEndDate] = useState(getItalianDate());
  const [selectedGuideForReport, setSelectedGuideForReport] = useState('');
  const [reportData, setReportData] = useState([]);
  const [reportLoading, setReportLoading] = useState(false);
  const [editingTransaction, setEditingTransaction] = useState(null);
  const [editValues, setEditValues] = useState({});

  useEffect(() => {
    setPageTitle('Payment Management');
    loadPaymentData();
  }, [setPageTitle]);

  const loadPaymentData = async () => {
    try {
      setLoading(true);
      setError(null);

      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

      // Load payment overview
      const overviewResponse = await fetch(`${API_BASE_URL}/guide-payments.php?action=overview`);
      if (!overviewResponse.ok) throw new Error('Failed to load payment overview');
      const overviewResult = await overviewResponse.json();

      // Load guide payment summaries
      const guidesResponse = await fetch(`${API_BASE_URL}/guide-payments.php`);
      if (!guidesResponse.ok) throw new Error('Failed to load guide payments');
      const guidesResult = await guidesResponse.json();

      if (overviewResult.success) setPaymentOverview(overviewResult.data);
      if (guidesResult.success) setGuidePayments(guidesResult.data);

      // Load unpaid tours for the alert
      const toursResponse = await fetch(`${API_BASE_URL}/tours.php`);
      if (toursResponse.ok) {
        const toursResult = await toursResponse.json();
        if (toursResult.success) {
          const allTours = toursResult.data || [];
          const unpaidToursData = allTours.filter(tour => !tour.paid && tour.guide_id);
          setUnpaidTours(unpaidToursData);
          setShowUnpaidAlert(unpaidToursData.length > 0);
        }
      }
    } catch (err) {
      setError(err.message);
      console.error('Error loading payment data:', err);
    } finally {
      setLoading(false);
    }
  };

  const handlePaymentRecorded = (paymentData) => {
    // Reload data after payment is recorded
    loadPaymentData();
    // Switch to overview tab to see updated stats
    setActiveTab('overview');
  };

  const loadGuideDetails = async (guideId) => {
    try {
      setDetailsLoading(true);
      setSelectedGuide(guideId);

      // Get guide info from the existing guidePayments data
      const guideInfo = guidePayments.find(g => g.guide_id == guideId);

      // For now, load payment transactions directly
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await fetch(`${API_BASE_URL}/payments.php?guide_id=${guideId}`);
      if (!response.ok) throw new Error('Failed to load payment transactions');

      const result = await response.json();
      if (result.success) {
        setGuideDetails({
          guide_info: {
            guide_name: guideInfo?.guide_name,
            guide_email: guideInfo?.guide_email,
          },
          summary: {
            total_payments_received: guideInfo?.total_payments_received || 0,
            cash_payments: guideInfo?.cash_payments || 0,
            bank_payments: guideInfo?.bank_payments || 0,
          },
          transactions: result.data || []
        });
      } else {
        throw new Error(result.error || 'Failed to load payment transactions');
      }
    } catch (err) {
      console.error('Error loading guide details:', err);
      showNotification('Failed to load guide details: ' + err.message, 'error');
    } finally {
      setDetailsLoading(false);
    }
  };

  const loadPaymentReports = async () => {
    try {
      setReportLoading(true);

      // Format dates for API
      const startDate = format(reportStartDate, 'yyyy-MM-dd');
      const endDate = format(reportEndDate, 'yyyy-MM-dd');

      // Build API URL with filters using payment-reports.php for proper filtering
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      let url = `${API_BASE_URL}/payment-reports.php?type=detailed&start_date=${startDate}&end_date=${endDate}`;
      if (selectedGuideForReport) {
        url += `&guide_id=${selectedGuideForReport}`;
      }

      const response = await fetch(url);
      if (!response.ok) throw new Error('Failed to load payment reports');

      const result = await response.json();
      if (result.success) {
        // payment-reports.php returns data.transactions for detailed reports
        setReportData(result.data.transactions || []);
      } else {
        setError(result.error || 'Failed to load payment reports');
      }
    } catch (error) {
      console.error('Error loading payment reports:', error);
      setError('Failed to load payment reports');
    } finally {
      setReportLoading(false);
    }
  };

  const closeGuideDetails = () => {
    setSelectedGuide(null);
    setGuideDetails(null);
  };


  const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'EUR'
    }).format(amount || 0);
  };

  const formatPaymentMethod = (method) => {
    return method.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
  };

  // Payment editing functions
  const startEditTransaction = (transaction) => {
    setEditingTransaction(transaction.id);
    setEditValues({
      amount: transaction.amount,
      payment_method: transaction.payment_method
    });
  };

  const cancelEditTransaction = () => {
    setEditingTransaction(null);
    setEditValues({});
  };

  const saveTransactionEdit = async (transactionId) => {
    try {
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await fetch(`${API_BASE_URL}/payments.php?id=${transactionId}`, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          amount: parseFloat(editValues.amount),
          payment_method: editValues.payment_method
        })
      });

      const result = await response.json();

      if (response.ok && result.success) {
        // Reload guide details to show updated data
        await loadGuideDetails(selectedGuide);
        showNotification('Payment transaction updated successfully!', 'success');
        setEditingTransaction(null);
        setEditValues({});
      } else {
        showNotification(result.error || 'Failed to update payment transaction', 'error');
      }
    } catch (error) {
      console.error('Error updating payment transaction:', error);
      showNotification('Network error. Please try again.', 'error');
    }
  };

  const deletePayment = async (paymentId, tourTitle) => {
    // Show confirmation dialog
    const confirmed = window.confirm(
      `Are you sure you want to delete this payment for "${tourTitle}"?\n\nThis action cannot be undone.`
    );

    if (!confirmed) {
      return;
    }

    try {
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await fetch(`${API_BASE_URL}/payments.php?id=${paymentId}`, {
        method: 'DELETE',
        headers: {
          'Content-Type': 'application/json'
        }
      });

      const result = await response.json();

      if (response.ok && result.success) {
        // Reload guide details to show updated data
        await loadGuideDetails(selectedGuide);
        // Also reload the main payment data to update summaries
        await loadPaymentData();
        showNotification('Payment deleted successfully!', 'success');
      } else {
        showNotification(result.error || 'Failed to delete payment', 'error');
      }
    } catch (error) {
      console.error('Error deleting payment:', error);
      showNotification('Network error. Please try again.', 'error');
    }
  };

  const paymentMethods = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'credit_card', label: 'Credit Card' },
    { value: 'paypal', label: 'PayPal' },
    { value: 'other', label: 'Other' }
  ];

  const downloadReport = async (period = '30', startDate = null, endDate = null, guideId = null) => {
    try {
      // Use provided dates or fall back to period-based calculation
      let reportStartDate, reportEndDate;

      if (startDate && endDate) {
        // Use explicitly provided dates
        reportStartDate = startDate;
        reportEndDate = endDate;
      } else {
        // Fall back to period-based calculation
        reportEndDate = new Date().toISOString().split('T')[0];
        reportStartDate = new Date(Date.now() - (period * 24 * 60 * 60 * 1000)).toISOString().split('T')[0];
      }

      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      let url = `${API_BASE_URL}/payment-reports.php?type=export&format=txt&start_date=${reportStartDate}&end_date=${reportEndDate}`;

      // Add guide filter if specified
      if (guideId) {
        url += `&guide_id=${guideId}`;
      }

      // Create a temporary link to download the file
      const link = document.createElement('a');
      link.href = url;
      link.download = `payment-report-${reportStartDate}-to-${reportEndDate}.txt`;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    } catch (err) {
      console.error('Error downloading report:', err);
      showNotification('Failed to download report. Please try again.', 'error');
    }
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="flex justify-center items-center h-64">
          <div className="text-gray-500">Loading payment data...</div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6">
        <Card className="border-red-200 bg-red-50">
          <div className="text-red-700 text-center">
            <p>Error loading payment data: {error}</p>
            <Button
              onClick={loadPaymentData}
              className="mt-4"
              variant="outline"
            >
              Retry
            </Button>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      {/* Notification */}
      {notification && (
        <div className={`p-4 rounded-lg flex items-start space-x-3 ${
          notification.type === 'success'
            ? 'bg-green-50 border border-green-200 text-green-700'
            : notification.type === 'warning'
            ? 'bg-yellow-50 border border-yellow-200 text-yellow-700'
            : 'bg-red-50 border border-red-200 text-red-700'
        }`}>
          <div className="flex-shrink-0">
            {notification.type === 'success' ? (
              <FiCheckCircle className="text-xl" />
            ) : notification.type === 'warning' ? (
              <FiAlertTriangle className="text-xl" />
            ) : (
              <FiAlertCircle className="text-xl" />
            )}
          </div>
          <div className="flex-1">
            <p className="font-medium">
              {notification.type === 'success' ? 'Success' :
               notification.type === 'warning' ? 'Warning' : 'Error'}
            </p>
            <p className="text-sm mt-1">{notification.message}</p>
          </div>
          <button
            onClick={() => setNotification(null)}
            className="flex-shrink-0 text-gray-400 hover:text-gray-600"
          >
            <FiX className="text-lg" />
          </button>
        </div>
      )}
      {/* Header with Actions */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Payment Management</h1>
          <p className="text-gray-600">Track and manage tour payments</p>
        </div>
        <div className="flex gap-2">
          <Button
            onClick={() => downloadReport(30)}
            variant="outline"
            icon={FiDownload}
          >
            Download Report
          </Button>
          <Button
            onClick={() => setActiveTab('record')}
            icon={FiPlus}
          >
            Record Payment
          </Button>
        </div>
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex space-x-8">
          {[
            { id: 'overview', name: 'Overview', icon: FiTrendingUp },
            { id: 'guides', name: 'Guide Payments', icon: FiUsers },
            { id: 'record', name: 'Record Payment', icon: FiPlus },
            { id: 'reports', name: 'Reports', icon: FiCalendar }
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-2 ${
                activeTab === tab.id
                  ? 'border-purple-500 text-purple-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              }`}
            >
              <tab.icon className="w-4 h-4" />
              {tab.name}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab Content */}
      {activeTab === 'overview' && (
        <div className="space-y-6">
          {/* Overview Stats */}
          {paymentOverview?.overall && (
            <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiDollarSign className="h-8 w-8 text-orange-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-gray-500">Total Payments to Guides</div>
                    <div className="text-2xl font-semibold text-gray-900">
                      {formatCurrency(paymentOverview.overall.total_amount)}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiTrendingUp className="h-8 w-8 text-blue-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-gray-500">Transactions</div>
                    <div className="text-2xl font-semibold text-gray-900">
                      {paymentOverview.overall.total_transactions}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiDollarSign className="h-8 w-8 text-purple-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-gray-500">Avg Payment</div>
                    <div className="text-2xl font-semibold text-gray-900">
                      {formatCurrency(paymentOverview.overall.avg_payment)}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiCalendar className="h-8 w-8 text-orange-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-gray-500">Last Payment</div>
                    <div className="text-sm font-semibold text-gray-900">
                      {paymentOverview.overall.last_payment || 'N/A'}
                    </div>
                  </div>
                </div>
              </Card>
            </div>
          )}

          {/* Unpaid Tours Alert */}
          {showUnpaidAlert && (
            <Card className="border-l-4 border-l-red-500 bg-red-50">
              <div className="flex items-center justify-between">
                <div className="flex items-center">
                  <FiAlertCircle className="h-6 w-6 text-red-600 mr-3" />
                  <div className="text-red-600">
                    <strong>Unpaid Tours</strong>
                    <p className="text-sm">
                      {unpaidTours.length} completed tours require payment processing
                    </p>
                  </div>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setActiveTab('guides')}
                >
                  Manage Payments
                </Button>
              </div>
            </Card>
          )}

          {/* Payment Methods Breakdown */}
          {paymentOverview?.payment_methods && paymentOverview.payment_methods.length > 0 && (
            <Card>
              <div className="px-6 py-4 border-b border-gray-200">
                <h3 className="text-lg font-medium text-gray-900">Payment Methods</h3>
              </div>
              <div className="p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {paymentOverview.payment_methods.map((method, index) => (
                    <div key={index} className="bg-gray-50 rounded-lg p-4">
                      <div className="text-sm font-medium text-gray-500 mb-1">
                        {formatPaymentMethod(method.method)}
                      </div>
                      <div className="text-xl font-semibold text-gray-900">
                        {formatCurrency(method.amount)}
                      </div>
                      <div className="text-sm text-gray-600">
                        {method.count} transactions
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </Card>
          )}

          {/* Tour Status Breakdown */}
          {paymentOverview?.tour_statuses && paymentOverview.tour_statuses.length > 0 && (
            <Card>
              <div className="px-6 py-4 border-b border-gray-200">
                <h3 className="text-lg font-medium text-gray-900">Tour Payment Status</h3>
              </div>
              <div className="p-6">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  {paymentOverview.tour_statuses.map((status, index) => (
                    <div key={index} className="text-center">
                      <div className="text-2xl font-bold text-gray-900">{status.count}</div>
                      <div className="text-sm text-gray-600 capitalize">
                        {status.status} Tours
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </Card>
          )}
        </div>
      )}

      {activeTab === 'guides' && (
        <div className="space-y-6">
          <Card>
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900">Guide Payment Summary</h3>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Guide
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Total Tours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Paid Tours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Total Payments
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Payment Rate
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                  {guidePayments.map((guide) => (
                    <tr key={guide.guide_id}>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-gray-900">
                            {guide.guide_name}
                          </div>
                          <div className="text-sm text-gray-500">
                            {guide.guide_email}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {guide.total_tours}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm text-gray-900">{guide.paid_tours}</span>
                        {guide.unpaid_tours > 0 && (
                          <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                            {guide.unpaid_tours} unpaid
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {formatCurrency(guide.total_payments_received)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900">
                          {guide.total_tours > 0
                            ? Math.round((guide.paid_tours / guide.total_tours) * 100)
                            : 0}%
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button
                          onClick={() => loadGuideDetails(guide.guide_id)}
                          className="text-purple-600 hover:text-purple-900"
                          disabled={detailsLoading && selectedGuide === guide.guide_id}
                        >
                          {detailsLoading && selectedGuide === guide.guide_id ? 'Loading...' : 'View Details'}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {activeTab === 'record' && (
        <PaymentRecordForm
          onPaymentRecorded={handlePaymentRecorded}
          onCancel={() => setActiveTab('overview')}
          onShowNotification={showNotification}
        />
      )}

      {activeTab === 'reports' && (
        <div className="space-y-6">
          {/* Date Range Filter */}
          <Card>
            <div className="px-6 py-4 border-b border-gray-200">
              <h3 className="text-lg font-medium text-gray-900 flex items-center gap-2">
                <FiFilter className="w-5 h-5" />
                Payment Report Filters
              </h3>
            </div>
            <div className="p-6">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Start Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Start Date
                  </label>
                  <DatePicker
                    selected={reportStartDate}
                    onChange={setReportStartDate}
                    dateFormat="yyyy-MM-dd"
                    className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    placeholderText="Select start date"
                  />
                </div>

                {/* End Date */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    End Date
                  </label>
                  <DatePicker
                    selected={reportEndDate}
                    onChange={setReportEndDate}
                    dateFormat="yyyy-MM-dd"
                    className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    placeholderText="Select end date"
                    minDate={reportStartDate}
                  />
                </div>

                {/* Guide Filter */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Guide (Optional)
                  </label>
                  <select
                    value={selectedGuideForReport}
                    onChange={(e) => setSelectedGuideForReport(e.target.value)}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                  >
                    <option value="">All Guides</option>
                    {guidePayments.map(guide => (
                      <option key={guide.guide_id} value={guide.guide_id}>
                        {guide.guide_name}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Actions */}
                <div className="flex flex-col justify-end">
                  <Button
                    onClick={loadPaymentReports}
                    icon={FiRefreshCw}
                    disabled={reportLoading}
                    className="mb-2"
                  >
                    {reportLoading ? 'Loading...' : 'Load Report'}
                  </Button>
                </div>
              </div>

              {/* Quick Date Filters */}
              <div className="mt-4 pt-4 border-t border-gray-200">
                <div className="text-sm font-medium text-gray-700 mb-2">Quick Filters:</div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      const today = getItalianDate();
                      setReportStartDate(today);
                      setReportEndDate(today);
                    }}
                  >
                    Today
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      const today = getItalianDate();
                      const lastWeek = new Date(today);
                      lastWeek.setDate(today.getDate() - 7);
                      setReportStartDate(lastWeek);
                      setReportEndDate(today);
                    }}
                  >
                    Last 7 Days
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      const today = getItalianDate();
                      const lastMonth = new Date(today);
                      lastMonth.setDate(today.getDate() - 30);
                      setReportStartDate(lastMonth);
                      setReportEndDate(today);
                    }}
                  >
                    Last 30 Days
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                      const today = getItalianDate();
                      const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                      setReportStartDate(firstOfMonth);
                      setReportEndDate(today);
                    }}
                  >
                    This Month
                  </Button>
                </div>
              </div>
            </div>
          </Card>

          {/* Report Results */}
          <Card>
            <div className="px-6 py-4 border-b border-gray-200">
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-medium text-gray-900">
                  Payment Report Results
                  {reportData.length > 0 && (
                    <span className="ml-2 text-sm font-normal text-gray-500">
                      ({reportData.length} payments found)
                    </span>
                  )}
                </h3>
                {reportData.length > 0 && (
                  <Button
                    onClick={() => {
                      const startDateStr = format(reportStartDate, 'yyyy-MM-dd');
                      const endDateStr = format(reportEndDate, 'yyyy-MM-dd');
                      downloadReport(null, startDateStr, endDateStr, selectedGuideForReport);
                    }}
                    icon={FiDownload}
                    variant="outline"
                    size="sm"
                  >
                    Download Report
                  </Button>
                )}
              </div>
            </div>
            <div className="p-6">
              {reportLoading ? (
                <div className="text-center py-8">
                  <div className="text-gray-500">Loading payment report...</div>
                </div>
              ) : reportData.length > 0 ? (
                <div className="space-y-6">
                  {/* Summary Stats */}
                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="bg-gray-50 rounded-lg p-4">
                      <div className="text-sm font-medium text-gray-500">Total Payments</div>
                      <div className="text-2xl font-semibold text-gray-900">
                        {reportData.length}
                      </div>
                    </div>
                    <div className="bg-gray-50 rounded-lg p-4">
                      <div className="text-sm font-medium text-gray-500">Total Amount</div>
                      <div className="text-2xl font-semibold text-gray-900">
                        {formatCurrency(reportData.reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                    <div className="bg-gray-50 rounded-lg p-4">
                      <div className="text-sm font-medium text-gray-500">Cash Payments</div>
                      <div className="text-2xl font-semibold text-gray-900">
                        {formatCurrency(reportData.filter(p => p.payment_method === 'cash').reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                    <div className="bg-gray-50 rounded-lg p-4">
                      <div className="text-sm font-medium text-gray-500">Bank Transfers</div>
                      <div className="text-2xl font-semibold text-gray-900">
                        {formatCurrency(reportData.filter(p => p.payment_method === 'bank_transfer').reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                  </div>

                  {/* Payment Table */}
                  <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200">
                      <thead className="bg-gray-50">
                        <tr>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guide</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tour</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                        </tr>
                      </thead>
                      <tbody className="bg-white divide-y divide-gray-200">
                        {reportData.map((payment, index) => (
                          <tr key={index} className={index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                              {payment.payment_date}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                              {payment.guide_name}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                              {payment.tour_title}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                              {formatCurrency(payment.amount)}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                              {formatPaymentMethod(payment.payment_method)}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                              {payment.transaction_reference || '-'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </div>
              ) : (
                <div className="text-center py-8">
                  <div className="text-gray-500">
                    {reportStartDate && reportEndDate ?
                      'No payments found for the selected date range and filters.' :
                      'Select a date range and click "Load Report" to view payment data.'
                    }
                  </div>
                </div>
              )}
            </div>
          </Card>
        </div>
      )}

      {/* Guide Details Modal */}
      {selectedGuide && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto">
            <div className="p-6">
              {/* Modal Header */}
              <div className="flex items-center justify-between mb-6">
                <h3 className="text-lg font-semibold text-gray-900">
                  Guide Payment Details
                </h3>
                <button
                  onClick={closeGuideDetails}
                  className="text-gray-400 hover:text-gray-600"
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              {/* Modal Content */}
              {detailsLoading ? (
                <div className="text-center py-8">
                  <div className="text-gray-500">Loading guide details...</div>
                </div>
              ) : guideDetails ? (
                <div className="space-y-6">
                  {/* Guide Info */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Guide Information</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <span className="text-gray-500">Name:</span>
                        <span className="ml-2 font-medium">{guideDetails.guide_info?.guide_name}</span>
                      </div>
                      <div>
                        <span className="text-gray-500">Email:</span>
                        <span className="ml-2">{guideDetails.guide_info?.guide_email}</span>
                      </div>
                    </div>
                  </Card>

                  {/* Payment Summary */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Payment Summary</h4>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div>
                        <span className="text-gray-500">Total Payments:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.total_payments_received || 0)}</span>
                      </div>
                      <div>
                        <span className="text-gray-500">Cash Payments:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.cash_payments || 0)}</span>
                      </div>
                      <div>
                        <span className="text-gray-500">Bank Transfers:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.bank_payments || 0)}</span>
                      </div>
                    </div>
                  </Card>

                  {/* Recent Transactions */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Recent Payment Transactions</h4>
                    {guideDetails.transactions && guideDetails.transactions.length > 0 ? (
                      <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200">
                          <thead className="bg-gray-50">
                            <tr>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tour</th>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                          </thead>
                          <tbody className="bg-white divide-y divide-gray-200">
                            {guideDetails.transactions.map((transaction, index) => (
                              <tr key={index}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                  {transaction.payment_date}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                  {transaction.tour_title}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                  {editingTransaction === transaction.id ? (
                                    <input
                                      type="number"
                                      step="0.01"
                                      value={editValues.amount || ''}
                                      onChange={(e) => setEditValues({...editValues, amount: e.target.value})}
                                      className="w-20 px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                                    />
                                  ) : (
                                    formatCurrency(transaction.amount)
                                  )}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                  {editingTransaction === transaction.id ? (
                                    <select
                                      value={editValues.payment_method || ''}
                                      onChange={(e) => setEditValues({...editValues, payment_method: e.target.value})}
                                      className="px-2 py-1 border border-gray-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                                    >
                                      {paymentMethods.map(method => (
                                        <option key={method.value} value={method.value}>
                                          {method.label}
                                        </option>
                                      ))}
                                    </select>
                                  ) : (
                                    formatPaymentMethod(transaction.payment_method)
                                  )}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                  {transaction.transaction_reference || '-'}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                  {editingTransaction === transaction.id ? (
                                    <div className="flex space-x-2">
                                      <button
                                        onClick={() => saveTransactionEdit(transaction.id)}
                                        className="text-green-600 hover:text-green-800"
                                        title="Save changes"
                                      >
                                        <FiCheck className="w-4 h-4" />
                                      </button>
                                      <button
                                        onClick={cancelEditTransaction}
                                        className="text-red-600 hover:text-red-800"
                                        title="Cancel editing"
                                      >
                                        <FiXCircle className="w-4 h-4" />
                                      </button>
                                    </div>
                                  ) : (
                                    <div className="flex space-x-2">
                                      <button
                                        onClick={() => startEditTransaction(transaction)}
                                        className="text-blue-600 hover:text-blue-800"
                                        title="Edit transaction"
                                      >
                                        <FiEdit2 className="w-4 h-4" />
                                      </button>
                                      <button
                                        onClick={() => deletePayment(transaction.id, transaction.tour_title)}
                                        className="text-red-600 hover:text-red-800"
                                        title="Delete payment"
                                      >
                                        <FiTrash2 className="w-4 h-4" />
                                      </button>
                                    </div>
                                  )}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    ) : (
                      <div className="text-gray-500 text-center py-4">No payment transactions found</div>
                    )}
                  </Card>
                </div>
              ) : (
                <div className="text-center py-8">
                  <div className="text-red-500">Failed to load guide details</div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Payments;