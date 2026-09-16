import React, { useEffect } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import * as Sentry from "@sentry/react";
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ToastProvider, useToast } from './components/Toast/ToastProvider';
import { SESSION_EXPIRED_EVENT, FORBIDDEN_EVENT, resetSessionExpiryGuard } from './services/sessionExpiry';
import ModernLayout from './components/Layout/ModernLayout';
import AdminRoute from './components/AdminRoute';
import Dashboard from './components/Dashboard';
import Guides from './pages/Guides';
import Tours from './pages/Tours';
import Tickets from './pages/Tickets';
import Payments from './pages/Payments';
import GuideReports from './pages/GuideReports';
import EditTour from './pages/EditTour';
import BokunIntegration from './pages/BokunIntegration';
import PriorityTickets from './pages/PriorityTickets';
import DailyPnL from './pages/DailyPnL';
import Login from './pages/Login';
import GuideRespond from './pages/GuideRespond';
import { PageTitleProvider } from './contexts/PageTitleContext';
import BokunAutoSyncProvider from './components/BokunAutoSyncProvider';
import './index.css';

// Bridges non-React 401 handling (axios interceptor + authFetch) to React.
// Listens for the window 'app:session-expired' event, shows a toast, and
// redirects to /login. Mounted inside Router + ToastProvider (so it has both
// useNavigate and useToast) and OUTSIDE AuthProvider (so it stays mounted even
// while AuthProvider is verifying the token).
function SessionExpiryListener() {
  const toast = useToast();
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const handler = () => {
      if (location.pathname !== '/login') {
        toast.error('Your session has expired. Please log in again.');
        navigate('/login', { replace: true });
      }
      // Re-arm the dedupe guard so a future session can notify again.
      setTimeout(() => resetSessionExpiryGuard(), 1500);
    };
    window.addEventListener(SESSION_EXPIRED_EVENT, handler);
    return () => window.removeEventListener(SESSION_EXPIRED_EVENT, handler);
  }, [toast, navigate, location.pathname]);

  return null;
}

// Step 1.1: bridges non-React 403 handling (axios interceptor + authFetch) to a
// single toast. Nothing is cleared and nobody is redirected - the user simply
// is not allowed to do that action.
function ForbiddenListener() {
  const toast = useToast();

  useEffect(() => {
    const handler = () => toast.error("You don't have permission for that");
    window.addEventListener(FORBIDDEN_EVENT, handler);
    return () => window.removeEventListener(FORBIDDEN_EVENT, handler);
  }, [toast]);

  return null;
}

// Protected Route component
const ProtectedRoute = ({ children }) => {
  const { isAuthenticated } = useAuth();
  // Step 1.5: no token in storage (logged out in this or another tab) -> never render
  // a protected page, whatever the in-memory flag says.
  let hasToken = false;
  try {
    hasToken = Boolean(localStorage.getItem('token'));
  } catch (_) {
    hasToken = false;
  }

  if (!isAuthenticated || !hasToken) {
    return <Navigate to="/login" replace />;
  }

  return children;
};

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      {/* Public, no-login guide availability response page (secret token link) */}
      <Route path="/respond/:token" element={<GuideRespond />} />
      <Route
        path="/"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <Dashboard />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/tours"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <Tours />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/guides"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <Guides />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/tickets"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <Tickets />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/payments"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <Payments />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/guide-reports"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <GuideReports />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/tours/:id/edit"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <EditTour />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route
        path="/bokun-integration"
        element={
          <ProtectedRoute>
            <AdminRoute>
              <ModernLayout>
                <BokunIntegration />
              </ModernLayout>
            </AdminRoute>
          </ProtectedRoute>
        }
      />
      <Route
        path="/daily-pnl"
        element={
          <ProtectedRoute>
            <AdminRoute>
              <ModernLayout>
                <DailyPnL />
              </ModernLayout>
            </AdminRoute>
          </ProtectedRoute>
        }
      />
      <Route
        path="/priority-tickets"
        element={
          <ProtectedRoute>
            <ModernLayout>
              <PriorityTickets />
            </ModernLayout>
          </ProtectedRoute>
        }
      />
      <Route path="*" element={<Navigate to="/" />} />
    </Routes>
  );
}

// Fallback component for Sentry error boundary
const ErrorFallback = ({ error, resetError }) => (
  <div className="min-h-screen flex items-center justify-center bg-gray-100">
    <div className="bg-white p-8 rounded-lg shadow-lg max-w-md text-center">
      <h2 className="text-2xl font-bold text-red-600 mb-4">Something went wrong</h2>
      <p className="text-gray-600 mb-4">An unexpected error occurred. Our team has been notified.</p>
      <button
        onClick={resetError}
        className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition"
      >
        Try Again
      </button>
    </div>
  </div>
);

function App() {
  return (
    <Sentry.ErrorBoundary fallback={ErrorFallback} showDialog>
      <Router>
        <ToastProvider>
          <SessionExpiryListener />
          <ForbiddenListener />
          <AuthProvider>
            <PageTitleProvider>
              <BokunAutoSyncProvider>
                <AppRoutes />
              </BokunAutoSyncProvider>
            </PageTitleProvider>
          </AuthProvider>
        </ToastProvider>
      </Router>
    </Sentry.ErrorBoundary>
  );
}

export default App; 