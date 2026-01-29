# React Patterns Skill

## Overview
This skill provides React component patterns specific to the Florence With Locals tour management system. Follow these patterns to maintain consistency with the existing codebase.

## Tech Stack Reference
- **React**: 18.3.1
- **Vite**: 5.4.14
- **React Router**: 6.28.1
- **TailwindCSS**: 3.4.17
- **date-fns**: 4.1.0
- **Sentry**: @sentry/react 8.57.0

---

## 1. Component Structure

### Page Component Template
```jsx
// src/pages/ExamplePage.jsx
import { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import mysqlDB from '../services/mysqlDB';
import ModernLayout from '../components/Layout/ModernLayout';
import Card from '../components/Card';

export default function ExamplePage() {
  const { user, isAuthenticated } = useAuth();
  const [data, setData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (isAuthenticated) {
      fetchData();
    }
  }, [isAuthenticated]);

  const fetchData = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await mysqlDB.getSomeData();
      setData(response);
    } catch (err) {
      setError(err.message || 'Failed to load data');
      console.error('Fetch error:', err);
    } finally {
      setLoading(false);
    }
  };

  if (!isAuthenticated) {
    return null; // AuthContext handles redirect
  }

  return (
    <ModernLayout>
      <div className="space-y-6">
        {/* Page Header */}
        <div className="flex justify-between items-center">
          <h1 className="text-2xl font-bold text-stone-800">Page Title</h1>
          <button className="bg-terracotta-600 text-white px-4 py-2 rounded-lg hover:bg-terracotta-700 transition-colors">
            Action Button
          </button>
        </div>

        {/* Error State */}
        {error && (
          <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
            {error}
          </div>
        )}

        {/* Loading State */}
        {loading ? (
          <div className="flex justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-terracotta-600"></div>
          </div>
        ) : (
          /* Content */
          <Card>
            {/* Card content */}
          </Card>
        )}
      </div>
    </ModernLayout>
  );
}
```

### Functional Component Template
```jsx
// src/components/ExampleComponent.jsx
import { useState } from 'react';
import PropTypes from 'prop-types';

export default function ExampleComponent({
  title,
  items = [],
  onSelect,
  className = ''
}) {
  const [selectedId, setSelectedId] = useState(null);

  const handleSelect = (item) => {
    setSelectedId(item.id);
    onSelect?.(item);
  };

  return (
    <div className={`bg-white rounded-xl shadow-sm border border-stone-200 ${className}`}>
      <div className="p-4 border-b border-stone-100">
        <h3 className="font-semibold text-stone-800">{title}</h3>
      </div>
      <div className="p-4">
        {items.map((item) => (
          <div
            key={item.id}
            onClick={() => handleSelect(item)}
            className={`p-3 rounded-lg cursor-pointer transition-colors ${
              selectedId === item.id
                ? 'bg-terracotta-50 border-terracotta-200'
                : 'hover:bg-stone-50'
            }`}
          >
            {item.name}
          </div>
        ))}
      </div>
    </div>
  );
}

ExampleComponent.propTypes = {
  title: PropTypes.string.isRequired,
  items: PropTypes.arrayOf(PropTypes.shape({
    id: PropTypes.oneOfType([PropTypes.string, PropTypes.number]).isRequired,
    name: PropTypes.string.isRequired,
  })),
  onSelect: PropTypes.func,
  className: PropTypes.string,
};
```

---

## 2. AuthContext Usage

### Import and Basic Usage
```jsx
import { useAuth } from '../contexts/AuthContext';

function MyComponent() {
  const {
    user,           // Current user object { id, username, role }
    isAuthenticated,// Boolean authentication status
    login,          // async (username, password) => Promise
    logout,         // () => void - clears session
    loading         // Boolean - true during auth check
  } = useAuth();

  // Check role-based access
  const isAdmin = user?.role === 'admin';

  // Protected action
  const handleAdminAction = () => {
    if (!isAdmin) {
      alert('Admin access required');
      return;
    }
    // Perform admin action
  };
}
```

