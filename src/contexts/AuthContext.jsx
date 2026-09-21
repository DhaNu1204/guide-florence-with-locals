import { createContext, useContext, useState, useEffect } from 'react';
import { markVerifyStart, markVerifyEnd } from '../utils/perfBeacon'; // step 4.7: measurement only

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [token, setToken] = useState(localStorage.getItem('token'));
  const [userRole, setUserRole] = useState(localStorage.getItem('userRole'));
  const [userName, setUserName] = useState(localStorage.getItem('userName'));
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const verifyToken = async () => {
      if (!token) {
        setLoading(false);
        return;
      }

      try {
        const API_BASE = import.meta.env.VITE_API_URL || '/api';
        markVerifyStart(); // step 4.7
        const response = await fetch(`${API_BASE}/auth.php?action=verify`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });
        markVerifyEnd(response.ok); // step 4.7

        if (response.ok) {
          const data = await response.json();
          setIsAuthenticated(true);
          setUserRole(data.role);
          setUserName(data.username);
          localStorage.setItem('userRole', data.role);
          localStorage.setItem('userName', data.username);
        } else {
          localStorage.removeItem('token');
          localStorage.removeItem('userRole');
          localStorage.removeItem('userName');
          setToken(null);
          setUserRole(null);
          setUserName(null);
        }
      } catch (error) {
        markVerifyEnd(false); // step 4.7
        console.error('Token verification failed:', error);
        localStorage.removeItem('token');
        localStorage.removeItem('userRole');
        localStorage.removeItem('userName');
        setToken(null);
        setUserRole(null);
        setUserName(null);
      }
      setLoading(false);
    };

    verifyToken();
  }, [token]);

  const login = async (username, password) => {
    try {
      const API_BASE = import.meta.env.VITE_API_URL || '/api';
      const response = await fetch(`${API_BASE}/auth.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ username, password }),
      });

      const data = await response.json();

      if (data.success) {
        localStorage.setItem('token', data.token);
        localStorage.setItem('userRole', data.role);
        localStorage.setItem('userName', data.username);
        setToken(data.token);
        setUserRole(data.role);
        setUserName(data.username);
        setIsAuthenticated(true);
        return { success: true };
      } else {
        return { success: false, message: data.message };
      }
    } catch (error) {
      console.error('Login failed:', error);
      return { success: false, message: 'Login failed. Please try again.' };
    }
  };

  // Step 1.5: real logout. The server deletes the session row first (so the token
  // is dead everywhere), then local state/storage are cleared - even if the call
  // failed (offline, already expired). Callers navigate to /login afterwards.
  const logout = async () => {
    const current = token || localStorage.getItem('token');
    if (current) {
      try {
        const API_BASE = import.meta.env.VITE_API_URL || '/api';
        await fetch(`${API_BASE}/auth.php?action=logout`, {
          method: 'POST',
          headers: { Authorization: `Bearer ${current}` },
        });
      } catch (error) {
        console.error('Logout call failed (clearing locally anyway):', error);
      }
    }
    localStorage.removeItem('token');
    localStorage.removeItem('userRole');
    localStorage.removeItem('userName');
    setToken(null);
    setUserRole(null);
    setUserName(null);
    setIsAuthenticated(false);
  };

  const isAdmin = () => userRole === 'admin';

  if (loading) {
    return <div>Loading...</div>;
  }

  return (
    <AuthContext.Provider value={{ isAuthenticated, login, logout, isAdmin, userRole, userName }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}; 