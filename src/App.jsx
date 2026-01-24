import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import * as Sentry from "@sentry/react";
import { AuthProvider, useAuth } from './contexts/AuthContext';
import ModernLayout from './components/Layout/ModernLayout';
import Dashboard from './components/Dashboard';
import Guides from './pages/Guides';
import Tours from './pages/Tours';
import Tickets from './pages/Tickets';
import Payments from './pages/Payments';
import EditTour from './pages/EditTour';
import BokunIntegration from './pages/BokunIntegration';
import PriorityTickets from './pages/PriorityTickets';
import Login from './pages/Login';
import { PageTitleProvider } from './contexts/PageTitleContext';
import BokunAutoSyncProvider from './components/BokunAutoSyncProvider';
import './index.css';

// Protected Route component
const ProtectedRoute = ({ children }) => {
  const { isAuthenticated } = useAuth();
  
  if (!isAuthenticated) {
    return <Navigate to="/login" />;
  }

  return children;
};

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
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
            <ModernLayout>
              <BokunIntegration />
            </ModernLayout>
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
        <AuthProvider>
          <PageTitleProvider>
            <BokunAutoSyncProvider>
              <AppRoutes />
            </BokunAutoSyncProvider>
          </PageTitleProvider>
        </AuthProvider>
      </Router>
    </Sentry.ErrorBoundary>
  );
}

export default App; 