### Protected Route Pattern
```jsx
// In App.jsx or routing configuration
import { Navigate } from 'react-router-dom';
import { useAuth } from './contexts/AuthContext';

function ProtectedRoute({ children, requiredRole }) {
  const { isAuthenticated, user, loading } = useAuth();

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-terracotta-600"></div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (requiredRole && user?.role !== requiredRole) {
    return <Navigate to="/unauthorized" replace />;
  }

  return children;
}

// Usage in routes
<Route
  path="/admin"
  element={
    <ProtectedRoute requiredRole="admin">
      <AdminPage />
    </ProtectedRoute>
  }
/>
```

### Auth State in Forms
```jsx
function LoginForm() {
  const { login } = useAuth();
  const [credentials, setCredentials] = useState({ username: '', password: '' });
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setSubmitting(true);

    try {
      await login(credentials.username, credentials.password);
      // Redirect handled by AuthContext
    } catch (err) {
      setError(err.message || 'Login failed');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      {error && (
        <div className="bg-red-50 text-red-700 p-3 rounded-lg mb-4">
          {error}
        </div>
      )}
      {/* Form fields */}
      <button
        type="submit"
        disabled={submitting}
        className="w-full bg-terracotta-600 text-white py-2 rounded-lg disabled:opacity-50"
      >
        {submitting ? 'Signing in...' : 'Sign In'}
      </button>
    </form>
  );
}
```

---

## 3. mysqlDB.js API Service Patterns

### Standard API Call Pattern
```jsx
import mysqlDB from '../services/mysqlDB';

// GET request with error handling
const fetchTours = async () => {
  try {
    const tours = await mysqlDB.getTours();
    return tours;
  } catch (error) {
    console.error('Failed to fetch tours:', error);
    throw error;
  }
};

// GET with parameters
const fetchToursByDate = async (startDate, endDate) => {
  try {
    const tours = await mysqlDB.getTours({ startDate, endDate });
    return tours;
  } catch (error) {
    console.error('Failed to fetch tours by date:', error);
    throw error;
  }
};

// POST request
const createGuide = async (guideData) => {
  try {
    const result = await mysqlDB.createGuide(guideData);
    return result;
  } catch (error) {
    console.error('Failed to create guide:', error);
    throw error;
  }
};

// PUT request
const updatePayment = async (paymentId, updates) => {
  try {
    const result = await mysqlDB.updatePayment(paymentId, updates);
    return result;
  } catch (error) {
    console.error('Failed to update payment:', error);
    throw error;
  }
};

// DELETE request
const deleteTicket = async (ticketId) => {
  try {
    await mysqlDB.deleteTicket(ticketId);
    return true;
  } catch (error) {
    console.error('Failed to delete ticket:', error);
    throw error;
  }
};
```

### Custom Hook for API Data
```jsx
// src/hooks/useApiData.js
import { useState, useEffect, useCallback } from 'react';

export function useApiData(fetchFn, deps = []) {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const refetch = useCallback(async () => {
    try {
      setLoading(true);
      setError(null);
      const result = await fetchFn();
      setData(result);
    } catch (err) {
      setError(err.message || 'An error occurred');
    } finally {
      setLoading(false);
    }
  }, [fetchFn]);

  useEffect(() => {
    refetch();
  }, deps);

  return { data, loading, error, refetch };
}

// Usage
import { useApiData } from '../hooks/useApiData';
import mysqlDB from '../services/mysqlDB';

function ToursPage() {
  const { data: tours, loading, error, refetch } = useApiData(
    () => mysqlDB.getTours(),
    []
  );

  // Component renders...
}
```

### API Service Extension Pattern
When adding new API endpoints to `mysqlDB.js`:

```javascript
// src/services/mysqlDB.js

// Add new method following existing patterns
const mysqlDB = {
  // ... existing methods ...

  // GET with optional filters
  async getNewResource(filters = {}) {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        params.append(key, value);
      }
    });

    const queryString = params.toString();
    const url = `${API_BASE_URL}/new-resource.php${queryString ? `?${queryString}` : ''}`;

    const response = await fetch(url, {
      headers: this.getAuthHeaders(),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to fetch resource');
    }

    return response.json();
  },

  // POST with body
  async createNewResource(data) {
    const response = await fetch(`${API_BASE_URL}/new-resource.php`, {
      method: 'POST',
      headers: {
        ...this.getAuthHeaders(),
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to create resource');
    }

    return response.json();
  },
};
```

