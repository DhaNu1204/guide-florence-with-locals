import React, { useState, useEffect } from 'react';
import Button from './UI/Button';
import Input from './UI/Input';
import Card from './UI/Card';
import DatePicker from "react-datepicker";
import "react-datepicker/dist/react-datepicker.css";
import { format } from "date-fns";
import { FiSave, FiDollarSign, FiCalendar, FiUser, FiClock } from 'react-icons/fi';
import { getTours, getGuides } from '../services/mysqlDB.js';
import { isTicketProduct } from '../utils/tourFilters';

const PaymentRecordForm = ({ onPaymentRecorded, onCancel, onShowNotification }) => {
  // Get current date and time in Italian timezone
  const getItalianDateTime = () => {
    const now = new Date();
    const italianDate = new Intl.DateTimeFormat('en-CA', {timeZone: 'Europe/Rome'}).format(now);
    const italianTime = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Europe/Rome',
      hour12: false,
      hour: '2-digit',
      minute: '2-digit'
    }).format(now);
    return { date: italianDate, time: italianTime };
  };

  const { date: currentItalianDate, time: currentItalianTime } = getItalianDateTime();

  const [formData, setFormData] = useState({
    tour_date: null, // New field for tour date selection
    tour_id: '',
    guide_id: '',
    amount: '',
    payment_method: 'cash',
    payment_date: currentItalianDate,
    payment_time: currentItalianTime,
    transaction_reference: '',
    notes: ''
  });

  const [tours, setTours] = useState([]);
  const [filteredTours, setFilteredTours] = useState([]); // Tours filtered by selected date
  const [guides, setGuides] = useState([]);
  const [loading, setLoading] = useState(false);
  const [errors, setErrors] = useState({});

  useEffect(() => {
    loadToursAndGuides();
  }, []);

  const loadToursAndGuides = async () => {
    try {
      // Load tours using the cached service (forces fresh data from server)
      console.log('PaymentRecordForm: Loading fresh tour data from mysqlDB service');
      const toursResponse = await getTours(true); // Force refresh to get latest data

      // Extract tours array from paginated response
      const toursData = toursResponse && toursResponse.data ? toursResponse.data : toursResponse;
      setTours(toursData);

      // Load guides using the cached service
      const guidesData = await getGuides();
      setGuides(guidesData);

      console.log('PaymentRecordForm: Loaded', toursData.length, 'tours and', guidesData.length, 'guides');
    } catch (error) {
      console.error('Error loading tours and guides:', error);
    }
  };

  // Function to filter tours by selected date
  const filterToursByDate = (selectedDate) => {
    if (!selectedDate) {
      setFilteredTours([]);
      return;
    }

    const dateString = format(selectedDate, 'yyyy-MM-dd');

    console.log('Filtering tours for date:', dateString);
    console.log('All tours:', tours);

    const filtered = tours.filter(tour => {
      // Check if tour is on the selected date
      if (tour.date !== dateString) return false;

      // Exclude ticket products using smart keyword detection from tourFilters utility
      // Tickets don't need guide payments
      if (isTicketProduct(tour)) {
        console.log(`Tour ${tour.id} excluded: ticket product`);
        return false;
      }

      // Check if tour is cancelled - exclude cancelled tours
      if (tour.cancelled === true || tour.cancelled === 1) {
        console.log(`Tour ${tour.id} excluded: cancelled`);
        return false;
      }

      // Data consistency validation: Clean up inconsistent payment data
      const totalAmountPaid = parseFloat(tour.total_amount_paid) || 0;
      const expectedAmount = parseFloat(tour.expected_amount) || 0;

      // Determine actual payment status based on amounts, not the status field
      let actualPaymentStatus = tour.payment_status || 'unpaid';
      if (totalAmountPaid === 0) {
        actualPaymentStatus = 'unpaid'; // No payment = unpaid, regardless of status field
      } else if (expectedAmount > 0 && totalAmountPaid >= expectedAmount) {
        actualPaymentStatus = 'paid'; // Fully paid
      } else if (totalAmountPaid > 0 && expectedAmount > 0 && totalAmountPaid < expectedAmount) {
        actualPaymentStatus = 'partial'; // Partially paid
      }

      // Get payment and guide status (using cleaned data)
      const isPaidBoolean = tour.paid === true || tour.paid === 1;
      const paymentStatus = actualPaymentStatus; // Use cleaned status
      const needsGuideAssignment = !tour.guide_id || tour.guide_id === null || tour.guide_id === '' || tour.guide_id === 'null';

      console.log(`Tour ${tour.id}:
        - paid boolean: ${isPaidBoolean}
        - original payment_status: ${tour.payment_status}
        - cleaned payment_status: ${paymentStatus}
        - total_amount_paid: ${totalAmountPaid}
        - expected_amount: ${expectedAmount}
        - guide_id: ${tour.guide_id}
        - needs_guide_assignment: ${needsGuideAssignment}`);

      // BUSINESS LOGIC: Include tours if ANY of these conditions are true:
      // 1. Tour needs guide assignment (regardless of payment status)
      // 2. Payment status is explicitly unpaid or partial
      // 3. Tour has no payment recorded (total_amount_paid = 0)
      // 4. Tour has expected amount but not fully paid

      let shouldInclude = false;
      let reason = '';

      // Condition 1: Needs guide assignment
      if (needsGuideAssignment) {
        shouldInclude = true;
        reason = 'needs guide assignment';
      }
      // Condition 2: Explicitly unpaid or partial payment status
      else if (paymentStatus === 'unpaid' || paymentStatus === 'partial') {
        shouldInclude = true;
        reason = 'payment status is ' + paymentStatus;
      }
      // Condition 3: No payment recorded
      else if (totalAmountPaid === 0) {
        shouldInclude = true;
        reason = 'no payment recorded';
      }
      // Condition 4: Expected amount exists but not fully paid
      else if (expectedAmount > 0 && totalAmountPaid < expectedAmount) {
        shouldInclude = true;
        reason = 'partially paid (expected: €' + expectedAmount + ', paid: €' + totalAmountPaid + ')';
      }
      // Exclude fully paid tours with guides assigned
      else {
        reason = 'fully paid and guide assigned';
      }

      console.log(`Tour ${tour.id} ${shouldInclude ? 'INCLUDED' : 'EXCLUDED'}: ${reason}`);
      return shouldInclude;
    });

    console.log('Filtered tours:', filtered);
    setFilteredTours(filtered);
  };

  // Handle date picker changes
  const handleDateChange = (date) => {
    // Update current payment date and time when tour date is selected
    const currentDateTime = getItalianDateTime();

    setFormData(prev => ({
      ...prev,
      tour_date: date,
      tour_id: '', // Reset tour selection
      guide_id: '', // Reset guide selection
      payment_date: currentDateTime.date, // Update to current date
      payment_time: currentDateTime.time  // Update to current time
    }));

    // Clear errors
    if (errors.tour_date) {
      setErrors(prev => ({ ...prev, tour_date: '' }));
    }

    // Filter tours by selected date
    filterToursByDate(date);
  };

  const handleInputChange = (e) => {
    const { name, value } = e.target;

    // Update current payment date and time on any form interaction
    const currentDateTime = getItalianDateTime();

    setFormData(prev => ({
      ...prev,
      [name]: value,
      payment_date: currentDateTime.date, // Always update to current date
      payment_time: currentDateTime.time  // Always update to current time
    }));

    // Clear error when user starts typing
    if (errors[name]) {
      setErrors(prev => ({
        ...prev,
        [name]: ''
      }));
    }

    // Auto-select guide when tour is selected
    if (name === 'tour_id' && value) {
      const selectedTour = filteredTours.find(tour => tour.id && tour.id.toString() === value);
      if (selectedTour && selectedTour.guide_id && selectedTour.guide_id !== null && selectedTour.guide_id !== '' && selectedTour.guide_id !== 'null') {
        setFormData(prev => ({
          ...prev,
          guide_id: selectedTour.guide_id.toString()
        }));
      } else {
        // Clear guide selection if tour has no guide assigned
        setFormData(prev => ({
          ...prev,
          guide_id: ''
        }));
      }
    }
  };

  const validateForm = () => {
    const newErrors = {};

    if (!formData.tour_date) {
      newErrors.tour_date = 'Please select a tour date first';
    }
    if (!formData.tour_id) {
      newErrors.tour_id = 'Tour is required';
    }
    if (!formData.guide_id) {
      newErrors.guide_id = 'Guide is required';
    }
    if (!formData.amount || parseFloat(formData.amount) <= 0) {
      newErrors.amount = 'Valid amount is required';
    }
    if (!formData.payment_date) {
      newErrors.payment_date = 'Payment date is required';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    if (!validateForm()) {
      return;
    }

    setLoading(true);

    try {
      const response = await fetch('http://localhost:8080/api/payments.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          tour_id: parseInt(formData.tour_id),
          guide_id: parseInt(formData.guide_id),
          amount: parseFloat(formData.amount),
          payment_method: formData.payment_method,
          payment_date: formData.payment_date,
          payment_time: formData.payment_time || null,
          transaction_reference: formData.transaction_reference || null,
          notes: formData.notes || null
        })
      });

      const result = await response.json();

      if (response.ok && result.success) {
        // Reset form with current Italian date/time
        const resetDateTime = getItalianDateTime();
        setFormData({
          tour_date: null,
          tour_id: '',
          guide_id: '',
          amount: '',
          payment_method: 'cash',
          payment_date: resetDateTime.date,
          payment_time: resetDateTime.time,
          transaction_reference: '',
          notes: ''
        });

        // Clear filtered tours
        setFilteredTours([]);

        // Notify parent component
        if (onPaymentRecorded) {
          onPaymentRecorded(result.data);
        }

        if (onShowNotification) {
          onShowNotification('Payment recorded successfully!', 'success');
        }
      } else {
        setErrors({ submit: result.error || 'Failed to record payment' });
      }
    } catch (error) {
      console.error('Error recording payment:', error);
      setErrors({ submit: 'Network error. Please try again.' });
    } finally {
      setLoading(false);
    }
  };

  const paymentMethods = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'credit_card', label: 'Credit Card' },
    { value: 'paypal', label: 'PayPal' },
    { value: 'other', label: 'Other' }
  ];

  const selectedTour = filteredTours.find(tour => tour.id && tour.id.toString() === formData.tour_id);

  return (
    <Card>
      <div className="px-6 py-4 border-b border-gray-200">
        <h3 className="text-lg font-medium text-gray-900 flex items-center gap-2">
          <FiDollarSign className="w-5 h-5" />
          Record New Payment
        </h3>
      </div>

      <form onSubmit={handleSubmit} className="p-6 space-y-6">
        {errors.submit && (
          <div className="bg-red-50 border border-red-200 rounded-md p-4">
            <div className="text-red-700 text-sm">{errors.submit}</div>
          </div>
        )}

        {/* Step 1: Tour Date Selection */}
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-2">
            <FiCalendar className="inline w-4 h-4 mr-1" />
            Step 1: Select Tour Date *
          </label>
          <DatePicker
            selected={formData.tour_date}
            onChange={handleDateChange}
            dateFormat="yyyy-MM-dd"
            className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
            placeholderText="Choose a date to see available tours..."
            minDate={new Date('2024-01-01')} // Allow past dates for payments
            maxDate={new Date(Date.now() + 365 * 24 * 60 * 60 * 1000)} // One year from now
          />
          {errors.tour_date && <div className="text-red-600 text-sm mt-1">{errors.tour_date}</div>}

          {formData.tour_date && filteredTours.length === 0 && (
            <div className="text-amber-600 text-sm mt-1">
              No unpaid tours found for {format(formData.tour_date, 'yyyy-MM-dd')}
            </div>
          )}
        </div>

        {/* Step 2: Tour Selection */}
        {formData.tour_date && filteredTours.length > 0 && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Step 2: Select Tour *
            </label>
            <select
              name="tour_id"
              value={formData.tour_id}
              onChange={handleInputChange}
              className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
              required
            >
              <option value="">Choose a tour from {format(formData.tour_date, 'd MMM yyyy')}...</option>
              {filteredTours.map(tour => {
                const needsGuide = !tour.guide_id || tour.guide_id === null || tour.guide_id === '' || tour.guide_id === 'null';
                const guideName = tour.guide_name || 'No guide assigned';
                const totalPaid = parseFloat(tour.total_amount_paid) || 0;
                const expectedAmount = parseFloat(tour.expected_amount) || 0;

                // Show status based on WHY this tour was included in the filtered list
                let statusText = '';
                if (needsGuide) {
                  statusText = ' - NEEDS GUIDE';
                } else if (totalPaid === 0) {
                  statusText = ' - UNPAID (No Payment)';
                } else if (tour.payment_status === 'unpaid') {
                  statusText = ' - UNPAID';
                } else if (tour.payment_status === 'partial') {
                  statusText = ' - PARTIAL PAYMENT';
                } else if (expectedAmount > 0 && totalPaid < expectedAmount) {
                  statusText = ` - PARTIAL (€${totalPaid.toFixed(2)}/€${expectedAmount.toFixed(2)})`;
                } else {
                  // This tour needs attention for some reason
                  statusText = ' - NEEDS ATTENTION';
                }

                return (
                  <option key={tour.id} value={tour.id}>
                    {tour.title} - {tour.time} ({guideName}){statusText}
                  </option>
                );
              })}
            </select>
            {errors.tour_id && <div className="text-red-600 text-sm mt-1">{errors.tour_id}</div>}
          </div>
        )}

        {/* Tour Details Display */}
        {selectedTour && (
          <div className="bg-gray-50 rounded-lg p-4">
            <h4 className="font-medium text-gray-900 mb-2">Tour Details</h4>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <span className="text-gray-500">Customer:</span>
                <span className="ml-2 text-gray-900 font-medium">{selectedTour.customer_name || 'N/A'}</span>
              </div>
              <div>
                <span className="text-gray-500">Date & Time:</span>
                <span className="ml-2 text-gray-900 font-medium">{selectedTour.date} at {selectedTour.time}</span>
              </div>
              <div>
                <span className="text-gray-500">Booking Channel:</span>
                <span className="ml-2 text-gray-900">{selectedTour.booking_channel || 'Unknown'}</span>
              </div>
              <div>
                <span className="text-gray-500">Participants:</span>
                <span className="ml-2 text-gray-900">{selectedTour.participants || 'N/A'}</span>
              </div>
              <div>
                <span className="text-gray-500">Guide:</span>
                <span className="ml-2 text-gray-900 font-medium">
                  {selectedTour.guide_name ||
                    <span className="text-red-600 font-semibold">⚠️ NO GUIDE ASSIGNED</span>
                  }
                </span>
              </div>
              <div>
                <span className="text-gray-500">Payment Status:</span>
                <span className={`ml-2 font-medium capitalize ${
                  (() => {
                    const totalPaid = parseFloat(selectedTour.total_amount_paid || 0);
                    const expectedAmount = parseFloat(selectedTour.expected_amount || 0);

                    // Determine actual payment status based on amounts, not just the status field
                    if (totalPaid === 0) return 'text-red-600'; // No payment = red
                    if (expectedAmount > 0 && totalPaid >= expectedAmount) return 'text-green-600'; // Fully paid = green
                    if (totalPaid > 0 && totalPaid < expectedAmount) return 'text-yellow-600'; // Partial = yellow
                    if (selectedTour.payment_status === 'paid') return 'text-green-600';
                    return 'text-red-600'; // Default to red for unclear cases
                  })()
                }`}>
                  {(() => {
                    const totalPaid = parseFloat(selectedTour.total_amount_paid || 0);
                    const expectedAmount = parseFloat(selectedTour.expected_amount || 0);

                    // Show accurate status based on actual payment amounts
                    if (totalPaid === 0) return 'unpaid';
                    if (expectedAmount > 0 && totalPaid >= expectedAmount) return 'paid';
                    if (totalPaid > 0 && totalPaid < expectedAmount) return 'partial';
                    return selectedTour.payment_status || 'unpaid';
                  })()}
                </span>
              </div>
              <div className="md:col-span-2">
                <span className="text-gray-500">Payment Summary:</span>
                <div className="ml-2 text-gray-900">
                  <span className="font-medium">
                    Total Paid: €{parseFloat(selectedTour.total_amount_paid || 0).toFixed(2)}
                  </span>
                  {selectedTour.expected_amount && parseFloat(selectedTour.expected_amount) > 0 && (
                    <span className="ml-4">
                      Expected: €{parseFloat(selectedTour.expected_amount).toFixed(2)}
                      {parseFloat(selectedTour.total_amount_paid || 0) < parseFloat(selectedTour.expected_amount) && (
                        <span className="text-red-600 font-medium ml-2">
                          (€{(parseFloat(selectedTour.expected_amount) - parseFloat(selectedTour.total_amount_paid || 0)).toFixed(2)} remaining)
                        </span>
                      )}
                    </span>
                  )}
                </div>
              </div>
              {selectedTour.customer_email && (
                <div className="md:col-span-2">
                  <span className="text-gray-500">Customer Email:</span>
                  <span className="ml-2 text-gray-900 text-xs">{selectedTour.customer_email}</span>
                </div>
              )}
              {selectedTour.customer_phone && (
                <div className="md:col-span-2">
                  <span className="text-gray-500">Customer Phone:</span>
                  <span className="ml-2 text-gray-900">{selectedTour.customer_phone}</span>
                </div>
              )}
            </div>

            {/* Action Required Alert */}
            {(!selectedTour.guide_id || selectedTour.guide_id === null || selectedTour.guide_id === '' || selectedTour.guide_id === 'null') && (
              <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                <div className="text-red-800 text-sm font-medium">
                  ⚠️ Action Required: This tour needs a guide assignment
                </div>
                <div className="text-red-600 text-xs mt-1">
                  You can assign a guide when recording the payment below
                </div>
              </div>
            )}
          </div>
        )}

        {/* Step 3: Guide Selection (auto-filled from tour) */}
        {selectedTour && (
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              <FiUser className="inline w-4 h-4 mr-1" />
              Step 3: Guide * {selectedTour?.guide_id ? '(Auto-selected from tour)' : '(Choose guide for this tour)'}
            </label>
            <select
              name="guide_id"
              value={formData.guide_id}
              onChange={handleInputChange}
              className={`w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent ${
                selectedTour?.guide_id ? 'bg-gray-50' : 'bg-white'
              }`}
              required
            >
              <option value="">
                {selectedTour?.guide_id ? 'Select different guide...' : 'Select guide for this tour...'}
              </option>
              {guides.map(guide => (
                <option key={guide.id} value={guide.id}>
                  {guide.name} - {guide.email}
                </option>
              ))}
            </select>
            {errors.guide_id && <div className="text-red-600 text-sm mt-1">{errors.guide_id}</div>}
            <div className="text-sm text-gray-600 mt-1">
              {selectedTour?.guide_id
                ? 'Guide is automatically selected from tour assignment (you can change if needed)'
                : 'This tour needs a guide assignment - please select one to continue'
              }
            </div>
          </div>
        )}

        {/* Step 4: Payment Details */}
        {formData.guide_id && (
          <>
            <div className="border-t border-gray-200 pt-6">
              <h4 className="text-lg font-medium text-gray-900 mb-4">
                <FiDollarSign className="inline w-5 h-5 mr-2" />
                Step 4: Payment Details
              </h4>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Amount (€) *
                  </label>
                  <Input
                    type="number"
                    name="amount"
                    value={formData.amount}
                    onChange={handleInputChange}
                    placeholder="0.00"
                    step="0.01"
                    min="0"
                    required
                    icon={FiDollarSign}
                  />
                  {errors.amount && <div className="text-red-600 text-sm mt-1">{errors.amount}</div>}
                </div>

                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">
                    Payment Method *
                  </label>
                  <select
                    name="payment_method"
                    value={formData.payment_method}
                    onChange={handleInputChange}
                    className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    required
                  >
                    {paymentMethods.map(method => (
                      <option key={method.value} value={method.value}>
                        {method.label}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Current Payment Date/Time Display */}
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-6">
                <h5 className="font-medium text-blue-900 mb-2">
                  <FiClock className="inline w-4 h-4 mr-1" />
                  Payment Date & Time (Auto-updated to current Italian time)
                </h5>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-blue-700 mb-2">
                      Payment Date *
                    </label>
                    <Input
                      type="date"
                      name="payment_date"
                      value={formData.payment_date}
                      onChange={handleInputChange}
                      required
                      icon={FiCalendar}
                      className="bg-white"
                    />
                    {errors.payment_date && <div className="text-red-600 text-sm mt-1">{errors.payment_date}</div>}
                  </div>

                  <div>
                    <label className="block text-sm font-medium text-blue-700 mb-2">
                      Payment Time
                    </label>
                    <Input
                      type="time"
                      name="payment_time"
                      value={formData.payment_time}
                      onChange={handleInputChange}
                      icon={FiClock}
                      className="bg-white"
                    />
                  </div>
                </div>
                <div className="text-sm text-blue-600 mt-2">
                  📍 Time zone: Europe/Rome (Italian time) - Auto-updates when form is used
                </div>
              </div>
            </div>
          </>
        )}

        {/* Optional Fields */}
        {formData.guide_id && (
          <>
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Transaction Reference
              </label>
              <Input
                type="text"
                name="transaction_reference"
                value={formData.transaction_reference}
                onChange={handleInputChange}
                placeholder="Bank reference, receipt number, etc."
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Notes
              </label>
              <textarea
                name="notes"
                value={formData.notes}
                onChange={handleInputChange}
                rows={3}
                className="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                placeholder="Additional notes about the payment..."
              />
            </div>
          </>
        )}

        {/* Action Buttons */}
        {formData.guide_id && (
          <div className="flex justify-end gap-3 pt-4 border-t border-gray-200">
            {onCancel && (
              <Button
                type="button"
                variant="outline"
                onClick={onCancel}
              >
                Cancel
              </Button>
            )}
            <Button
              type="submit"
              icon={FiSave}
              disabled={loading}
            >
              {loading ? 'Recording...' : 'Record Payment'}
            </Button>
          </div>
        )}
      </form>
    </Card>
  );
};

export default PaymentRecordForm;