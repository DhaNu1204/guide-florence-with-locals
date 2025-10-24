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
    unassignedTours: 0,
    unpaidTours: 0
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
      const toursResponse = await getTours(forceRefresh);
      const guidesData = await getGuides();

      // Extract tours array from paginated response
      const toursData = toursResponse && toursResponse.data ? toursResponse.data : toursResponse;

      if (toursData && guidesData) {
        // Ticket products to exclude (not actual tours that need guides)
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

            // Exclude ticket products (they don't need guides)
            const isTicketProduct = ticketProducts.some(ticket => tour.title && tour.title.includes(ticket));
            if (isTicketProduct) return false;

            // Only show unassigned tours (no guide assigned)
            const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';

            // Show only unassigned tours
            return !hasGuide;
          })
          .sort((a, b) => {
            // Sort by date AND time (chronological order)
            const dateA = new Date(a.date + ' ' + a.time);
            const dateB = new Date(b.date + ' ' + b.time);
            return dateA - dateB;
          })
          .slice(0, 10);

        const upcoming = filteredTours
          .filter(tour => {
            if (tour.cancelled) return false;

            const tourDate = new Date(tour.date);
            if (tourDate < now) return false; // Must be future tours

            // Exclude ticket products (they don't need guides)
            const isTicketProduct = ticketProducts.some(ticket => tour.title && tour.title.includes(ticket));
            if (isTicketProduct) return false;

            // Show all upcoming tours regardless of guide assignment or payment status
            return true;
          })
          .sort((a, b) => {
            // Sort by date AND time (chronological order)
            const dateA = new Date(a.date + ' ' + a.time);
            const dateB = new Date(b.date + ' ' + b.time);
            return dateA - dateB;
          })
          .slice(0, 15);
          
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
    now.setHours(0, 0, 0, 0); // Reset to start of day

    // Count unassigned tours for future dates only
    const unassignedTours = tours.filter(tour => {
      if (tour.cancelled) return false;

      const tourDate = new Date(tour.date);
      tourDate.setHours(0, 0, 0, 0);

      // Only future tours
      if (tourDate < now) return false;

      // Check if guide is not assigned
      const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';
      return !hasGuide;
    }).length;

    // Count tours with unpaid or partial payment status (past tours only)
    const unpaidTours = tours.filter(tour => {
      if (tour.cancelled) return false;

      const tourDate = new Date(tour.date);
      tourDate.setHours(0, 0, 0, 0);

      // Only count past tours
      if (tourDate >= now) return false;

      // Check enhanced payment status
      if (tour.payment_status) {
        return tour.payment_status === 'unpaid' || tour.payment_status === 'partial';
      }
      // Fallback to legacy paid field
      return !tour.paid;
    }).length;

    setStats({
      unassignedTours,
      unpaidTours
    });
  };


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
      <div className="grid grid-cols-2 gap-4">
        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Unassigned Tours</p>
              <p className="text-3xl font-bold text-gray-900">{stats.unassignedTours}</p>
              <p className="text-xs text-gray-500 mt-1">Future tours without guide</p>
            </div>
            <div className="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
              <FiAlertCircle className="text-orange-600 text-xl" />
            </div>
          </div>
        </div>

        <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-gray-600 mb-1">Unpaid Tours</p>
              <p className="text-3xl font-bold text-gray-900">{stats.unpaidTours}</p>
              <p className="text-xs text-gray-500 mt-1">Past tours pending payment</p>
            </div>
            <div className={`w-12 h-12 rounded-lg flex items-center justify-center ${
              stats.unpaidTours > 0 ? 'bg-red-100' : 'bg-green-100'
            }`}>
              <FiDollarSign className={`text-xl ${
                stats.unpaidTours > 0 ? 'text-red-600' : 'text-green-600'
              }`} />
            </div>
          </div>
        </div>
      </div>

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
                      {tour.language && (
                        <span className="inline-flex items-center px-2 py-0.5 bg-blue-50 text-blue-700 text-xs font-medium rounded">
                          🗣️ {tour.language}
                        </span>
                      )}
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
                      {tour.language && (
                        <span className="inline-flex items-center px-2 py-0.5 bg-blue-50 text-blue-700 text-xs font-medium rounded">
                          🗣️ {tour.language}
                        </span>
                      )}
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

    </div>
  );
};

export default Dashboard;