---

## 4. ModernLayout.jsx Page Layout

### Standard Page Structure
```jsx
import ModernLayout from '../components/Layout/ModernLayout';

export default function StandardPage() {
  return (
    <ModernLayout>
      {/* Content wrapper with consistent spacing */}
      <div className="space-y-6">

        {/* Page Header */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h1 className="text-2xl font-bold text-stone-800">Page Title</h1>
            <p className="text-stone-500 mt-1">Page description or subtitle</p>
          </div>
          <div className="flex gap-2">
            <button className="btn-secondary">Secondary Action</button>
            <button className="btn-primary">Primary Action</button>
          </div>
        </div>

        {/* Filters/Controls Section */}
        <Card>
          <div className="flex flex-wrap gap-4">
            {/* Filter controls */}
          </div>
        </Card>

        {/* Main Content */}
        <Card>
          {/* Table, list, or main content */}
        </Card>

        {/* Optional Secondary Section */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card>Left content</Card>
          <Card>Right content</Card>
        </div>
      </div>
    </ModernLayout>
  );
}
```

### Dashboard Layout Pattern
```jsx
import ModernLayout from '../components/Layout/ModernLayout';
import Card from '../components/Card';

export default function DashboardPage() {
  return (
    <ModernLayout>
      <div className="space-y-6">
        {/* Stats Row */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard title="Total Tours" value={125} icon={CalendarIcon} />
          <StatCard title="Active Guides" value={12} icon={UsersIcon} />
          <StatCard title="Revenue" value="€15,420" icon={CurrencyIcon} />
          <StatCard title="Pending" value={8} icon={ClockIcon} />
        </div>

        {/* Two-column layout */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          {/* Main content - 2/3 width */}
          <div className="lg:col-span-2 space-y-6">
            <Card title="Recent Bookings">
              {/* Table content */}
            </Card>
          </div>

          {/* Sidebar - 1/3 width */}
          <div className="space-y-6">
            <Card title="Quick Actions">
              {/* Action buttons */}
            </Card>
            <Card title="Notifications">
              {/* Notification list */}
            </Card>
          </div>
        </div>
      </div>
    </ModernLayout>
  );
}

function StatCard({ title, value, icon: Icon }) {
  return (
    <Card className="flex items-center gap-4">
      <div className="p-3 bg-terracotta-100 rounded-lg">
        <Icon className="w-6 h-6 text-terracotta-600" />
      </div>
      <div>
        <p className="text-sm text-stone-500">{title}</p>
        <p className="text-2xl font-bold text-stone-800">{value}</p>
      </div>
    </Card>
  );
}
```

---

## 5. Modal Patterns (BookingDetailsModal Style)

### Standard Modal Component
```jsx
// src/components/ExampleModal.jsx
import { Fragment } from 'react';
import { Dialog, Transition } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/24/outline';

export default function ExampleModal({ isOpen, onClose, data }) {
  return (
    <Transition appear show={isOpen} as={Fragment}>
      <Dialog as="div" className="relative z-50" onClose={onClose}>
        {/* Backdrop */}
        <Transition.Child
          as={Fragment}
          enter="ease-out duration-300"
          enterFrom="opacity-0"
          enterTo="opacity-100"
          leave="ease-in duration-200"
          leaveFrom="opacity-100"
          leaveTo="opacity-0"
        >
          <div className="fixed inset-0 bg-black/25 backdrop-blur-sm" />
        </Transition.Child>

        {/* Modal Container */}
        <div className="fixed inset-0 overflow-y-auto">
          <div className="flex min-h-full items-center justify-center p-4">
            <Transition.Child
              as={Fragment}
              enter="ease-out duration-300"
              enterFrom="opacity-0 scale-95"
              enterTo="opacity-100 scale-100"
              leave="ease-in duration-200"
              leaveFrom="opacity-100 scale-100"
              leaveTo="opacity-0 scale-95"
            >
              <Dialog.Panel className="w-full max-w-lg transform overflow-hidden rounded-2xl bg-white shadow-xl transition-all">
                {/* Header */}
                <div className="flex items-center justify-between p-4 border-b border-stone-200">
                  <Dialog.Title className="text-lg font-semibold text-stone-800">
                    Modal Title
                  </Dialog.Title>
                  <button
                    onClick={onClose}
                    className="p-2 hover:bg-stone-100 rounded-lg transition-colors"
                  >
                    <XMarkIcon className="w-5 h-5 text-stone-500" />
                  </button>
                </div>

                {/* Content */}
                <div className="p-4 space-y-4">
                  {/* Modal content */}
                  <p className="text-stone-600">Modal content goes here.</p>
                </div>

                {/* Footer */}
                <div className="flex justify-end gap-3 p-4 border-t border-stone-200 bg-stone-50">
                  <button
                    onClick={onClose}
                    className="px-4 py-2 text-stone-700 hover:bg-stone-200 rounded-lg transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={() => {/* handle action */}}
                    className="px-4 py-2 bg-terracotta-600 text-white rounded-lg hover:bg-terracotta-700 transition-colors"
                  >
                    Confirm
                  </button>
                </div>
              </Dialog.Panel>
            </Transition.Child>
          </div>
        </div>
      </Dialog>
    </Transition>
  );
}
```

