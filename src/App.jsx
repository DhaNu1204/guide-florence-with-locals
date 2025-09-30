import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexts/AuthContext';
import ModernLayout from './components/Layout/ModernLayout';
import Dashboard from './components/Dashboard';
import Guides from './pages/Guides';
import Tours from './pages/Tours';
import Tickets from './pages/Tickets';
import Payments from './pages/Payments';
import EditTour from './pages/EditTour';
import BokunIntegration from './pages/BokunIntegration';
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
      <Route path="*" element={<Navigate to="/" />} />
    </Routes>
  );
}

function App() {
  return (
    <Router>
      <AuthProvider>
        <PageTitleProvider>
          <BokunAutoSyncProvider>
            <AppRoutes />
          </BokunAutoSyncProvider>
        </PageTitleProvider>
      </AuthProvider>
    </Router>
  );
}

export default App; 