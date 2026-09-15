import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import { useToast } from './Toast/ToastProvider';

// Step 1.1: route guard for admin-only pages (/bokun-integration, /daily-pnl).
// Mount inside ProtectedRoute. Non-admins are sent to / with an "Admin only"
// toast; the server enforces the same rule, this only keeps the UI honest.
const AdminRoute = ({ children }) => {
  const { isAuthenticated, isAdmin } = useAuth();
  const toast = useToast();
  const allowed = isAuthenticated && isAdmin();

  useEffect(() => {
    if (isAuthenticated && !allowed) {
      toast.error('Admin only');
    }
  }, [isAuthenticated, allowed, toast]);

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (!allowed) {
    return <Navigate to="/" replace />;
  }

  return children;
};

export default AdminRoute;