### Modal with Form
```jsx
import { useState } from 'react';

export default function FormModal({ isOpen, onClose, onSubmit, initialData }) {
  const [formData, setFormData] = useState(initialData || {
    name: '',
    email: '',
    role: 'guide',
  });
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
    // Clear field error on change
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: null }));
    }
  };

  const validate = () => {
    const newErrors = {};
    if (!formData.name.trim()) newErrors.name = 'Name is required';
    if (!formData.email.trim()) newErrors.email = 'Email is required';
    return newErrors;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    const validationErrors = validate();
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit(formData);
      onClose();
    } catch (err) {
      setErrors({ submit: err.message });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog /* ... */>
      <form onSubmit={handleSubmit}>
        <div className="p-4 space-y-4">
          {errors.submit && (
            <div className="bg-red-50 text-red-700 p-3 rounded-lg">
              {errors.submit}
            </div>
          )}

          <div>
            <label className="block text-sm font-medium text-stone-700 mb-1">
              Name
            </label>
            <input
              type="text"
              name="name"
              value={formData.name}
              onChange={handleChange}
              className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-terracotta-500 focus:border-transparent ${
                errors.name ? 'border-red-500' : 'border-stone-300'
              }`}
            />
            {errors.name && (
              <p className="text-sm text-red-600 mt-1">{errors.name}</p>
            )}
          </div>

          {/* More fields... */}
        </div>

        <div className="flex justify-end gap-3 p-4 border-t border-stone-200 bg-stone-50">
          <button type="button" onClick={onClose} disabled={submitting}>
            Cancel
          </button>
          <button type="submit" disabled={submitting}>
            {submitting ? 'Saving...' : 'Save'}
          </button>
        </div>
      </form>
    </Dialog>
  );
}
```

### Confirmation Modal
```jsx
export default function ConfirmModal({
  isOpen,
  onClose,
  onConfirm,
  title = 'Confirm Action',
  message = 'Are you sure you want to proceed?',
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  variant = 'danger' // 'danger' | 'warning' | 'info'
}) {
  const [loading, setLoading] = useState(false);

  const handleConfirm = async () => {
    setLoading(true);
    try {
      await onConfirm();
      onClose();
    } finally {
      setLoading(false);
    }
  };

  const variantStyles = {
    danger: 'bg-red-600 hover:bg-red-700',
    warning: 'bg-amber-600 hover:bg-amber-700',
    info: 'bg-terracotta-600 hover:bg-terracotta-700',
  };

  return (
    <Dialog /* ... */>
      <div className="p-6 text-center">
        <h3 className="text-lg font-semibold text-stone-800 mb-2">{title}</h3>
        <p className="text-stone-600 mb-6">{message}</p>
        <div className="flex justify-center gap-3">
          <button
            onClick={onClose}
            disabled={loading}
            className="px-4 py-2 text-stone-700 bg-stone-100 hover:bg-stone-200 rounded-lg"
          >
            {cancelText}
          </button>
          <button
            onClick={handleConfirm}
            disabled={loading}
            className={`px-4 py-2 text-white rounded-lg ${variantStyles[variant]}`}
          >
            {loading ? 'Processing...' : confirmText}
          </button>
        </div>
      </div>
    </Dialog>
  );
}
```

---

## 6. Form Handling Patterns

### Controlled Form Component
```jsx
import { useState } from 'react';

