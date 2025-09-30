import { createContext, useContext, useState, useEffect } from 'react';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [token, setToken] = useState(localStorage.getItem('authToken'));
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
        const response = await fetch(`${API_BASE}/auth.php?action=verify`, {
          headers: {
            'Authorization': `Bearer ${token}`
          }
        });

        if (response.ok) {
          const data = await response.json();
          setIsAuthenticated(true);
          setUserRole(data.role);
          setUserName(data.username);
          localStorage.setItem('userRole', data.role);
          localStorage.setItem('userName', data.username);
        } else {
          localStorage.removeItem('authToken');
          localStorage.removeItem('userRole');
          localStorage.removeItem('userName');
          setToken(null);
          setUserRole(null);
          setUserName(null);
        }
      } catch (error) {
        console.error('Token verification failed:', error);
        localStorage.removeItem('authToken');
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
        localStorage.setItem('authToken', data.token);
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

  const logout = () => {
    localStorage.removeItem('authToken');
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