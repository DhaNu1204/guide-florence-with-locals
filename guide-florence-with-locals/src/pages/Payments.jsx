import React, { useState, useEffect, useMemo } from 'react';
import { usePageTitle } from '../contexts/PageTitleContext';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import DatePicker from "react-datepicker";
import "react-datepicker/dist/react-datepicker.css";
import { format } from "date-fns";
import { FiDollarSign, FiTrendingUp, FiUsers, FiCalendar, FiDownload, FiPlus, FiFilter, FiRefreshCw, FiAlertCircle, FiCheckCircle, FiAlertTriangle, FiX, FiEdit2, FiCheck, FiXCircle, FiTrash2, FiFileText, FiCreditCard } from 'react-icons/fi';
import {
  generateGuidePaymentSummaryPDF,
  generatePendingPaymentsPDF,
  generatePaymentTransactionsPDF
} from '../utils/pdfGenerator';

// Authenticated fetch wrapper - adds Bearer token from localStorage
const authFetch = (url, options = {}) => {
  const token = localStorage.getItem('token');
  return fetch(url, {
    ...options,
    headers: {
      ...options.headers,
      ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    }
  });
};

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

  // Record Payment tab state
  const [selectedTourIds, setSelectedTourIds] = useState(new Set());
  const [recordAmount, setRecordAmount] = useState('');
  const [recordMethod, setRecordMethod] = useState('cash');
  const [recordReference, setRecordReference] = useState('');
  const [recordGuideOverride, setRecordGuideOverride] = useState('');
  const [recordSubmitting, setRecordSubmitting] = useState(false);
  const [guides, setGuides] = useState([]);

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
    loadGuides();
  }, [setPageTitle]);

  const loadPaymentData = async () => {
    try {
      setLoading(true);
      setError(null);

      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

      // Load payment overview
      const overviewResponse = await authFetch(`${API_BASE_URL}/guide-payments.php?action=overview`);
      if (!overviewResponse.ok) throw new Error('Failed to load payment overview');
      const overviewResult = await overviewResponse.json();

      // Load guide payment summaries
      const guidesResponse = await authFetch(`${API_BASE_URL}/guide-payments.php`);
      if (!guidesResponse.ok) throw new Error('Failed to load guide payments');
      const guidesResult = await guidesResponse.json();

      if (overviewResult.success) setPaymentOverview(overviewResult.data);
      if (guidesResult.success) setGuidePayments(guidesResult.data);

      // Load unpaid tours from API - uses server-side logic checking payments table
      // This ensures consistency with the database (tours with NO payment record)
      const pendingToursResponse = await authFetch(`${API_BASE_URL}/guide-payments.php?action=pending_tours`);
      if (pendingToursResponse.ok) {
        const pendingToursResult = await pendingToursResponse.json();
        if (pendingToursResult.success) {
          const unpaidToursData = pendingToursResult.data || [];
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

  // Load guides list for the dropdown fallback
  const loadGuides = async () => {
    try {
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await authFetch(`${API_BASE_URL}/guides.php?per_page=100`);
      if (response.ok) {
        const result = await response.json();
        const data = result?.data || result;
        setGuides(Array.isArray(data) ? data : []);
      }
    } catch (err) {
      console.error('Error loading guides:', err);
    }
  };

  // Detect guide from selected tours
  const selectedToursGuideInfo = useMemo(() => {
    if (selectedTourIds.size === 0) return { guideName: null, guideId: null, mixed: false };
    const selected = unpaidTours.filter(t => {
      const key = t.is_group ? `g${t.group_id}` : `t${t.id}`;
      return selectedTourIds.has(key);
    });
    if (selected.length === 0) return { guideName: null, guideId: null, mixed: false };
    const guideIds = [...new Set(selected.map(t => t.guide_id).filter(Boolean))];
    if (guideIds.length === 1) {
      return { guideName: selected[0].guide_name, guideId: guideIds[0], mixed: false };
    }
    return { guideName: null, guideId: null, mixed: guideIds.length > 1 };
  }, [selectedTourIds, unpaidTours]);

  // Toggle tour selection
  const toggleTourSelection = (tourKey) => {
    setSelectedTourIds(prev => {
      const next = new Set(prev);
      if (next.has(tourKey)) {
        next.delete(tourKey);
      } else {
        next.add(tourKey);
      }
      return next;
    });
  };

  // Select/deselect all tours
  const toggleSelectAll = () => {
    if (selectedTourIds.size === unpaidTours.length) {
      setSelectedTourIds(new Set());
    } else {
      const allKeys = new Set(unpaidTours.map(t => t.is_group ? `g${t.group_id}` : `t${t.id}`));
      setSelectedTourIds(allKeys);
    }
  };

  // Get Italian date/time for payment timestamp
  const getItalianDateTime = () => {
    const now = new Date();
    const italianDate = new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Rome'}).format(now);
    const italianTime = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Europe/Rome', hour12: false, hour: '2-digit', minute: '2-digit'
    }).format(now);
    return { date: italianDate, time: italianTime };
  };

  // Submit payment for selected tours
  const handleRecordPayment = async () => {
    if (selectedTourIds.size === 0) return;

    const amount = parseFloat(recordAmount);
    if (!amount || amount <= 0) {
      showNotification('Please enter a valid amount', 'error');
      return;
    }

    // Determine guide
    const guideId = selectedToursGuideInfo.mixed
      ? parseInt(recordGuideOverride)
      : selectedToursGuideInfo.guideId;

    if (!guideId) {
      showNotification('Please select a guide', 'error');
      return;
    }

    setRecordSubmitting(true);
    const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
    const { date: paymentDate, time: paymentTime } = getItalianDateTime();

    const selectedTours = unpaidTours.filter(t => {
      const key = t.is_group ? `g${t.group_id}` : `t${t.id}`;
      return selectedTourIds.has(key);
    });

    let successCount = 0;
    let errorMessages = [];

    for (const tour of selectedTours) {
      try {
        const tourId = tour.id; // For groups, this is the first tour's ID
        const response = await authFetch(`${API_BASE_URL}/payments.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            tour_id: parseInt(tourId),
            guide_id: parseInt(guideId),
            amount: amount,
            payment_method: recordMethod,
            payment_date: paymentDate,
            payment_time: paymentTime,
            transaction_reference: recordReference || null,
            force_group_payment: false
          })
        });
        const result = await response.json();
        if (response.ok && result.success) {
          successCount++;
        } else {
          errorMessages.push(result.error || result.message || 'Unknown error');
        }
      } catch (err) {
        errorMessages.push(err.message);
      }
    }

    if (successCount > 0) {
      showNotification(`${successCount} payment${successCount > 1 ? 's' : ''} recorded successfully!`, 'success');
      // Optimistic removal: remove paid tours from the list
      setUnpaidTours(prev => prev.filter(t => {
        const key = t.is_group ? `g${t.group_id}` : `t${t.id}`;
        return !selectedTourIds.has(key);
      }));
      // Clear selection & form
      setSelectedTourIds(new Set());
      setRecordAmount('');
      setRecordMethod('cash');
      setRecordReference('');
      setRecordGuideOverride('');
      // Reload data in background
      loadPaymentData();
    }

    if (errorMessages.length > 0) {
      showNotification(`${errorMessages.length} payment(s) failed: ${errorMessages[0]}`, 'error');
    }

    setRecordSubmitting(false);
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

      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      const response = await authFetch(`${API_BASE_URL}/guide-payments.php?guide_id=${guideId}`);
      if (!response.ok) throw new Error('Failed to load guide payment details');

      const result = await response.json();
      if (result.success) {
        const data = result.data;
        setGuideDetails({
          guide_info: {
            guide_name: data.guide_summary?.guide_name,
            guide_email: data.guide_summary?.guide_email,
          },
          summary: {
            total_payments_received: data.guide_summary?.total_payments_received || 0,
            cash_payments: data.guide_summary?.cash_payments || 0,
            bank_payments: data.guide_summary?.bank_payments || 0,
          },
          transactions: data.recent_payments || [],
          unpaid_tours: data.unpaid_tours || []
        });
      } else {
        throw new Error(result.error || 'Failed to load guide payment details');
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

      const response = await authFetch(url);
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
      const response = await authFetch(`${API_BASE_URL}/payments.php?id=${transactionId}`, {
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
      const response = await authFetch(`${API_BASE_URL}/payments.php?id=${paymentId}`, {
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

  // Download PDF report - fetches data and generates professional PDF
  const downloadReport = async (period = '30', startDate = null, endDate = null, guideId = null) => {
    try {
      // Use provided dates or fall back to period-based calculation
      let reportStartDate, reportEndDate;

      if (startDate && endDate) {
        reportStartDate = startDate;
        reportEndDate = endDate;
      } else {
        reportEndDate = new Date().toISOString().split('T')[0];
        reportStartDate = new Date(Date.now() - (period * 24 * 60 * 60 * 1000)).toISOString().split('T')[0];
      }

      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      let url = `${API_BASE_URL}/payment-reports.php?type=detailed&start_date=${reportStartDate}&end_date=${reportEndDate}`;

      if (guideId) {
        url += `&guide_id=${guideId}`;
      }

      // Fetch the data
      const response = await authFetch(url);
      if (!response.ok) throw new Error('Failed to fetch report data');

      const result = await response.json();
      if (!result.success) throw new Error(result.error || 'Failed to load report data');

      const transactions = result.data.transactions || [];

      // Find guide name if filtered
      let guideName = null;
      if (guideId) {
        const guide = guidePayments.find(g => g.guide_id == guideId);
        guideName = guide?.guide_name;
      }

      // Generate PDF
      generatePaymentTransactionsPDF(transactions, {
        startDate: reportStartDate,
        endDate: reportEndDate,
        guideName,
        filename: guideName ? `payment-report-${guideName.toLowerCase().replace(/\s+/g, '-')}` : 'payment-transactions-report'
      });

      showNotification('PDF report generated successfully!', 'success');
    } catch (err) {
      console.error('Error generating PDF report:', err);
      showNotification('Failed to generate PDF report. Please try again.', 'error');
    }
  };

  // Download Guide Payment Summary as PDF
  const downloadGuideSummaryPDF = () => {
    try {
      generateGuidePaymentSummaryPDF(guidePayments, {
        filename: 'guide-payment-summary'
      });
      showNotification('Guide summary PDF generated successfully!', 'success');
    } catch (err) {
      console.error('Error generating guide summary PDF:', err);
      showNotification('Failed to generate PDF. Please try again.', 'error');
    }
  };

  // Download Pending Payments as PDF
  const downloadPendingPaymentsPDF = () => {
    try {
      generatePendingPaymentsPDF(unpaidTours, {
        filename: 'pending-guide-payments'
      });
      showNotification('Pending payments PDF generated successfully!', 'success');
    } catch (err) {
      console.error('Error generating pending payments PDF:', err);
      showNotification('Failed to generate PDF. Please try again.', 'error');
    }
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="flex justify-center items-center h-64">
          <div className="text-stone-500">Loading payment data...</div>
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="p-6">
        <Card className="border-terracotta-200 bg-terracotta-50">
          <div className="text-terracotta-700 text-center">
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
    <div className="p-4 md:p-6 space-y-4 md:space-y-6 overflow-x-hidden">
      {/* Notification */}
      {notification && (
        <div className={`p-4 rounded-tuscan-lg flex items-start space-x-3 ${
          notification.type === 'success'
            ? 'bg-olive-50 border border-olive-200 text-olive-700'
            : notification.type === 'warning'
            ? 'bg-gold-50 border border-gold-200 text-gold-700'
            : 'bg-terracotta-50 border border-terracotta-200 text-terracotta-700'
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
            className="flex-shrink-0 text-stone-400 hover:text-stone-600"
          >
            <FiX className="text-lg" />
          </button>
        </div>
      )}
      {/* Header with Actions */}
      <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
        <div>
          <h1 className="text-xl md:text-2xl font-bold text-stone-900">Payment Management</h1>
          <p className="text-sm md:text-base text-stone-600">Track and manage tour payments</p>
        </div>
        <div className="flex gap-2 w-full sm:w-auto">
          <Button
            onClick={downloadGuideSummaryPDF}
            variant="outline"
            icon={FiFileText}
            className="flex-1 sm:flex-initial"
          >
            <span className="hidden sm:inline">PDF Report</span>
            <span className="sm:hidden">PDF</span>
          </Button>
          <Button
            onClick={() => setActiveTab('record')}
            icon={FiPlus}
            className="flex-1 sm:flex-initial"
          >
            <span className="hidden sm:inline">Record Payment</span>
            <span className="sm:hidden">Record</span>
          </Button>
        </div>
      </div>

      {/* Tab Navigation */}
      <div className="border-b border-stone-200 -mx-4 px-4 md:mx-0 md:px-0">
        <nav className="-mb-px flex space-x-4 md:space-x-8 overflow-x-auto whitespace-nowrap scrollbar-hide">
          {[
            { id: 'overview', name: 'Overview', icon: FiTrendingUp },
            { id: 'pending', name: 'Pending', icon: FiAlertCircle, badge: unpaidTours.length, fullName: 'Pending Payments' },
            { id: 'guides', name: 'Guides', icon: FiUsers, fullName: 'Guide Payments' },
            { id: 'record', name: 'Record', icon: FiPlus, fullName: 'Record Payment' },
            { id: 'reports', name: 'Reports', icon: FiCalendar }
          ].map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`whitespace-nowrap py-3 md:py-4 px-1 border-b-2 font-medium text-sm flex items-center gap-1.5 md:gap-2 flex-shrink-0 min-h-[44px] touch-manipulation ${
                activeTab === tab.id
                  ? 'border-terracotta-500 text-terracotta-600'
                  : 'border-transparent text-stone-500 hover:text-stone-700 hover:border-stone-300'
              }`}
            >
              <tab.icon className="w-4 h-4" />
              <span className="md:hidden">{tab.name}</span>
              <span className="hidden md:inline">{tab.fullName || tab.name}</span>
              {tab.badge > 0 && (
                <span className="ml-1 inline-flex items-center justify-center px-2 py-0.5 text-xs font-medium rounded-full bg-terracotta-100 text-terracotta-800">
                  {tab.badge}
                </span>
              )}
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
                    <FiDollarSign className="h-8 w-8 text-gold-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-stone-500">Total Payments to Guides</div>
                    <div className="text-2xl font-semibold text-stone-900">
                      {formatCurrency(paymentOverview.overall.total_amount)}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiTrendingUp className="h-8 w-8 text-renaissance-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-stone-500">Transactions</div>
                    <div className="text-2xl font-semibold text-stone-900">
                      {paymentOverview.overall.total_transactions}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiDollarSign className="h-8 w-8 text-terracotta-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-stone-500">Avg Payment</div>
                    <div className="text-2xl font-semibold text-stone-900">
                      {formatCurrency(paymentOverview.overall.avg_payment)}
                    </div>
                  </div>
                </div>
              </Card>

              <Card>
                <div className="flex items-center">
                  <div className="flex-shrink-0">
                    <FiCalendar className="h-8 w-8 text-olive-500" />
                  </div>
                  <div className="ml-4">
                    <div className="text-sm font-medium text-stone-500">Last Payment</div>
                    <div className="text-sm font-semibold text-stone-900">
                      {paymentOverview.overall.last_payment || 'N/A'}
                    </div>
                  </div>
                </div>
              </Card>
            </div>
          )}

          {/* Unpaid Tours Alert */}
          {showUnpaidAlert && (
            <Card className="border-l-4 border-l-terracotta-500 bg-terracotta-50">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div className="flex items-center">
                  <FiAlertCircle className="h-6 w-6 text-terracotta-600 mr-3 flex-shrink-0" />
                  <div className="text-terracotta-600">
                    <strong>Unpaid Tours</strong>
                    <p className="text-sm">
                      {unpaidTours.length} completed tours require payment
                    </p>
                  </div>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setActiveTab('guides')}
                  className="self-start sm:self-auto min-h-[44px]"
                >
                  Manage
                </Button>
              </div>
            </Card>
          )}

          {/* Payment Methods Breakdown */}
          {paymentOverview?.payment_methods && paymentOverview.payment_methods.length > 0 && (
            <Card>
              <div className="px-4 md:px-6 py-4 border-b border-stone-200">
                <h3 className="text-base md:text-lg font-medium text-stone-900">Payment Methods</h3>
              </div>
              <div className="p-4 md:p-6">
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                  {paymentOverview.payment_methods.map((method, index) => (
                    <div key={index} className="bg-stone-50 rounded-tuscan-lg p-4">
                      <div className="text-sm font-medium text-stone-500 mb-1">
                        {formatPaymentMethod(method.method)}
                      </div>
                      <div className="text-xl font-semibold text-stone-900">
                        {formatCurrency(method.amount)}
                      </div>
                      <div className="text-sm text-stone-600">
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
              <div className="px-4 md:px-6 py-4 border-b border-stone-200">
                <h3 className="text-base md:text-lg font-medium text-stone-900">Tour Payment Status</h3>
              </div>
              <div className="p-4 md:p-6">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                  {paymentOverview.tour_statuses.map((status, index) => (
                    <div key={index} className="text-center">
                      <div className="text-2xl font-bold text-stone-900">{status.count}</div>
                      <div className="text-sm text-stone-600 capitalize">
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

      {activeTab === 'pending' && (
        <div className="space-y-6">
          <Card>
            <div className="px-4 md:px-6 py-4 border-b border-stone-200">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <div>
                  <h3 className="text-base md:text-lg font-medium text-stone-900">
                    Pending Guide Payments
                    <span className="ml-2 text-sm font-normal text-stone-500">
                      ({unpaidTours.length})
                    </span>
                  </h3>
                  <p className="text-xs md:text-sm text-stone-500 mt-1">
                    Completed tours with assigned guides awaiting payment
                  </p>
                </div>
                {unpaidTours.length > 0 && (
                  <Button
                    onClick={downloadPendingPaymentsPDF}
                    variant="outline"
                    size="sm"
                    icon={FiFileText}
                    className="self-start sm:self-auto"
                  >
                    <span className="hidden sm:inline">Download PDF</span>
                    <span className="sm:hidden">PDF</span>
                  </Button>
                )}
              </div>
            </div>
            {unpaidTours.length === 0 ? (
              <div className="p-8 text-center">
                <FiCheckCircle className="w-12 h-12 text-olive-500 mx-auto mb-4" />
                <h4 className="text-lg font-medium text-stone-900 mb-2">All Caught Up!</h4>
                <p className="text-stone-500">No pending guide payments at this time.</p>
              </div>
            ) : (
              <>
              {/* Desktop Table */}
              <div className="hidden md:block overflow-x-auto">
                <table className="min-w-full divide-y divide-stone-200">
                  <thead className="bg-stone-50">
                    <tr>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Date
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Tour Name
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Guide Name
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Participants
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Expected Payment
                      </th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                        Action
                      </th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-stone-200">
                    {unpaidTours
                      .sort((a, b) => new Date(b.date) - new Date(a.date))
                      .map((tour) => (
                        <tr key={tour.id}>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                            {new Date(tour.date).toLocaleDateString('en-GB', {
                              day: 'numeric',
                              month: 'short',
                              year: 'numeric'
                            })}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <div className="text-sm font-medium text-stone-900 max-w-xs truncate" title={tour.title}>
                              {tour.title}
                            </div>
                            {tour.time && (
                              <div className="text-xs text-stone-500">{tour.time}</div>
                            )}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                            {tour.guide_name || 'Unknown Guide'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                            {tour.participants || tour.adults || '-'}
                            {tour.children > 0 && ` + ${tour.children} children`}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                            {tour.expected_amount ? formatCurrency(tour.expected_amount) : '-'}
                          </td>
                          <td className="px-6 py-4 whitespace-nowrap">
                            <Button
                              size="sm"
                              icon={FiPlus}
                              onClick={() => {
                                setActiveTab('record');
                              }}
                            >
                              Record Payment
                            </Button>
                          </td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </div>

              {/* Mobile Cards */}
              <div className="md:hidden divide-y divide-stone-200">
                {unpaidTours
                  .sort((a, b) => new Date(b.date) - new Date(a.date))
                  .map((tour) => (
                    <div key={tour.id} className="p-4 space-y-2">
                      <div className="flex items-center justify-between">
                        <span className="text-xs font-medium text-stone-500">
                          {new Date(tour.date).toLocaleDateString('en-GB', {
                            day: 'numeric',
                            month: 'short',
                            year: 'numeric'
                          })}
                          {tour.time && ` · ${tour.time}`}
                        </span>
                        {tour.expected_amount ? (
                          <span className="text-sm font-semibold text-terracotta-600">
                            {formatCurrency(tour.expected_amount)}
                          </span>
                        ) : (
                          <span className="text-xs text-stone-400">No amount</span>
                        )}
                      </div>
                      <p className="text-sm font-medium text-stone-900 line-clamp-2 leading-snug">
                        {tour.title}
                      </p>
                      <div className="flex items-center justify-between">
                        <div className="flex items-center gap-3 text-xs text-stone-600">
                          <span className="flex items-center gap-1">
                            <FiUsers className="w-3.5 h-3.5" />
                            {tour.guide_name || 'Unknown Guide'}
                          </span>
                          <span>
                            {tour.participants || tour.adults || '-'} PAX
                            {tour.children > 0 && ` + ${tour.children} child`}
                          </span>
                        </div>
                        <button
                          onClick={() => setActiveTab('record')}
                          className="flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-white bg-terracotta-500 rounded-tuscan min-h-[36px] touch-manipulation"
                        >
                          <FiPlus className="w-3.5 h-3.5" />
                          Pay
                        </button>
                      </div>
                    </div>
                  ))}
              </div>
              </>
            )}
          </Card>
        </div>
      )}

      {activeTab === 'guides' && (
        <div className="space-y-6">
          <Card>
            <div className="px-4 md:px-6 py-4 border-b border-stone-200 flex items-center justify-between gap-2">
              <h3 className="text-base md:text-lg font-medium text-stone-900">Guide Payment Summary</h3>
              <Button
                onClick={downloadGuideSummaryPDF}
                variant="outline"
                size="sm"
                icon={FiFileText}
              >
                <span className="hidden sm:inline">Download PDF</span>
                <span className="sm:hidden">PDF</span>
              </Button>
            </div>
            {/* Desktop Table */}
            <div className="hidden md:block overflow-x-auto">
              <table className="min-w-full divide-y divide-stone-200">
                <thead className="bg-stone-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Guide
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Total Tours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Paid Tours
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Total Payments
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Payment Rate
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-stone-200">
                  {guidePayments.map((guide) => (
                    <tr key={guide.guide_id}>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div>
                          <div className="text-sm font-medium text-stone-900">
                            {guide.guide_name}
                          </div>
                          <div className="text-sm text-stone-500">
                            {guide.guide_email}
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                        {guide.total_tours}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="text-sm text-stone-900">{guide.paid_tours}</span>
                        {guide.unpaid_tours > 0 && (
                          <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                            {guide.unpaid_tours} unpaid
                          </span>
                        )}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                        {formatCurrency(guide.total_payments_received)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-stone-900">
                          {guide.total_tours > 0
                            ? Math.round((guide.paid_tours / guide.total_tours) * 100)
                            : 0}%
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button
                          onClick={() => loadGuideDetails(guide.guide_id)}
                          className="text-terracotta-600 hover:text-terracotta-800"
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

            {/* Mobile Cards */}
            <div className="md:hidden divide-y divide-stone-200">
              {guidePayments.map((guide) => {
                const paymentRate = guide.total_tours > 0
                  ? Math.round((guide.paid_tours / guide.total_tours) * 100)
                  : 0;
                return (
                  <div key={guide.guide_id} className="p-4 space-y-2">
                    <div className="flex items-start justify-between">
                      <div className="min-w-0 flex-1">
                        <div className="text-sm font-medium text-stone-900">{guide.guide_name}</div>
                        <div className="text-xs text-stone-500 truncate">{guide.guide_email}</div>
                      </div>
                      <span className="ml-2 text-lg font-semibold text-stone-900 flex-shrink-0">
                        {formatCurrency(guide.total_payments_received)}
                      </span>
                    </div>
                    <div className="flex items-center gap-3 text-xs text-stone-600">
                      <span>{guide.total_tours} tours</span>
                      <span className="text-stone-300">|</span>
                      <span>{guide.paid_tours} paid</span>
                      {guide.unpaid_tours > 0 && (
                        <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                          {guide.unpaid_tours} unpaid
                        </span>
                      )}
                      <span className="text-stone-300">|</span>
                      <span className={paymentRate === 100 ? 'text-olive-600 font-medium' : 'text-stone-600'}>
                        {paymentRate}%
                      </span>
                    </div>
                    <button
                      onClick={() => loadGuideDetails(guide.guide_id)}
                      disabled={detailsLoading && selectedGuide === guide.guide_id}
                      className="w-full mt-1 py-2 text-sm font-medium text-terracotta-600 bg-terracotta-50 rounded-tuscan min-h-[44px] touch-manipulation active:bg-terracotta-100 disabled:opacity-50"
                    >
                      {detailsLoading && selectedGuide === guide.guide_id ? 'Loading...' : 'View Details'}
                    </button>
                  </div>
                );
              })}
            </div>
          </Card>
        </div>
      )}

      {activeTab === 'record' && (
        <div className="space-y-4 pb-48 md:pb-4">
          {/* Pending Tours List */}
          {unpaidTours.length === 0 ? (
            <Card>
              <div className="p-8 text-center">
                <FiCheckCircle className="w-16 h-16 text-olive-500 mx-auto mb-4" />
                <h4 className="text-lg font-semibold text-stone-900 mb-2">All Tours Paid</h4>
                <p className="text-stone-500">No pending payments at this time.</p>
              </div>
            </Card>
          ) : (
            <>
              {/* Desktop Table */}
              <Card padding="p-0" className="hidden md:block">
                <div className="px-6 py-4 border-b border-stone-200">
                  <div className="flex items-center justify-between">
                    <h3 className="text-base font-medium text-stone-900">
                      Select tours to pay
                      <span className="ml-2 text-sm font-normal text-stone-500">({unpaidTours.length} pending)</span>
                    </h3>
                    {selectedTourIds.size > 0 && (
                      <span className="text-sm font-medium text-terracotta-600">
                        {selectedTourIds.size} selected
                      </span>
                    )}
                  </div>
                </div>
                <div className="overflow-x-auto">
                  <table className="min-w-full divide-y divide-stone-200">
                    <thead className="bg-stone-50">
                      <tr>
                        <th className="px-4 py-3 text-left w-10">
                          <input
                            type="checkbox"
                            checked={selectedTourIds.size === unpaidTours.length && unpaidTours.length > 0}
                            onChange={toggleSelectAll}
                            className="w-4 h-4 text-terracotta-500 border-stone-300 rounded focus:ring-terracotta-500 cursor-pointer"
                          />
                        </th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Date</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Time</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Tour Name</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">PAX</th>
                        <th className="px-4 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Guide</th>
                      </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-stone-100">
                      {unpaidTours
                        .sort((a, b) => new Date(b.date) - new Date(a.date))
                        .map((tour) => {
                          const tourKey = tour.is_group ? `g${tour.group_id}` : `t${tour.id}`;
                          const isSelected = selectedTourIds.has(tourKey);
                          return (
                            <tr
                              key={tourKey}
                              onClick={() => toggleTourSelection(tourKey)}
                              className={`cursor-pointer transition-colors ${
                                isSelected
                                  ? 'bg-terracotta-50/60'
                                  : 'hover:bg-stone-50'
                              }`}
                            >
                              <td className="px-4 py-3">
                                <input
                                  type="checkbox"
                                  checked={isSelected}
                                  onChange={() => {}}
                                  className="w-4 h-4 text-terracotta-500 border-stone-300 rounded focus:ring-terracotta-500 cursor-pointer"
                                />
                              </td>
                              <td className="px-4 py-3 text-sm text-stone-900 whitespace-nowrap">
                                {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                              </td>
                              <td className="px-4 py-3 text-sm text-stone-600 whitespace-nowrap">
                                {tour.time ? tour.time.substring(0, 5) : '-'}
                              </td>
                              <td className="px-4 py-3 text-sm text-stone-900 max-w-xs">
                                <span className="truncate block" title={tour.title}>{tour.title}</span>
                                {tour.is_group && (
                                  <span className="inline-flex items-center px-1.5 py-0.5 mt-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700">
                                    {tour.booking_count} bookings
                                  </span>
                                )}
                              </td>
                              <td className="px-4 py-3 text-sm text-stone-900">
                                {tour.participants || '-'}
                              </td>
                              <td className="px-4 py-3 text-sm text-stone-900">
                                {tour.guide_name || <span className="text-stone-400">Unassigned</span>}
                              </td>
                            </tr>
                          );
                        })}
                    </tbody>
                  </table>
                </div>

                {/* Desktop Payment Form - inline below table */}
                {selectedTourIds.size > 0 && (
                  <div className="border-t-2 border-terracotta-200 bg-gradient-to-b from-terracotta-50/30 to-white p-6">
                    <div className="flex items-center gap-2 mb-4">
                      <FiCreditCard className="w-5 h-5 text-terracotta-600" />
                      <h4 className="text-base font-semibold text-stone-900">
                        Record Payment
                      </h4>
                      <span className="text-sm text-stone-500">
                        — {selectedTourIds.size} tour{selectedTourIds.size > 1 ? 's' : ''} selected
                      </span>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                      {/* Guide */}
                      <div>
                        <label className="block text-sm font-medium text-stone-700 mb-1">Guide</label>
                        {selectedToursGuideInfo.mixed ? (
                          <>
                            <div className="text-xs text-terracotta-600 mb-1">Different guides — select one:</div>
                            <select
                              value={recordGuideOverride}
                              onChange={(e) => setRecordGuideOverride(e.target.value)}
                              className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
                            >
                              <option value="">Select guide...</option>
                              {guides.map(g => (
                                <option key={g.id} value={g.id}>{g.name}</option>
                              ))}
                            </select>
                          </>
                        ) : (
                          <div className="px-3 py-2 bg-stone-50 border border-stone-200 rounded-tuscan text-sm text-stone-900 font-medium">
                            {selectedToursGuideInfo.guideName || 'No guide'}
                          </div>
                        )}
                      </div>

                      {/* Amount */}
                      <div>
                        <label className="block text-sm font-medium text-stone-700 mb-1">Amount (€)</label>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          value={recordAmount}
                          onChange={(e) => setRecordAmount(e.target.value)}
                          placeholder="0.00"
                          className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
                        />
                      </div>

                      {/* Method Toggle */}
                      <div>
                        <label className="block text-sm font-medium text-stone-700 mb-1">Method</label>
                        <div className="flex rounded-tuscan-lg overflow-hidden border border-stone-300">
                          <button
                            type="button"
                            onClick={() => setRecordMethod('cash')}
                            className={`flex-1 px-3 py-2 text-sm font-medium transition-colors ${
                              recordMethod === 'cash'
                                ? 'bg-terracotta-500 text-white'
                                : 'bg-white text-stone-600 hover:bg-stone-50'
                            }`}
                          >
                            Cash
                          </button>
                          <button
                            type="button"
                            onClick={() => setRecordMethod('bank_transfer')}
                            className={`flex-1 px-3 py-2 text-sm font-medium transition-colors border-l border-stone-300 ${
                              recordMethod === 'bank_transfer'
                                ? 'bg-terracotta-500 text-white'
                                : 'bg-white text-stone-600 hover:bg-stone-50'
                            }`}
                          >
                            Bank Transfer
                          </button>
                        </div>
                      </div>

                      {/* Reference */}
                      <div>
                        <label className="block text-sm font-medium text-stone-700 mb-1">Reference <span className="text-stone-400 font-normal">(optional)</span></label>
                        <input
                          type="text"
                          value={recordReference}
                          onChange={(e) => setRecordReference(e.target.value)}
                          placeholder="Receipt #, bank ref..."
                          className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
                        />
                      </div>
                    </div>

                    <div className="mt-4 flex justify-end">
                      <Button
                        onClick={handleRecordPayment}
                        disabled={recordSubmitting || !recordAmount || parseFloat(recordAmount) <= 0}
                        loading={recordSubmitting}
                        icon={FiDollarSign}
                      >
                        Record Payment
                      </Button>
                    </div>
                  </div>
                )}
              </Card>

              {/* Mobile Cards */}
              <div className="md:hidden space-y-2">
                <div className="flex items-center justify-between px-1">
                  <h3 className="text-sm font-medium text-stone-700">
                    Select tours to pay
                    <span className="ml-1 text-stone-400">({unpaidTours.length})</span>
                  </h3>
                  <button
                    onClick={toggleSelectAll}
                    className="text-xs font-medium text-terracotta-600 min-h-[44px] flex items-center px-2 touch-manipulation"
                  >
                    {selectedTourIds.size === unpaidTours.length ? 'Deselect All' : 'Select All'}
                  </button>
                </div>
                {unpaidTours
                  .sort((a, b) => new Date(b.date) - new Date(a.date))
                  .map((tour) => {
                    const tourKey = tour.is_group ? `g${tour.group_id}` : `t${tour.id}`;
                    const isSelected = selectedTourIds.has(tourKey);
                    return (
                      <div
                        key={tourKey}
                        onClick={() => toggleTourSelection(tourKey)}
                        className={`p-3 rounded-tuscan-lg border transition-colors cursor-pointer touch-manipulation ${
                          isSelected
                            ? 'bg-terracotta-50/60 border-terracotta-300'
                            : 'bg-white border-stone-200 active:bg-stone-50'
                        }`}
                      >
                        <div className="flex items-start gap-3">
                          <input
                            type="checkbox"
                            checked={isSelected}
                            onChange={() => {}}
                            className="w-4 h-4 mt-0.5 text-terracotta-500 border-stone-300 rounded focus:ring-terracotta-500 flex-shrink-0"
                          />
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center justify-between gap-2">
                              <span className="text-xs text-stone-500">
                                {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                                {tour.time && ` · ${tour.time.substring(0, 5)}`}
                              </span>
                              <span className="text-xs text-stone-500 flex-shrink-0">
                                {tour.participants || '-'} PAX
                              </span>
                            </div>
                            <p className="text-sm font-medium text-stone-900 line-clamp-2 leading-snug mt-0.5">
                              {tour.title}
                              {tour.is_group && (
                                <span className="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700 align-middle">
                                  {tour.booking_count} bookings
                                </span>
                              )}
                            </p>
                            <div className="text-xs text-stone-500 mt-1">
                              <FiUsers className="w-3 h-3 inline mr-1" />
                              {tour.guide_name || 'Unassigned'}
                            </div>
                          </div>
                        </div>
                      </div>
                    );
                  })}
              </div>

              {/* Mobile Bottom Sheet Payment Form */}
              {selectedTourIds.size > 0 && (
                <div className="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-white border-t-2 border-terracotta-200 shadow-[0_-4px_20px_rgba(0,0,0,0.12)] rounded-t-2xl">
                  {/* Handle */}
                  <div className="flex justify-center pt-2 pb-1">
                    <div className="w-10 h-1 bg-stone-300 rounded-full" />
                  </div>
                  <div className="px-4 pb-4 pt-1 space-y-3 max-h-[50vh] overflow-y-auto overscroll-contain">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-stone-900">
                        {selectedTourIds.size} tour{selectedTourIds.size > 1 ? 's' : ''} selected
                      </span>
                      <button
                        onClick={() => setSelectedTourIds(new Set())}
                        className="text-xs text-stone-500 min-h-[44px] flex items-center px-2 touch-manipulation"
                      >
                        Clear
                      </button>
                    </div>

                    {/* Guide */}
                    {selectedToursGuideInfo.mixed ? (
                      <div>
                        <div className="text-xs text-terracotta-600 mb-1">Different guides — select one:</div>
                        <select
                          value={recordGuideOverride}
                          onChange={(e) => setRecordGuideOverride(e.target.value)}
                          className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 min-h-[44px]"
                        >
                          <option value="">Select guide...</option>
                          {guides.map(g => (
                            <option key={g.id} value={g.id}>{g.name}</option>
                          ))}
                        </select>
                      </div>
                    ) : (
                      <div className="text-sm text-stone-700">
                        Guide: <span className="font-medium text-stone-900">{selectedToursGuideInfo.guideName || 'No guide'}</span>
                      </div>
                    )}

                    {/* Amount + Method row */}
                    <div className="flex gap-3">
                      <div className="flex-1">
                        <label className="block text-xs font-medium text-stone-600 mb-1">Amount (€)</label>
                        <input
                          type="number"
                          inputMode="decimal"
                          step="0.01"
                          min="0"
                          value={recordAmount}
                          onChange={(e) => setRecordAmount(e.target.value)}
                          placeholder="0.00"
                          className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 min-h-[44px]"
                        />
                      </div>
                      <div className="flex-1">
                        <label className="block text-xs font-medium text-stone-600 mb-1">Method</label>
                        <div className="flex rounded-tuscan-lg overflow-hidden border border-stone-300 min-h-[44px]">
                          <button
                            type="button"
                            onClick={() => setRecordMethod('cash')}
                            className={`flex-1 px-2 py-2 text-xs font-medium transition-colors ${
                              recordMethod === 'cash'
                                ? 'bg-terracotta-500 text-white'
                                : 'bg-white text-stone-600'
                            }`}
                          >
                            Cash
                          </button>
                          <button
                            type="button"
                            onClick={() => setRecordMethod('bank_transfer')}
                            className={`flex-1 px-2 py-2 text-xs font-medium transition-colors border-l border-stone-300 ${
                              recordMethod === 'bank_transfer'
                                ? 'bg-terracotta-500 text-white'
                                : 'bg-white text-stone-600'
                            }`}
                          >
                            Bank
                          </button>
                        </div>
                      </div>
                    </div>

                    {/* Reference */}
                    <input
                      type="text"
                      value={recordReference}
                      onChange={(e) => setRecordReference(e.target.value)}
                      placeholder="Reference (optional)"
                      className="w-full border border-stone-300 rounded-tuscan px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 min-h-[44px]"
                    />

                    {/* Submit */}
                    <Button
                      onClick={handleRecordPayment}
                      disabled={recordSubmitting || !recordAmount || parseFloat(recordAmount) <= 0}
                      loading={recordSubmitting}
                      icon={FiDollarSign}
                      fullWidth
                      size="lg"
                    >
                      Record Payment
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </div>
      )}

      {activeTab === 'reports' && (
        <div className="space-y-6">
          {/* Date Range Filter */}
          <Card>
            <div className="px-4 md:px-6 py-4 border-b border-stone-200">
              <h3 className="text-base md:text-lg font-medium text-stone-900 flex items-center gap-2">
                <FiFilter className="w-5 h-5" />
                Payment Report Filters
              </h3>
            </div>
            <div className="p-4 md:p-6">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {/* Start Date */}
                <div>
                  <label className="block text-sm font-medium text-stone-700 mb-2">
                    Start Date
                  </label>
                  <DatePicker
                    selected={reportStartDate}
                    onChange={setReportStartDate}
                    dateFormat="yyyy-MM-dd"
                    className="w-full border border-stone-300 rounded-tuscan px-3 py-2 focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
                    placeholderText="Select start date"
                  />
                </div>

                {/* End Date */}
                <div>
                  <label className="block text-sm font-medium text-stone-700 mb-2">
                    End Date
                  </label>
                  <DatePicker
                    selected={reportEndDate}
                    onChange={setReportEndDate}
                    dateFormat="yyyy-MM-dd"
                    className="w-full border border-stone-300 rounded-tuscan px-3 py-2 focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
                    placeholderText="Select end date"
                    minDate={reportStartDate}
                  />
                </div>

                {/* Guide Filter */}
                <div>
                  <label className="block text-sm font-medium text-stone-700 mb-2">
                    Guide (Optional)
                  </label>
                  <select
                    value={selectedGuideForReport}
                    onChange={(e) => setSelectedGuideForReport(e.target.value)}
                    className="w-full border border-stone-300 rounded-tuscan px-3 py-2 focus:outline-none focus:ring-2 focus:ring-terracotta-500 focus:border-transparent"
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
              <div className="mt-4 pt-4 border-t border-stone-200">
                <div className="text-sm font-medium text-stone-700 mb-2">Quick Filters:</div>
                <div className="flex flex-wrap gap-2 overflow-x-auto whitespace-nowrap scrollbar-hide">
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
            <div className="px-4 md:px-6 py-4 border-b border-stone-200">
              <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <h3 className="text-base md:text-lg font-medium text-stone-900">
                  Payment Report Results
                  {reportData.length > 0 && (
                    <span className="ml-2 text-sm font-normal text-stone-500">
                      ({reportData.length})
                    </span>
                  )}
                </h3>
                {reportData.length > 0 && (
                  <Button
                    onClick={() => {
                      const startDateStr = format(reportStartDate, 'yyyy-MM-dd');
                      const endDateStr = format(reportEndDate, 'yyyy-MM-dd');
                      let guideName = null;
                      if (selectedGuideForReport) {
                        const guide = guidePayments.find(g => g.guide_id == selectedGuideForReport);
                        guideName = guide?.guide_name;
                      }
                      generatePaymentTransactionsPDF(reportData, {
                        startDate: startDateStr,
                        endDate: endDateStr,
                        guideName,
                        filename: guideName ? `payment-report-${guideName.toLowerCase().replace(/\s+/g, '-')}` : 'payment-transactions-report'
                      });
                      showNotification('PDF report generated successfully!', 'success');
                    }}
                    icon={FiFileText}
                    variant="outline"
                    size="sm"
                    className="self-start sm:self-auto"
                  >
                    <span className="hidden sm:inline">Download PDF</span>
                    <span className="sm:hidden">PDF</span>
                  </Button>
                )}
              </div>
            </div>
            <div className="p-4 md:p-6">
              {reportLoading ? (
                <div className="text-center py-8">
                  <div className="text-stone-500">Loading payment report...</div>
                </div>
              ) : reportData.length > 0 ? (
                <div className="space-y-6">
                  {/* Summary Stats */}
                  <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="bg-stone-50 rounded-tuscan-lg p-4">
                      <div className="text-sm font-medium text-stone-500">Total Payments</div>
                      <div className="text-2xl font-semibold text-stone-900">
                        {reportData.length}
                      </div>
                    </div>
                    <div className="bg-stone-50 rounded-tuscan-lg p-4">
                      <div className="text-sm font-medium text-stone-500">Total Amount</div>
                      <div className="text-2xl font-semibold text-stone-900">
                        {formatCurrency(reportData.reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                    <div className="bg-stone-50 rounded-tuscan-lg p-4">
                      <div className="text-sm font-medium text-stone-500">Cash Payments</div>
                      <div className="text-2xl font-semibold text-stone-900">
                        {formatCurrency(reportData.filter(p => p.payment_method === 'cash').reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                    <div className="bg-stone-50 rounded-tuscan-lg p-4">
                      <div className="text-sm font-medium text-stone-500">Bank Transfers</div>
                      <div className="text-2xl font-semibold text-stone-900">
                        {formatCurrency(reportData.filter(p => p.payment_method === 'bank_transfer').reduce((sum, payment) => sum + parseFloat(payment.amount), 0))}
                      </div>
                    </div>
                  </div>

                  {/* Desktop Payment Table */}
                  <div className="hidden md:block overflow-x-auto">
                    <table className="min-w-full divide-y divide-stone-200">
                      <thead className="bg-stone-50">
                        <tr>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Date</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Guide</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Tour</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Amount</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Method</th>
                          <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Reference</th>
                        </tr>
                      </thead>
                      <tbody className="bg-white divide-y divide-stone-200">
                        {reportData.map((payment, index) => (
                          <tr key={index} className={index % 2 === 0 ? 'bg-white' : 'bg-stone-50'}>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                              {payment.payment_date}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                              {payment.guide_name}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                              {payment.tour_title}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                              {formatCurrency(payment.amount)}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                              {formatPaymentMethod(payment.payment_method)}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-500">
                              {payment.transaction_reference || '-'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Mobile Payment Cards */}
                  <div className="md:hidden divide-y divide-stone-200">
                    {reportData.map((payment, index) => (
                      <div key={index} className="py-3 space-y-1.5">
                        <div className="flex items-center justify-between">
                          <span className="text-xs text-stone-500">{payment.payment_date}</span>
                          <span className="text-sm font-semibold text-stone-900">{formatCurrency(payment.amount)}</span>
                        </div>
                        <p className="text-sm text-stone-900 line-clamp-1">{payment.tour_title}</p>
                        <div className="flex items-center gap-2 text-xs text-stone-600">
                          <span className="font-medium">{payment.guide_name}</span>
                          <span className="text-stone-300">|</span>
                          <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-stone-100 text-stone-700">
                            {formatPaymentMethod(payment.payment_method)}
                          </span>
                          {payment.transaction_reference && (
                            <>
                              <span className="text-stone-300">|</span>
                              <span className="truncate">{payment.transaction_reference}</span>
                            </>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              ) : (
                <div className="text-center py-8">
                  <div className="text-stone-500">
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
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-end md:items-center justify-center z-50">
          <div className="bg-white rounded-t-2xl md:rounded-lg max-w-4xl w-full md:mx-4 max-h-[90vh] overflow-y-auto overscroll-contain">
            {/* Mobile handle */}
            <div className="md:hidden flex justify-center pt-2">
              <div className="w-12 h-1.5 bg-stone-300 rounded-full" />
            </div>
            <div className="p-4 md:p-6">
              {/* Modal Header */}
              <div className="flex items-center justify-between mb-6">
                <h3 className="text-lg font-semibold text-stone-900">
                  Guide Payment Details
                </h3>
                <button
                  onClick={closeGuideDetails}
                  className="text-stone-400 hover:text-stone-600"
                >
                  <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>

              {/* Modal Content */}
              {detailsLoading ? (
                <div className="text-center py-8">
                  <div className="text-stone-500">Loading guide details...</div>
                </div>
              ) : guideDetails ? (
                <div className="space-y-6">
                  {/* Guide Info */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Guide Information</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <span className="text-stone-500">Name:</span>
                        <span className="ml-2 font-medium">{guideDetails.guide_info?.guide_name}</span>
                      </div>
                      <div>
                        <span className="text-stone-500">Email:</span>
                        <span className="ml-2">{guideDetails.guide_info?.guide_email}</span>
                      </div>
                    </div>
                  </Card>

                  {/* Payment Summary */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Payment Summary</h4>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div>
                        <span className="text-stone-500">Total Payments:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.total_payments_received || 0)}</span>
                      </div>
                      <div>
                        <span className="text-stone-500">Cash Payments:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.cash_payments || 0)}</span>
                      </div>
                      <div>
                        <span className="text-stone-500">Bank Transfers:</span>
                        <span className="ml-2 font-medium">{formatCurrency(guideDetails.summary?.bank_payments || 0)}</span>
                      </div>
                    </div>
                  </Card>

                  {/* Unpaid Tours */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">
                      Unpaid Tours
                      {guideDetails.unpaid_tours?.length > 0 && (
                        <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                          {guideDetails.unpaid_tours.length}
                        </span>
                      )}
                    </h4>
                    {guideDetails.unpaid_tours && guideDetails.unpaid_tours.length > 0 ? (
                      <>
                        {/* Desktop Table */}
                        <div className="hidden md:block overflow-x-auto">
                          <table className="min-w-full divide-y divide-stone-200">
                            <thead className="bg-stone-50">
                              <tr>
                                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Date</th>
                                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Time</th>
                                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Tour</th>
                                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">PAX</th>
                                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Action</th>
                              </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-stone-100">
                              {guideDetails.unpaid_tours
                                .sort((a, b) => b.date.localeCompare(a.date))
                                .map((tour) => (
                                <tr key={tour.is_group ? `g${tour.group_id}` : `t${tour.id}`} className="hover:bg-stone-50">
                                  <td className="px-4 py-2 text-sm text-stone-900 whitespace-nowrap">
                                    {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
                                  </td>
                                  <td className="px-4 py-2 text-sm text-stone-600 whitespace-nowrap">
                                    {tour.time ? tour.time.substring(0, 5) : '-'}
                                  </td>
                                  <td className="px-4 py-2 text-sm text-stone-900 max-w-[200px] truncate" title={tour.title}>
                                    {tour.title}
                                    {tour.is_group && (
                                      <span className="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700">
                                        {tour.booking_count} bookings
                                      </span>
                                    )}
                                  </td>
                                  <td className="px-4 py-2 text-sm text-stone-900">
                                    {tour.participants || '-'}
                                  </td>
                                  <td className="px-4 py-2">
                                    <button
                                      onClick={() => {
                                        closeGuideDetails();
                                        setActiveTab('record');
                                      }}
                                      className="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-white bg-terracotta-500 hover:bg-terracotta-600 rounded-tuscan transition-colors"
                                    >
                                      <FiPlus className="w-3 h-3" />
                                      Pay
                                    </button>
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        {/* Mobile Cards */}
                        <div className="md:hidden divide-y divide-stone-100 -mx-2">
                          {guideDetails.unpaid_tours
                            .sort((a, b) => b.date.localeCompare(a.date))
                            .map((tour) => (
                            <div key={tour.is_group ? `g${tour.group_id}` : `t${tour.id}`} className="px-2 py-3 flex items-center justify-between gap-2">
                              <div className="min-w-0 flex-1">
                                <div className="text-xs text-stone-500">
                                  {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
                                  {tour.time && ` · ${tour.time.substring(0, 5)}`}
                                  {tour.participants > 0 && ` · ${tour.participants} PAX`}
                                </div>
                                <p className="text-sm text-stone-900 truncate">
                                  {tour.title}
                                  {tour.is_group && (
                                    <span className="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700">
                                      {tour.booking_count} bookings
                                    </span>
                                  )}
                                </p>
                              </div>
                              <button
                                onClick={() => {
                                  closeGuideDetails();
                                  setActiveTab('record');
                                }}
                                className="flex-shrink-0 inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-white bg-terracotta-500 rounded-tuscan min-h-[36px] touch-manipulation"
                              >
                                <FiPlus className="w-3 h-3" />
                                Pay
                              </button>
                            </div>
                          ))}
                        </div>
                      </>
                    ) : (
                      <div className="flex items-center gap-2 text-olive-600 py-3">
                        <FiCheckCircle className="w-5 h-5" />
                        <span className="text-sm font-medium">All tours paid</span>
                      </div>
                    )}
                  </Card>

                  {/* Recent Transactions */}
                  <Card>
                    <h4 className="text-md font-semibold mb-4">Recent Payment Transactions</h4>
                    {guideDetails.transactions && guideDetails.transactions.length > 0 ? (
                      <>
                        {/* Desktop Table */}
                        <div className="hidden md:block overflow-x-auto">
                          <table className="min-w-full divide-y divide-stone-200">
                            <thead className="bg-stone-50">
                              <tr>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Date</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Tour</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Amount</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Method</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Reference</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-stone-500 uppercase tracking-wider">Actions</th>
                              </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-stone-200">
                              {guideDetails.transactions.map((transaction, index) => (
                                <tr key={index}>
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                                    {transaction.payment_date}
                                  </td>
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                                    {transaction.tour_title}
                                  </td>
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                                    {editingTransaction === transaction.id ? (
                                      <input
                                        type="number"
                                        step="0.01"
                                        value={editValues.amount || ''}
                                        onChange={(e) => setEditValues({...editValues, amount: e.target.value})}
                                        className="w-20 px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                                      />
                                    ) : (
                                      formatCurrency(transaction.amount)
                                    )}
                                  </td>
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                                    {editingTransaction === transaction.id ? (
                                      <select
                                        value={editValues.payment_method || ''}
                                        onChange={(e) => setEditValues({...editValues, payment_method: e.target.value})}
                                        className="px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
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
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-500">
                                    {transaction.transaction_reference || '-'}
                                  </td>
                                  <td className="px-6 py-4 whitespace-nowrap text-sm text-stone-900">
                                    {editingTransaction === transaction.id ? (
                                      <div className="flex space-x-2">
                                        <button
                                          onClick={() => saveTransactionEdit(transaction.id)}
                                          className="text-olive-600 hover:text-olive-800"
                                          title="Save changes"
                                        >
                                          <FiCheck className="w-4 h-4" />
                                        </button>
                                        <button
                                          onClick={cancelEditTransaction}
                                          className="text-terracotta-600 hover:text-terracotta-800"
                                          title="Cancel editing"
                                        >
                                          <FiXCircle className="w-4 h-4" />
                                        </button>
                                      </div>
                                    ) : (
                                      <div className="flex space-x-2">
                                        <button
                                          onClick={() => startEditTransaction(transaction)}
                                          className="text-terracotta-600 hover:text-terracotta-800"
                                          title="Edit transaction"
                                        >
                                          <FiEdit2 className="w-4 h-4" />
                                        </button>
                                        <button
                                          onClick={() => deletePayment(transaction.id, transaction.tour_title)}
                                          className="text-terracotta-600 hover:text-terracotta-800"
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

                        {/* Mobile Transaction Cards */}
                        <div className="md:hidden divide-y divide-stone-200 -mx-2">
                          {guideDetails.transactions.map((transaction, index) => (
                            <div key={index} className="px-2 py-3 space-y-2">
                              {editingTransaction === transaction.id ? (
                                /* Editing state */
                                <div className="space-y-3">
                                  <p className="text-sm text-stone-900 line-clamp-1 font-medium">{transaction.tour_title}</p>
                                  <div className="grid grid-cols-2 gap-3">
                                    <div>
                                      <label className="block text-xs text-stone-500 mb-1">Amount</label>
                                      <input
                                        type="number"
                                        step="0.01"
                                        inputMode="decimal"
                                        value={editValues.amount || ''}
                                        onChange={(e) => setEditValues({...editValues, amount: e.target.value})}
                                        className="w-full px-3 py-2 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                                      />
                                    </div>
                                    <div>
                                      <label className="block text-xs text-stone-500 mb-1">Method</label>
                                      <select
                                        value={editValues.payment_method || ''}
                                        onChange={(e) => setEditValues({...editValues, payment_method: e.target.value})}
                                        className="w-full px-3 py-2 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                                      >
                                        {paymentMethods.map(method => (
                                          <option key={method.value} value={method.value}>
                                            {method.label}
                                          </option>
                                        ))}
                                      </select>
                                    </div>
                                  </div>
                                  <div className="flex gap-2">
                                    <button
                                      onClick={() => saveTransactionEdit(transaction.id)}
                                      className="flex-1 py-2 text-sm font-medium text-white bg-olive-600 rounded-tuscan min-h-[44px] touch-manipulation"
                                    >
                                      Save
                                    </button>
                                    <button
                                      onClick={cancelEditTransaction}
                                      className="flex-1 py-2 text-sm font-medium text-stone-700 bg-stone-100 rounded-tuscan min-h-[44px] touch-manipulation"
                                    >
                                      Cancel
                                    </button>
                                  </div>
                                </div>
                              ) : (
                                /* Display state */
                                <>
                                  <div className="flex items-center justify-between">
                                    <span className="text-xs text-stone-500">{transaction.payment_date}</span>
                                    <span className="text-sm font-semibold text-stone-900">{formatCurrency(transaction.amount)}</span>
                                  </div>
                                  <p className="text-sm text-stone-900 line-clamp-1">{transaction.tour_title}</p>
                                  <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-2 text-xs text-stone-600">
                                      <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-stone-100 text-stone-700">
                                        {formatPaymentMethod(transaction.payment_method)}
                                      </span>
                                      {transaction.transaction_reference && (
                                        <span className="truncate max-w-[120px]">{transaction.transaction_reference}</span>
                                      )}
                                    </div>
                                    <div className="flex items-center gap-1">
                                      <button
                                        onClick={() => startEditTransaction(transaction)}
                                        className="p-2 text-terracotta-600 hover:text-terracotta-800 rounded-tuscan min-h-[44px] min-w-[44px] flex items-center justify-center touch-manipulation"
                                        title="Edit"
                                      >
                                        <FiEdit2 className="w-4 h-4" />
                                      </button>
                                      <button
                                        onClick={() => deletePayment(transaction.id, transaction.tour_title)}
                                        className="p-2 text-terracotta-600 hover:text-terracotta-800 rounded-tuscan min-h-[44px] min-w-[44px] flex items-center justify-center touch-manipulation"
                                        title="Delete"
                                      >
                                        <FiTrash2 className="w-4 h-4" />
                                      </button>
                                    </div>
                                  </div>
                                </>
                              )}
                            </div>
                          ))}
                        </div>
                      </>
                    ) : (
                      <div className="text-stone-500 text-center py-4">No payment transactions found</div>
                    )}
                  </Card>
                </div>
              ) : (
                <div className="text-center py-8">
                  <div className="text-terracotta-500">Failed to load guide details</div>
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