export default function BookingForm({ onSubmit, guides, tours }) {
  const [formData, setFormData] = useState({
    tour_id: '',
    guide_id: '',
    customer_name: '',
    customer_email: '',
    participants: 1,
    booking_date: '',
    notes: '',
  });
  const [errors, setErrors] = useState({});
  const [submitting, setSubmitting] = useState(false);

  const handleChange = (e) => {
    const { name, value, type } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: type === 'number' ? parseInt(value, 10) || '' : value,
    }));
  };

  const handleSelectChange = (name, value) => {
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const validate = () => {
    const errors = {};

    if (!formData.tour_id) errors.tour_id = 'Please select a tour';
    if (!formData.customer_name.trim()) errors.customer_name = 'Customer name is required';
    if (!formData.customer_email.trim()) {
      errors.customer_email = 'Email is required';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.customer_email)) {
      errors.customer_email = 'Invalid email format';
    }
    if (!formData.booking_date) errors.booking_date = 'Booking date is required';
    if (formData.participants < 1) errors.participants = 'At least 1 participant required';

    return errors;
  };

  const handleSubmit = async (e) => {
    e.preventDefault();

    const validationErrors = validate();
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      return;
    }

    setSubmitting(true);
    setErrors({});

    try {
      await onSubmit(formData);
    } catch (err) {
      setErrors({ form: err.message || 'Failed to submit form' });
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {/* Form error */}
      {errors.form && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
          {errors.form}
        </div>
      )}

      {/* Select field */}
      <div>
        <label className="block text-sm font-medium text-stone-700 mb-1">
          Tour <span className="text-red-500">*</span>
        </label>
        <select
          name="tour_id"
          value={formData.tour_id}
          onChange={handleChange}
          className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-terracotta-500 ${
            errors.tour_id ? 'border-red-500' : 'border-stone-300'
          }`}
        >
          <option value="">Select a tour</option>
          {tours.map(tour => (
            <option key={tour.id} value={tour.id}>{tour.name}</option>
          ))}
        </select>
        {errors.tour_id && (
          <p className="text-sm text-red-600 mt-1">{errors.tour_id}</p>
        )}
      </div>

      {/* Text input */}
      <div>
        <label className="block text-sm font-medium text-stone-700 mb-1">
          Customer Name <span className="text-red-500">*</span>
        </label>
        <input
          type="text"
          name="customer_name"
          value={formData.customer_name}
          onChange={handleChange}
          className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-terracotta-500 ${
            errors.customer_name ? 'border-red-500' : 'border-stone-300'
          }`}
        />
        {errors.customer_name && (
          <p className="text-sm text-red-600 mt-1">{errors.customer_name}</p>
        )}
      </div>

      {/* Number input */}
      <div>
        <label className="block text-sm font-medium text-stone-700 mb-1">
          Participants
        </label>
        <input
          type="number"
          name="participants"
          value={formData.participants}
          onChange={handleChange}
          min="1"
          max="50"
          className="w-full px-3 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-terracotta-500"
        />
      </div>

      {/* Date input */}
      <div>
        <label className="block text-sm font-medium text-stone-700 mb-1">
          Booking Date <span className="text-red-500">*</span>
        </label>
        <input
          type="date"
          name="booking_date"
          value={formData.booking_date}
          onChange={handleChange}
          className={`w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-terracotta-500 ${
            errors.booking_date ? 'border-red-500' : 'border-stone-300'
          }`}
        />
        {errors.booking_date && (
          <p className="text-sm text-red-600 mt-1">{errors.booking_date}</p>
        )}
      </div>

      {/* Textarea */}
      <div>
        <label className="block text-sm font-medium text-stone-700 mb-1">
          Notes
        </label>
        <textarea
          name="notes"
          value={formData.notes}
          onChange={handleChange}
          rows={3}
          className="w-full px-3 py-2 border border-stone-300 rounded-lg focus:ring-2 focus:ring-terracotta-500 resize-none"
        />
      </div>

      {/* Submit button */}
      <div className="flex justify-end gap-3 pt-4">
        <button
          type="submit"
          disabled={submitting}
          className="px-6 py-2 bg-terracotta-600 text-white rounded-lg hover:bg-terracotta-700 disabled:opacity-50 transition-colors"
        >
          {submitting ? 'Saving...' : 'Save Booking'}
        </button>
      </div>
    </form>
  );
}
```

---

## 7. Date Handling with date-fns

### Common Date Operations
```jsx
import {
  format,
  parseISO,
  isToday,
  isTomorrow,
  isPast,
  isFuture,
  addDays,
  subDays,
  startOfWeek,
  endOfWeek,
  startOfMonth,
  endOfMonth,
  differenceInDays,
  isWithinInterval
} from 'date-fns';

