import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  FiCalendar,
  FiUsers,
  FiTag,
  FiDollarSign,
  FiTrendingUp,
  FiClock,
  FiMapPin,
  FiRefreshCw,
  FiPlus,
  FiEye,
  FiAlertCircle
} from 'react-icons/fi';
import Card, { StatsCard, ActionCard, TourCard } from './UI/Card';
import Button from './UI/Button';
import { getTours, getGuides } from '../services/mysqlDB';

const Dashboard = () => {
  const [stats, setStats] = useState({
    totalTours: 0,
    activeTours: 0,
    totalGuides: 0,
    upcomingTours: 0,
    unpaidTours: 0,
    todayTours: 0
  });
  const [recentTours, setRecentTours] = useState([]);
  const [upcomingTours, setUpcomingTours] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [lastRefresh, setLastRefresh] = useState(new Date());

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async (forceRefresh = false) => {
    setLoading(true);
    try {
      // Load tours data with optional force refresh
      const toursData = await getTours(forceRefresh);
      const guidesData = await getGuides();
      
      if (toursData && guidesData) {
        // Filter out ticket products (not actual tours) - BUT keep Bokun synced bookings
        const ticketProducts = [
          'Uffizi Gallery Priority Entrance Tickets',
          'Skip the Line: Accademia Gallery Priority Entry Ticket with eBook'
        ];
        const filteredTours = toursData.filter(tour => {
          const isTicketProduct = ticketProducts.some(ticket => tour.title && tour.title.includes(ticket));
          // Keep tour if it's NOT a ticket product OR if it's from Bokun (real booking)
          return !isTicketProduct || tour.external_source === 'bokun';
        });

        calculateStats(filteredTours, guidesData);

        // Get recent and upcoming tours
        const now = new Date();
        const today = new Date().toDateString();

        const recent = filteredTours
          .filter(tour => {
            // Show unassigned tours for today, tomorrow, and future dates
            if (tour.cancelled) return false;

            const tourDate = new Date(tour.date);
            const today = new Date();
            today.setHours(0, 0, 0, 0); // Reset to start of day for comparison

            // Only show tours from today onwards
            if (tourDate < today) return false;

            // Only show unassigned tours (no guide assigned)
            const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';

            // Show only unassigned tours
            return !hasGuide;
          })
          .sort((a, b) => new Date(a.date) - new Date(b.date)) // Sort by date ascending (earliest first)
          .slice(0, 10);

        const upcoming = filteredTours
          .filter(tour => {
            if (tour.cancelled) return false;

            const tourDate = new Date(tour.date);
            if (tourDate < now) return false; // Must be future tours

            // Show all upcoming tours regardless of guide assignment or payment status
            return true;
          })
          .sort((a, b) => new Date(a.date) - new Date(b.date))
          .slice(0, 5);
          
        setRecentTours(recent);
        setUpcomingTours(upcoming);
      }
    } catch (error) {
      console.error('Error loading dashboard data:', error);
      setError('Failed to load dashboard data. Please try again.');
    } finally {
      setLoading(false);
      setLastRefresh(new Date());
    }
  };

  const calculateStats = (tours, guides) => {
    const now = new Date();
    const today = new Date().toDateString();
    
    const totalTours = tours.length;
    const activeTours = tours.filter(tour => !tour.cancelled).length;
    const totalGuides = guides.length;
    
    const upcomingTours = tours.filter(tour => {
      const tourDate = new Date(tour.date);
      return !tour.cancelled && tourDate >= now;
    }).length;
    
    // Count tours with unpaid or partial payment status
    const unpaidTours = tours.filter(tour => {
      if (tour.cancelled) return false;
      const tourDate = new Date(tour.date);
      if (tourDate >= now) return false; // Only count past tours

      // Check enhanced payment status
      if (tour.payment_status) {
        return tour.payment_status === 'unpaid' || tour.payment_status === 'partial';
      }
      // Fallback to legacy paid field
      return !tour.paid;
    }).length;
    
    const todayTours = tours.filter(tour => 
      !tour.cancelled && new Date(tour.date).toDateString() === today
    ).length;

    setStats({
      totalTours,
      activeTours,
      totalGuides,
      upcomingTours,
      unpaidTours,
      todayTours
    });
  };

  const quickActions = [
    {
      title: 'Add New Tour',
      description: 'Schedule a new guided tour',
      icon: FiPlus,
      color: 'blue',
      link: '/tours#add'
    },
    {
      title: 'Payment Management',
      description: 'Record and track payments',
      icon: FiDollarSign,
      color: 'green',
      link: '/payments'
    },
    {
      title: 'Manage Guides',
      description: 'View and edit guide information',
      icon: FiUsers,
      color: 'purple',
      link: '/guides'
    },
    {
      title: 'Ticket Inventory',
      description: 'Manage museum tickets',
      icon: FiTag,
      color: 'orange',
      link: '/tickets'
    }
  ];

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Error Alert */}
      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg flex items-start">
          <FiAlertCircle className="text-xl mr-3 mt-0.5 flex-shrink-0" />
          <div className="flex-1">
            <p className="font-medium">Error</p>
            <p className="text-sm mt-1">{error}</p>
          </div>
          <button
            onClick={() => setError(null)}
            className="ml-4 text-red-500 hover:text-red-700"
          >
            ×
          </button>
        </div>
      )}
      {/* Welcome Header */}
      <div className="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-6 text-white">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl md:text-3xl font-bold mb-2">Welcome to Florence with Locals Tour Guide Management System</h1>
            <p className="text-blue-100 text-sm md:text-base">
              Manage your tours, guides, and bookings efficiently
            </p>
            <p className="text-blue-200 text-xs mt-2">
              Last updated: {lastRefresh.toLocaleTimeString()}
            </p>
          </div>
          <div className="flex items-center space-x-4">
            <Button
              variant="secondary"
              size="sm"
              icon={FiRefreshCw}
              onClick={() => loadDashboardData(true)}
              disabled={loading}
              className="bg-white bg-opacity-20 hover:bg-opacity-30 text-white border-white border-opacity-30"
            >
              Refresh
            </Button>
            <div className="hidden md:block">
              <div className="w-16 h-16 bg-white bg-opacity-20 rounded-full flex items-center justify-center">
                <FiMapPin className="text-2xl" />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <StatsCard
          title="Total Tours"
          value={stats.totalTours}
          icon={FiCalendar}
          color="blue"
        />
        <StatsCard
          title="Active Tours"
          value={stats.activeTours}
          icon={FiClock}
          color="green"
        />
        <StatsCard
          title="Total Guides"
          value={stats.totalGuides}
          icon={FiUsers}
          color="purple"
        />
        <StatsCard
          title="Upcoming"
          value={stats.upcomingTours}
          icon={FiTrendingUp}
          color="orange"
        />
        <StatsCard
          title="Today's Tours"
          value={stats.todayTours}
          icon={FiMapPin}
          color="blue"
        />
        <StatsCard
          title="Unpaid"
          value={stats.unpaidTours}
          icon={FiDollarSign}
          color={stats.unpaidTours > 0 ? "red" : "green"}
        />
      </div>

      {/* Quick Actions */}
      <Card>
        <h2 className="text-xl font-semibold text-gray-900 mb-4">Quick Actions</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
          {quickActions.map((action) => (
            <Link key={action.link} to={action.link}>
              <ActionCard
                title={action.title}
                description={action.description}
                icon={action.icon}
                color={action.color}
              />
            </Link>
          ))}
        </div>
      </Card>

      {/* Recent Activity and Upcoming Tours */}
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6">
        {/* Upcoming Tours */}
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-xl font-semibold text-gray-900">Upcoming Tours</h2>
            <Link to="/tours">
              <Button variant="ghost" size="sm" icon={FiEye}>
                View All
              </Button>
            </Link>
          </div>
          
          {upcomingTours.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
              <FiCalendar className="text-3xl mx-auto mb-2 opacity-50" />
              <p>No upcoming tours</p>
            </div>
          ) : (
            <div className="space-y-3">
              {upcomingTours.map((tour) => (
                <div
                  key={tour.id}
                  className="flex items-center justify-between p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition-colors"
                >
                  <div className="flex-1">
                    <h3 className="font-medium text-gray-900">{tour.title}</h3>
                    <div className="flex items-center space-x-4 text-sm text-gray-600 mt-1">
                      <span>{new Date(tour.date).toLocaleDateString('en-GB')}</span>
                      <span>{tour.time}</span>
                      <span>{tour.guide_name || 'Unassigned'}</span>
                    </div>
                  </div>
                  {tour.booking_channel && (
                    <div className="ml-2">
                      <span className="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded-full">
                        {tour.booking_channel}
                      </span>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>

        {/* Unassigned Tours */}
        <Card>
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-xl font-semibold text-gray-900">Unassigned Tours</h2>
            <Link to="/tours">
              <Button variant="ghost" size="sm" icon={FiEye}>
                View All
              </Button>
            </Link>
          </div>
          
          {recentTours.length === 0 ? (
            <div className="text-center py-8 text-gray-500">
              <FiClock className="text-3xl mx-auto mb-2 opacity-50" />
              <p>No unassigned tours</p>
            </div>
          ) : (
            <div className="space-y-3">
              {recentTours.map((tour) => (
                <div
                  key={tour.id}
                  className="flex items-center justify-between p-3 rounded-lg border border-gray-100 hover:bg-gray-50 transition-colors"
                >
                  <div className="flex-1">
                    <h3 className="font-medium text-gray-900">{tour.title}</h3>
                    <div className="flex items-center space-x-4 text-sm text-gray-600 mt-1">
                      <span>{new Date(tour.date).toLocaleDateString('en-GB')}</span>
                      <span>{tour.time}</span>
                      <span>{tour.guide_name || 'Unassigned'}</span>
                    </div>
                  </div>
                  <div className="flex items-center space-x-2">
                    <span className={`inline-block px-2 py-1 text-xs font-medium rounded-full ${
                      tour.payment_status === 'paid' || tour.paid
                        ? 'bg-green-100 text-green-700'
                        : tour.payment_status === 'partial'
                        ? 'bg-yellow-100 text-yellow-700'
                        : 'bg-red-100 text-red-700'
                    }`}>
                      {tour.payment_status === 'paid' || tour.paid
                        ? 'Paid'
                        : tour.payment_status === 'partial'
                        ? 'Partial'
                        : 'Unpaid'}
                    </span>
                    {tour.booking_channel && (
                      <span className="inline-block px-2 py-1 bg-purple-100 text-purple-700 text-xs font-medium rounded-full">
                        {tour.booking_channel}
                      </span>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      {/* Call to Action - if no tours exist */}
      {stats.totalTours === 0 && (
        <Card className="text-center py-12">
          <FiCalendar className="text-4xl text-gray-400 mx-auto mb-4" />
          <h3 className="text-lg font-semibold text-gray-900 mb-2">No tours yet</h3>
          <p className="text-gray-600 mb-6">Get started by adding your first tour or importing from Bokun</p>
          <div className="flex flex-col sm:flex-row gap-4 justify-center">
            <Link to="/tours#add">
              <Button variant="primary" icon={FiPlus}>
                Add New Tour
              </Button>
            </Link>
            <Link to="/tours#bokun">
              <Button variant="outline" icon={FiRefreshCw}>
                Sync from Bokun
              </Button>
            </Link>
          </div>
        </Card>
      )}
    </div>
  );
};

export default Dashboard;