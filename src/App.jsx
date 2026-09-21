import React, { useEffect, Suspense } from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import * as Sentry from "@sentry/react";
import { AuthProvider, useAuth } from './contexts/AuthContext';
import { ToastProvider, useToast } from './components/Toast/ToastProvider';
import { SESSION_EXPIRED_EVENT, FORBIDDEN_EVENT, resetSessionExpiryGuard } from './services/sessionExpiry';
import ModernLayout from './components/Layout/ModernLayout';
import AdminRoute from './components/AdminRoute';
import Login from './pages/Login';
import { PageTitleProvider } from './contexts/PageTitleContext';
import BokunAutoSyncProvider from './components/BokunAutoSyncProvider';
import lazyWithRetry from './utils/lazyWithRetry';
import './index.css';

// Step 4.1: every page below is fetched as its own chunk the first time its route
// is opened, so the first screen no longer downloads the whole app. Login,
// ModernLayout, AuthContext and the route guards stay eager (needed immediately).
// Step 4.1b: through lazyWithRetry, so one dropped chunk request on a phone retries
// (300 ms, 900 ms) and, if it still fails, reloads the tab once instead of showing the
// red error screen. See src/utils/lazyWithRetry.js.
const Dashboard = lazyWithRetry(() => import('./components/Dashboard'));
const Guides = lazyWithRetry(() => import('./pages/Guides'));
const Tours = lazyWithRetry(() => import('./pages/Tours'));
const Tickets = lazyWithRetry(() => import('./pages/Tickets'));
const Payments = lazyWithRetry(() => import('./pages/Payments'));
const GuideReports = lazyWithRetry(() => import('./pages/GuideReports'));
const EditTour = lazyWithRetry(() => import('./pages/EditTour'));
const BokunIntegration = lazyWithRetry(() => import('./pages/BokunIntegration'));
const PriorityTickets = lazyWithRetry(() => import('./pages/PriorityTickets'));
const DailyPnL = lazyWithRetry(() => import('./pages/DailyPnL'));
const Radios = lazyWithRetry(() => import('./pages/Radios'));
const ClientPerf = lazyWithRetry(() => import('./pages/ClientPerf')); // step 4.7
const GuideRespond = lazyWithRetry(() => import('./pages/GuideRespond'));

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

// Shown while a page chunk is being fetched (same spinner the pages use).
const PageSpinner = () => (
  <div className="flex items-center justify-center h-64" role="status" aria-label="Loading page">
    <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-terracotta-500"></div>
  </div>
);

function AppRoutes() {
  return (
    <Suspense fallback={<PageSpinner />}>
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
        path="/load-measurements"
        element={
          <ProtectedRoute>
            <AdminRoute>
              <ModernLayout>
                <ClientPerf />
              </ModernLayout>
            </AdminRoute>
          </ProtectedRoute>
        }
      />
      <Route
        path="/radios"
        element={
          <ProtectedRoute>
            <AdminRoute>
              <ModernLayout>
                <Radios />
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
    </Suspense>
  );
}

// Fallback component for Sentry error boundary.
// Step 4.1b: "Try Again" used to call resetError(), which only re-renders - for the most common
// error here (a page chunk that failed to download) React replays the same rejected promise, so
// the button could never help. It reloads the page instead, which refetches index.html (no-store
// since step 2.3) and therefore the current chunks.
const ErrorFallback = () => (
  <div className="min-h-screen flex items-center justify-center bg-gray-100">
    <div className="bg-white p-8 rounded-lg shadow-lg max-w-md text-center">
      <h2 className="text-2xl font-bold text-red-600 mb-4">Something went wrong</h2>
      <p className="text-gray-600 mb-2">An unexpected error occurred. Our team has been notified.</p>
      <p className="text-gray-500 text-sm mb-4">
        If you are on mobile data, check your connection and try again.
      </p>
      <button
        onClick={() => window.location.reload()}
        className="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition"
      >
        Try Again
      </button>
    </div>
  </div>
);

function App() {
  return (
    <Sentry.ErrorBoundary fallback={ErrorFallback}>
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