// Format dates for display
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = parseISO(dateString);
  return format(date, 'MMM d, yyyy'); // "Jan 15, 2025"
};

const formatDateTime = (dateString) => {
  if (!dateString) return 'N/A';
  const date = parseISO(dateString);
  return format(date, 'MMM d, yyyy HH:mm'); // "Jan 15, 2025 14:30"
};

const formatTime = (dateString) => {
  if (!dateString) return 'N/A';
  const date = parseISO(dateString);
  return format(date, 'HH:mm'); // "14:30"
};

// Relative date labels
const getRelativeLabel = (dateString) => {
  const date = parseISO(dateString);
  if (isToday(date)) return 'Today';
  if (isTomorrow(date)) return 'Tomorrow';
  return format(date, 'EEEE, MMM d'); // "Monday, Jan 15"
};

// Date status checks
const getDateStatus = (dateString) => {
  const date = parseISO(dateString);
  if (isPast(date)) return 'past';
  if (isToday(date)) return 'today';
  if (isFuture(date)) return 'upcoming';
};

// Date range for filters
const getDateRangeForPeriod = (period) => {
  const today = new Date();

  switch (period) {
    case 'today':
      return { start: today, end: today };
    case 'week':
      return { start: startOfWeek(today), end: endOfWeek(today) };
    case 'month':
      return { start: startOfMonth(today), end: endOfMonth(today) };
    case 'next7days':
      return { start: today, end: addDays(today, 7) };
    default:
      return null;
  }
};

// Check if date is within range
const isDateInRange = (dateString, startDate, endDate) => {
  const date = parseISO(dateString);
  return isWithinInterval(date, { start: startDate, end: endDate });
};

// Days until event
const getDaysUntil = (dateString) => {
  const date = parseISO(dateString);
  return differenceInDays(date, new Date());
};
```

### Date Input with Validation
```jsx
import { parseISO, isValid, isBefore, startOfDay } from 'date-fns';

function DateInput({ value, onChange, minDate, maxDate, error }) {
  const handleChange = (e) => {
    const newValue = e.target.value;
    onChange(newValue);
  };

  const validateDate = (dateString) => {
    if (!dateString) return 'Date is required';

    const date = parseISO(dateString);
    if (!isValid(date)) return 'Invalid date';

    const today = startOfDay(new Date());
    if (minDate && isBefore(date, minDate)) {
      return `Date must be after ${format(minDate, 'MMM d, yyyy')}`;
    }

    return null;
  };

  return (
    <div>
      <input
        type="date"
        value={value}
        onChange={handleChange}
        min={minDate ? format(minDate, 'yyyy-MM-dd') : undefined}
        max={maxDate ? format(maxDate, 'yyyy-MM-dd') : undefined}
        className={`w-full px-3 py-2 border rounded-lg ${
          error ? 'border-red-500' : 'border-stone-300'
        }`}
      />
      {error && <p className="text-sm text-red-600 mt-1">{error}</p>}
    </div>
  );
}
```

---

## 8. Sentry Error Tracking Integration

### Setup in main.jsx
```jsx
// src/main.jsx
import * as Sentry from '@sentry/react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';

