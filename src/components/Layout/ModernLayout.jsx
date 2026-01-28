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

  // Tuscan-themed menu items
  const menuItems = [
    {
      title: 'Dashboard',
      icon: FiHome,
      path: '/',
      color: 'text-terracotta-600',
      bgColor: 'bg-terracotta-50',
      borderColor: 'border-terracotta-500'
    },
    {
      title: 'Tours',
      icon: FiCalendar,
      path: '/tours',
      color: 'text-olive-600',
      bgColor: 'bg-olive-50',
      borderColor: 'border-olive-500'
    },
    {
      title: 'Guides',
      icon: FiUsers,
      path: '/guides',
      color: 'text-olive-600',
      bgColor: 'bg-olive-50',
      borderColor: 'border-olive-500'
    },
    {
      title: 'Priority Tickets',
      icon: FiTag,
      path: '/priority-tickets',
      color: 'text-gold-600',
      bgColor: 'bg-gold-50',
      borderColor: 'border-gold-500'
    },
    {
      title: 'Tickets',
      icon: BsBoxSeam,
      path: '/tickets',
      color: 'text-gold-600',
      bgColor: 'bg-gold-50',
      borderColor: 'border-gold-500'
    },
    {
      title: 'Payments',
      icon: FiDollarSign,
      path: '/payments',
      color: 'text-olive-600',
      bgColor: 'bg-olive-50',
      borderColor: 'border-olive-500'
    },
    {
      title: 'Bokun Integration',
      icon: FiRefreshCw,
      path: '/bokun-integration',
      color: 'text-renaissance-600',
      bgColor: 'bg-renaissance-50',
      borderColor: 'border-renaissance-500'
    }
  ];

  const isActive = (path) => {
    if (path === '/') return location.pathname === '/';
    return location.pathname.startsWith(path.split('#')[0]);
  };

  return (
    <div className="min-h-screen bg-tuscan-gradient">
      {/* Desktop Sidebar */}
      <aside className={`
        fixed top-0 left-0 z-40 h-full bg-white shadow-tuscan-xl transition-all duration-300 ease-in-out
        border-r border-stone-200/50
        ${isMobile ? 'hidden' : isSidebarOpen ? 'w-64' : 'w-20'}
      `}>
        {/* Sidebar Header */}
        <div className="flex items-center justify-between p-4 border-b border-stone-200">
          <div className={`flex items-center ${!isSidebarOpen && !isMobile ? 'justify-center w-full' : 'space-x-3'}`}>
            <div className="w-10 h-10 bg-gradient-to-br from-terracotta-500 to-terracotta-700 rounded-tuscan-lg flex items-center justify-center shadow-tuscan flex-shrink-0">
              <FiMapPin className="text-white text-xl" />
            </div>
            {(isSidebarOpen || isMobile) && (
              <div>
                <h1 className="text-lg font-bold text-stone-800">Florence with Locals</h1>
                <p className="text-xs text-stone-500">Management System</p>
              </div>
            )}
          </div>
          {!isMobile && isSidebarOpen && (
            <button
              onClick={() => setIsSidebarOpen(!isSidebarOpen)}
              className="p-3 min-h-[44px] min-w-[44px] rounded-tuscan-lg hover:bg-stone-100 transition-colors touch-manipulation flex items-center justify-center"
            >
              <FiChevronLeft className="text-stone-600 text-lg transition-transform" />
            </button>
          )}
        </div>

        {/* Expand button when collapsed */}
        {!isMobile && !isSidebarOpen && (
          <div className="p-2 border-b border-stone-200">
            <button
              onClick={() => setIsSidebarOpen(true)}
              className="w-full p-3 min-h-[44px] rounded-tuscan-lg hover:bg-stone-100 transition-colors touch-manipulation flex items-center justify-center"
            >
              <FiChevronLeft className="text-stone-600 text-lg rotate-180" />
            </button>
          </div>
        )}

        {/* Navigation Menu */}
        <nav className="flex-1 p-3 space-y-1 overflow-y-auto">
          {menuItems.map((item) => {
            const Icon = item.icon;
            const active = isActive(item.path);
            return (
              <Link
                key={item.path}
                to={item.path}
                title={!isSidebarOpen ? item.title : undefined}
                className={`
                  flex items-center px-3 py-3 rounded-tuscan-lg transition-all duration-200
                  ${!isSidebarOpen && !isMobile ? 'justify-center' : 'space-x-3'}
                  ${active
                    ? `${item.bgColor} ${item.color} border-l-4 ${item.borderColor} shadow-tuscan-sm`
                    : 'hover:bg-stone-100 text-stone-600 hover:text-stone-800'
                  }
                `}
              >
                <Icon className={`text-xl flex-shrink-0 ${active ? item.color : ''}`} />
                {(isSidebarOpen || isMobile) && (
                  <span className="font-medium">{item.title}</span>
                )}
              </Link>
            );
          })}
        </nav>

        {/* Sidebar Footer - User Profile & Logout */}
        <div className={`border-t border-stone-200 ${!isSidebarOpen && !isMobile ? '' : ''}`}>
          {/* User Profile Section */}
          <div className={`p-4 border-b border-stone-100 ${!isSidebarOpen && !isMobile ? 'px-3' : ''}`}>
            <div className={`flex items-center ${!isSidebarOpen && !isMobile ? 'justify-center' : 'space-x-3'}`}>
              <div className="w-10 h-10 bg-gradient-to-br from-terracotta-400 to-terracotta-600 rounded-full flex items-center justify-center text-white font-semibold shadow-tuscan flex-shrink-0">
                {(userInfo?.username || 'U').charAt(0).toUpperCase()}
              </div>
              {(isSidebarOpen || isMobile) && (
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-semibold text-stone-800 truncate">{userInfo?.username || 'User'}</p>
                  <p className="text-xs text-stone-500 capitalize">{userInfo?.role || 'viewer'}</p>
                </div>
              )}
            </div>
          </div>

          {/* Logout Button */}
          <div className={`p-3 ${!isSidebarOpen && !isMobile ? '' : ''}`}>
            <button
              onClick={handleLogout}
              title={!isSidebarOpen ? 'Logout' : undefined}
              className={`
                flex items-center w-full px-3 py-3 rounded-tuscan-lg
                text-stone-600 hover:bg-terracotta-50 hover:text-terracotta-600 transition-all duration-200
                ${!isSidebarOpen && !isMobile ? 'justify-center' : 'space-x-3'}
              `}
            >
              <FiLogOut className="text-xl flex-shrink-0" />
              {(isSidebarOpen || isMobile) && (
                <span className="font-medium">Logout</span>
              )}
            </button>
          </div>
        </div>
      </aside>

      {/* Mobile Header */}
      {isMobile && (
        <header className="fixed top-0 left-0 right-0 z-50 bg-white shadow-tuscan border-b border-stone-200">
          <div className="flex items-center justify-between px-3 py-2">
            <div className="flex items-center space-x-2">
              <button
                onClick={() => setIsMobileMenuOpen(!isMobileMenuOpen)}
                className="p-3 min-h-[44px] min-w-[44px] rounded-tuscan-lg hover:bg-stone-100 active:bg-stone-200 transition-colors touch-manipulation flex items-center justify-center"
              >
                {isMobileMenuOpen ? <FiX className="text-2xl text-stone-700" /> : <FiMenu className="text-2xl text-stone-700" />}
              </button>
              <div className="flex items-center space-x-2">
                <div className="w-8 h-8 bg-gradient-to-br from-terracotta-500 to-terracotta-700 rounded-tuscan flex items-center justify-center shadow-tuscan">
                  <FiMapPin className="text-white text-sm" />
                </div>
                <h1 className="text-lg font-bold text-stone-800">Florence with Locals</h1>
              </div>
            </div>
            <div className="w-10 h-10 min-h-[44px] min-w-[44px] bg-gradient-to-br from-terracotta-400 to-terracotta-600 rounded-full flex items-center justify-center text-white font-semibold text-sm touch-manipulation shadow-tuscan">
              {(userInfo?.username || 'U').charAt(0).toUpperCase()}
            </div>
          </div>
        </header>
      )}

      {/* Mobile Menu Overlay */}
      {isMobile && isMobileMenuOpen && (
        <>
          <div
            className="fixed inset-0 bg-black/50 backdrop-blur-sm z-40 transition-opacity duration-300"
            onClick={() => setIsMobileMenuOpen(false)}
          />
          <div className="fixed top-0 left-0 w-80 max-w-[85vw] h-full bg-white z-50 shadow-tuscan-xl transform transition-transform duration-300 ease-out animate-slide-in-left">
            {/* Mobile Menu Header */}
            <div className="flex items-center justify-between p-4 border-b border-stone-200">
              <div className="flex items-center space-x-3">
                <div className="w-10 h-10 bg-gradient-to-br from-terracotta-500 to-terracotta-700 rounded-tuscan-lg flex items-center justify-center shadow-tuscan">
                  <FiMapPin className="text-white text-xl" />
                </div>
                <div>
                  <h1 className="text-lg font-bold text-stone-800">Florence with Locals</h1>
                  <p className="text-xs text-stone-500">Management System</p>
                </div>
              </div>
              <button
                onClick={() => setIsMobileMenuOpen(false)}
                className="p-3 min-h-[44px] min-w-[44px] rounded-tuscan-lg hover:bg-stone-100 active:bg-stone-200 transition-colors touch-manipulation flex items-center justify-center"
              >
                <FiX className="text-2xl text-stone-600" />
              </button>
            </div>

            {/* Mobile Navigation */}
            <nav className="flex-1 p-3 space-y-1 overflow-y-auto">
              {menuItems.map((item) => {
                const Icon = item.icon;
                const active = isActive(item.path);
                return (
                  <Link
                    key={item.path}
                    to={item.path}
                    onClick={() => setIsMobileMenuOpen(false)}
                    className={`
                      flex items-center space-x-3 px-4 py-3 min-h-[48px] rounded-tuscan-lg transition-all duration-200 touch-manipulation active:scale-[0.98]
                      ${active
                        ? `${item.bgColor} ${item.color} border-l-4 ${item.borderColor} shadow-tuscan-sm`
                        : 'hover:bg-stone-100 active:bg-stone-200 text-stone-600 hover:text-stone-800'
                      }
                    `}
                  >
                    <Icon className={`text-xl flex-shrink-0 ${active ? item.color : ''}`} />
                    <span className="font-medium">{item.title}</span>
                  </Link>
                );
              })}
            </nav>

            {/* Mobile Menu Footer - User Profile & Logout */}
            <div className="border-t border-stone-200">
              {/* Mobile User Profile */}
              <div className="p-4 border-b border-stone-100">
                <div className="flex items-center space-x-3">
                  <div className="w-12 h-12 bg-gradient-to-br from-terracotta-400 to-terracotta-600 rounded-full flex items-center justify-center text-white font-semibold shadow-tuscan">
                    {(userInfo?.username || 'U').charAt(0).toUpperCase()}
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-semibold text-stone-800">{userInfo?.username || 'User'}</p>
                    <p className="text-xs text-stone-500 capitalize">{userInfo?.role || 'viewer'}</p>
                  </div>
                </div>
              </div>

              {/* Mobile Logout Button */}
              <div className="p-3">
                <button
                  onClick={handleLogout}
                  className="flex items-center space-x-3 w-full px-4 py-3 min-h-[48px] rounded-tuscan-lg text-stone-600 hover:bg-terracotta-50 hover:text-terracotta-600 active:bg-terracotta-100 transition-all duration-200 touch-manipulation"
                >
                  <FiLogOut className="text-xl flex-shrink-0" />
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
