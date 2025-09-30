import React, { useState, useEffect } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { 
  FiHome, 
  FiCalendar, 
  FiUsers, 
  FiTag, 
  FiSettings, 
  FiLogOut,
  FiMenu,
  FiX,
  FiChevronLeft,
  FiRefreshCw,
  FiDollarSign,
  FiMapPin
} from 'react-icons/fi';
import { BsBoxSeam } from 'react-icons/bs';

const ModernLayout = ({ children }) => {
  const location = useLocation();
  const navigate = useNavigate();
  const [isSidebarOpen, setIsSidebarOpen] = useState(true);
  const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(false);
  const [userInfo, setUserInfo] = useState({ username: '', role: '' });

  useEffect(() => {
    // Check if mobile on mount and resize
    const checkMobile = () => {
      setIsMobile(window.innerWidth < 768);
      if (window.innerWidth < 768) {
        setIsSidebarOpen(false);
      } else {
        setIsSidebarOpen(true);
        setIsMobileMenuOpen(false);
      }
    };

    checkMobile();
    window.addEventListener('resize', checkMobile);

    // Get user info from localStorage
    const username = localStorage.getItem('username') || 'User';
    const role = localStorage.getItem('userRole') || 'viewer';
    setUserInfo({ username, role });

    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  const handleLogout = () => {
    localStorage.removeItem('token');
    localStorage.removeItem('userRole');
    localStorage.removeItem('username');
    navigate('/login');
  };

  const menuItems = [
    {
      title: 'Dashboard',
      icon: FiHome,
      path: '/',
      color: 'text-blue-600',
      bgColor: 'bg-blue-50',
      borderColor: 'border-blue-500'
    },
    {
      title: 'Tours',
      icon: FiCalendar,
      path: '/tours',
      color: 'text-purple-600',
      bgColor: 'bg-purple-50',
      borderColor: 'border-purple-500'
    },
    {
      title: 'Guides',
      icon: FiUsers,
      path: '/guides',
      color: 'text-green-600',
      bgColor: 'bg-green-50',
      borderColor: 'border-green-500'
    },
    {
      title: 'Tickets',
      icon: FiTag,
      path: '/tickets',
      color: 'text-orange-600',
      bgColor: 'bg-orange-50',
      borderColor: 'border-orange-500'
    },
    {
      title: 'Payments',
      icon: FiDollarSign,
      path: '/payments',
      color: 'text-emerald-600',
      bgColor: 'bg-emerald-50',
      borderColor: 'border-emerald-500'
    },
    {
      title: 'Bokun Integration',
      icon: FiRefreshCw,
      path: '/bokun-integration',
      color: 'text-cyan-600',
      bgColor: 'bg-cyan-50',
      borderColor: 'border-cyan-500'
    }
  ];

  const isActive = (path) => {
    if (path === '/') return location.pathname === '/';
    return location.pathname.startsWith(path.split('#')[0]);
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
      {/* Desktop Sidebar */}
      <aside className={`
        fixed top-0 left-0 z-40 h-full bg-white shadow-xl transition-all duration-300 ease-in-out
        ${isMobile ? 'hidden' : isSidebarOpen ? 'w-64' : 'w-20'}
      `}>
        {/* Sidebar Header */}
        <div className="flex items-center justify-between p-4 border-b border-gray-200">
          <div className={`flex items-center space-x-3 ${!isSidebarOpen && !isMobile ? 'justify-center' : ''}`}>
            <div className="w-10 h-10 bg-gradient-to-br from-purple-600 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
              <FiMapPin className="text-white text-xl" />
            </div>
            {(isSidebarOpen || isMobile) && (
              <div>
                <h1 className="text-lg font-bold text-gray-800">Florence with Locals</h1>
                <p className="text-xs text-gray-500">Management System</p>
              </div>
            )}
          </div>
          {!isMobile && (
            <button
              onClick={() => setIsSidebarOpen(!isSidebarOpen)}
              className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
            >
              <FiChevronLeft className={`text-gray-600 transition-transform ${!isSidebarOpen ? 'rotate-180' : ''}`} />
            </button>
          )}
        </div>


        {/* Navigation Menu */}
        <nav className="flex-1 p-4 space-y-2 overflow-y-auto">
          {menuItems.map((item) => {
            const Icon = item.icon;
            const active = isActive(item.path);
            return (
              <Link
                key={item.path}
                to={item.path}
                className={`
                  flex items-center space-x-3 px-3 py-3 rounded-xl transition-all duration-200
                  ${!isSidebarOpen && !isMobile ? 'justify-center' : ''}
                  ${active
                    ? `${item.bgColor} ${item.color} border-l-4 ${item.borderColor}`
                    : 'hover:bg-gray-100 text-gray-600 hover:text-gray-900'
                  }
                `}
              >
                <Icon className={`text-xl ${active ? item.color : ''}`} />
                {(isSidebarOpen || isMobile) && (
                  <span className="font-medium">{item.title}</span>
                )}
              </Link>
            );
          })}
        </nav>

        {/* Sidebar Footer - User Profile & Logout */}
        <div className={`border-t border-gray-200 ${!isSidebarOpen && !isMobile ? 'px-2' : ''}`}>
          {/* User Profile Section */}
          <div className={`p-4 border-b border-gray-200 ${!isSidebarOpen && !isMobile ? 'px-2' : ''}`}>
            <div className={`flex items-center ${!isSidebarOpen && !isMobile ? 'justify-center' : 'space-x-3'}`}>
              <div className="w-10 h-10 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold shadow-md">
                {userInfo.username.charAt(0).toUpperCase()}
              </div>
              {(isSidebarOpen || isMobile) && (
                <div className="flex-1">
                  <p className="text-sm font-semibold text-gray-800">{userInfo.username}</p>
                  <p className="text-xs text-gray-500 capitalize">{userInfo.role}</p>
                </div>
              )}
            </div>
          </div>

          {/* Logout Button */}
          <div className={`p-4 ${!isSidebarOpen && !isMobile ? 'px-2' : ''}`}>
            <button
              onClick={handleLogout}
              className={`
                flex items-center space-x-3 w-full px-3 py-3 rounded-xl
                text-gray-600 hover:bg-red-50 hover:text-red-600 transition-all duration-200
                ${!isSidebarOpen && !isMobile ? 'justify-center' : ''}
              `}
            >
              <FiLogOut className="text-xl" />
              {(isSidebarOpen || isMobile) && (
                <span className="font-medium">Logout</span>
              )}
            </button>
          </div>
        </div>
      </aside>

      {/* Mobile Header */}
      {isMobile && (
        <header className="fixed top-0 left-0 right-0 z-50 bg-white shadow-md">
          <div className="flex items-center justify-between p-4">
            <div className="flex items-center space-x-3">
              <button
                onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
              >
                {isMobileMenuOpen ? <FiX className="text-xl" /> : <FiMenu className="text-xl" />}
              </button>
              <div className="flex items-center space-x-2">
                <div className="w-8 h-8 bg-gradient-to-br from-purple-600 to-blue-600 rounded-lg flex items-center justify-center shadow-md">
                  <FiMapPin className="text-white text-sm" />
                </div>
                <h1 className="text-lg font-bold text-gray-800">Florence with Locals</h1>
              </div>
            </div>
            <div className="w-8 h-8 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
              {userInfo.username.charAt(0).toUpperCase()}
            </div>
          </div>
        </header>
      )}

      {/* Mobile Menu Overlay */}
      {isMobile && isMobileMenuOpen && (
        <>
          <div 
            className="fixed inset-0 bg-black bg-opacity-50 z-40"
            onClick={() => setIsMobileMenuOpen(false)}
          />
          <div className="fixed top-0 left-0 w-80 max-w-[85%] h-full bg-white z-50 shadow-2xl transform transition-transform duration-300">
            {/* Mobile Menu Header */}
            <div className="flex items-center justify-between p-4 border-b border-gray-200">
              <div className="flex items-center space-x-3">
                <div className="w-10 h-10 bg-gradient-to-br from-purple-600 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                  <FiMapPin className="text-white text-xl" />
                </div>
                <div>
                  <h1 className="text-lg font-bold text-gray-800">Florence with Locals</h1>
                  <p className="text-xs text-gray-500">Management System</p>
                </div>
              </div>
              <button
                onClick={() => setIsMobileMenuOpen(false)}
                className="p-2 rounded-lg hover:bg-gray-100 transition-colors"
              >
                <FiX className="text-xl text-gray-600" />
              </button>
            </div>


            {/* Mobile Navigation */}
            <nav className="flex-1 p-4 space-y-2 overflow-y-auto">
              {menuItems.map((item) => {
                const Icon = item.icon;
                const active = isActive(item.path);
                return (
                  <Link
                    key={item.path}
                    to={item.path}
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`
                      flex items-center space-x-3 px-3 py-3 rounded-xl transition-all duration-200
                      ${active
                        ? `${item.bgColor} ${item.color} border-l-4 ${item.borderColor}`
                        : 'hover:bg-gray-100 text-gray-600 hover:text-gray-900'
                      }
                    `}
                  >
                    <Icon className={`text-xl ${active ? item.color : ''}`} />
                    <span className="font-medium">{item.title}</span>
                  </Link>
                );
              })}
            </nav>

            {/* Mobile Menu Footer - User Profile & Logout */}
            <div className="border-t border-gray-200">
              {/* Mobile User Profile */}
              <div className="p-4 border-b border-gray-200">
                <div className="flex items-center space-x-3">
                  <div className="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold shadow-md">
                    {userInfo.username.charAt(0).toUpperCase()}
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-semibold text-gray-800">{userInfo.username}</p>
                    <p className="text-xs text-gray-500 capitalize">{userInfo.role}</p>
                  </div>
                </div>
              </div>

              {/* Mobile Logout Button */}
              <div className="p-4">
                <button
                  onClick={handleLogout}
                  className="flex items-center space-x-3 w-full px-3 py-3 rounded-xl text-gray-600 hover:bg-red-50 hover:text-red-600 transition-all duration-200"
                >
                  <FiLogOut className="text-xl" />
                  <span className="font-medium">Logout</span>
                </button>
              </div>
            </div>
          </div>
        </>
      )}

      {/* Main Content Area */}
      <main className={`
        transition-all duration-300 ease-in-out
        ${isMobile ? 'ml-0 mt-16' : isSidebarOpen ? 'ml-64' : 'ml-20'}
        min-h-screen
      `}>
        <div className="p-4 md:p-6 lg:p-8">
          {/* Page Content */}
          <div className="max-w-7xl mx-auto">
            {children}
          </div>
        </div>
      </main>
    </div>
  );
};

export default ModernLayout;