// Initialize Sentry (only in production)
if (import.meta.env.PROD) {
  Sentry.init({
    dsn: import.meta.env.VITE_SENTRY_DSN,
    environment: import.meta.env.MODE,
    integrations: [
      Sentry.browserTracingIntegration(),
      Sentry.replayIntegration(),
    ],
    tracesSampleRate: 0.1, // 10% of transactions
    replaysSessionSampleRate: 0.1,
    replaysOnErrorSampleRate: 1.0, // 100% of errors
  });
}

const container = document.getElementById('root');
const root = createRoot(container);

root.render(
  <BrowserRouter>
    <Sentry.ErrorBoundary fallback={<ErrorFallback />}>
      <App />
    </Sentry.ErrorBoundary>
  </BrowserRouter>
);

function ErrorFallback() {
  return (
    <div className="min-h-screen flex items-center justify-center bg-stone-50">
      <div className="text-center p-8">
        <h1 className="text-2xl font-bold text-stone-800 mb-4">
          Something went wrong
        </h1>
        <p className="text-stone-600 mb-6">
          We've been notified and are working on a fix.
        </p>
        <button
          onClick={() => window.location.reload()}
          className="bg-terracotta-600 text-white px-6 py-2 rounded-lg"
        >
          Refresh Page
        </button>
      </div>
    </div>
  );
}
```

### Manual Error Reporting
```jsx
import * as Sentry from '@sentry/react';

// Capture exception with context
const handleApiError = (error, context) => {
  Sentry.captureException(error, {
    tags: {
      component: context.component,
      action: context.action,
    },
    extra: {
      userId: context.userId,
      requestData: context.requestData,
    },
  });
};

// Usage in component
const fetchData = async () => {
  try {
    const data = await mysqlDB.getTours();
    setTours(data);
  } catch (error) {
    handleApiError(error, {
      component: 'ToursPage',
      action: 'fetchTours',
      userId: user?.id,
    });
    setError('Failed to load tours');
  }
};

// Set user context on login
const handleLogin = async (credentials) => {
  const user = await login(credentials);

  Sentry.setUser({
    id: user.id,
    username: user.username,
    email: user.email,
  });
};

// Clear user on logout
const handleLogout = () => {
  logout();
  Sentry.setUser(null);
};

// Add breadcrumb for user actions
const handleBookingAction = (action, bookingId) => {
  Sentry.addBreadcrumb({
    category: 'booking',
    message: `User ${action} booking ${bookingId}`,
    level: 'info',
  });

  // Perform action...
};
```

### Error Boundary Component
```jsx
// src/components/ErrorBoundary.jsx
import { Component } from 'react';
import * as Sentry from '@sentry/react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    Sentry.captureException(error, {
      extra: {
        componentStack: errorInfo.componentStack,
      },
    });
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback || (
        <div className="p-6 bg-red-50 rounded-lg">
          <h2 className="text-lg font-semibold text-red-800">
            Something went wrong
          </h2>
          <p className="text-red-600 mt-2">
            {this.state.error?.message || 'An unexpected error occurred'}
          </p>
          <button
            onClick={() => this.setState({ hasError: false, error: null })}
            className="mt-4 px-4 py-2 bg-red-600 text-white rounded-lg"
          >
            Try Again
          </button>
        </div>
      );
    }

    return this.props.children;
  }
}

// Usage
<ErrorBoundary fallback={<CustomErrorUI />}>
  <ComponentThatMightError />
</ErrorBoundary>
```

---

## Quick Reference: Component Checklist

When creating new React components, ensure:

- [ ] Import statements are organized (React, external libs, local imports)
- [ ] Component uses function declaration with `export default`
- [ ] Props have default values where appropriate
- [ ] Loading states are handled with spinner or skeleton
- [ ] Error states display user-friendly messages
- [ ] Empty states have helpful messages
- [ ] Forms validate before submission
- [ ] Async operations have try/catch with error handling
- [ ] Sentry captures significant errors
- [ ] TailwindCSS classes follow project color scheme
- [ ] Components are responsive (mobile-first)
- [ ] Modals use consistent pattern with proper transitions
- [ ] Dates are formatted consistently using date-fns
