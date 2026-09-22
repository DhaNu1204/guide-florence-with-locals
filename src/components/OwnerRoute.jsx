import { Navigate } from 'react-router-dom';
import { FiLock } from 'react-icons/fi';
import { useAuth } from '../contexts/AuthContext';

// Step 6.10: the guard for /daily-pnl. Unlike AdminRoute it does NOT redirect - anyone
// who types the URL gets a plain, finished page saying they have no access, rather than
// a bounce, a broken screen or a spinner that never ends. The server enforces the same
// rule (pnl.php answers 403); this only keeps the UI honest.
const OwnerRoute = ({ children }) => {
  const { isAuthenticated, canSeePnl } = useAuth();

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (!canSeePnl || !canSeePnl()) {
    return (
      <div className="flex items-center justify-center py-16 px-4">
        <div className="max-w-md w-full bg-white rounded-2xl shadow-tuscan border border-stone-200 p-8 text-center">
          <div className="mx-auto mb-4 w-12 h-12 rounded-full bg-stone-100 flex items-center justify-center">
            <FiLock className="w-6 h-6 text-stone-500" />
          </div>
          <h1 className="text-lg font-semibold text-stone-900 mb-2">
            You don't have access to this page
          </h1>
          <p className="text-sm text-stone-600">
            The Daily P&amp;L is restricted to the account owner.
          </p>
        </div>
      </div>
    );
  }

  return children;
};

export default OwnerRoute;
