import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  FiCalendar,
  FiClock,
  FiDollarSign,
  FiEye,
  FiAlertCircle,
  FiRefreshCw,
  FiUsers,
  FiMapPin
} from 'react-icons/fi';
import Card from './UI/Card';
import Button from './UI/Button';
import { getTours, getGuides } from '../services/mysqlDB';
import { isTicketProduct, filterToursOnly } from '../utils/tourFilters';
import { useBokunSync } from '../hooks/useBokunAutoSync';

// Authenticated fetch wrapper - adds Bearer token from localStorage
const authFetch = (url, options = {}) => {
  const token = localStorage.getItem('token');
  return fetch(url, {
    ...options,
    headers: {
      ...options.headers,
      ...(token ? { 'Authorization': `Bearer ${token}` } : {})
    }
  });
};

// Helper function to format time ago
const formatTimeAgo = (date) => {
  if (!date) return 'Never';

  const now = new Date();
  const syncDate = new Date(date);
  const diffMs = now - syncDate;
  const diffMinutes = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));

  if (diffMinutes < 1) return 'Just now';
  if (diffMinutes < 60) return `${diffMinutes} min ago`;
  if (diffHours < 24) return `${diffHours} hr ago`;
  return syncDate.toLocaleDateString();
};

const Dashboard = () => {
  const { lastSync, isSyncing, syncNow, error: syncError } = useBokunSync();

  const [stats, setStats] = useState({
    unassignedTours: 0,
    unpaidTours: 0,
    totalGuidedTours: 0,
    paidTours: 0
  });
  const [recentTours, setRecentTours] = useState([]);
  const [upcomingTours, setUpcomingTours] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    loadDashboardData();
  }, []);

  const loadDashboardData = async (forceRefresh = false) => {
    setLoading(true);
    try {
      // Fetch upcoming tours for display lists
      const upcomingResponse = await getTours(forceRefresh, 1, 500, { upcoming: true });
      // Fetch all tours for accurate payment stats (past tours need to be counted)
      const allToursResponse = await getTours(forceRefresh, 1, 500, {});
      const guidesData = await getGuides();

      // Fetch pending payments count from API (authoritative source)
      const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';
      let pendingPaymentsCount = 0;
      try {
        const pendingResponse = await authFetch(`${API_BASE_URL}/guide-payments.php?action=pending_tours`);
        if (pendingResponse.ok) {
          const pendingData = await pendingResponse.json();
          if (pendingData.success) {
            pendingPaymentsCount = pendingData.count || (pendingData.data ? pendingData.data.length : 0);
          }
        }
      } catch (e) {
        console.warn('Failed to fetch pending payments count:', e);
      }

      const upcomingData = upcomingResponse && upcomingResponse.data ? upcomingResponse.data : upcomingResponse;
      const allToursData = allToursResponse && allToursResponse.data ? allToursResponse.data : allToursResponse;

      if (upcomingData && allToursData && guidesData) {
        // Filter out ticket products - only count real guided tours
        const allGuidedTours = filterToursOnly(allToursData);
        const upcomingGuidedTours = filterToursOnly(upcomingData);

        calculateStats(allGuidedTours, upcomingGuidedTours, guidesData, pendingPaymentsCount);

        const now = new Date();

        // Unassigned upcoming tours (need guide assignment)
        const recent = upcomingGuidedTours
          .filter(tour => {
            if (tour.cancelled) return false;
            const tourDate = new Date(tour.date);
            const todayDate = new Date();
            todayDate.setHours(0, 0, 0, 0);
            if (tourDate < todayDate) return false;
            const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';
            return !hasGuide;
          })
          .sort((a, b) => {
            const dateA = new Date(a.date + ' ' + a.time);
            const dateB = new Date(b.date + ' ' + b.time);
            return dateA - dateB;
          })
          .slice(0, 10);

        // Upcoming tours list
        const upcoming = upcomingGuidedTours
          .filter(tour => {
            if (tour.cancelled) return false;
            const tourDate = new Date(tour.date);
            if (tourDate < now) return false;
            return true;
          })
          .sort((a, b) => {
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
    }
  };

  const calculateStats = (allTours, upcomingTours, guides, pendingPaymentsCount = 0) => {
    const now = new Date();
    now.setHours(0, 0, 0, 0);

    // Count unassigned UPCOMING tours (future tours without guide)
    const unassignedTours = upcomingTours.filter(tour => {
      if (tour.cancelled) return false;
      const tourDate = new Date(tour.date);
      tourDate.setHours(0, 0, 0, 0);
      if (tourDate < now) return false;
      const hasGuide = tour.guide_id && tour.guide_name && tour.guide_id !== 'null' && tour.guide_id !== '';
      return !hasGuide;
    }).length;

    // Count paid PAST tours (for display purposes)
    const pastTours = allTours.filter(tour => {
      if (tour.cancelled) return false;
      const tourDate = new Date(tour.date);
      tourDate.setHours(0, 0, 0, 0);
      return tourDate < now; // Past tours only
    });

    const paidTours = pastTours.filter(tour => {
      if (tour.payment_status) {
        return tour.payment_status === 'paid';
      }
      return tour.paid === 1 || tour.paid === true || tour.paid === '1';
    }).length;

    // Use API count for unpaid tours (authoritative source - checks payments table)
    setStats({
      unassignedTours,
      unpaidTours: pendingPaymentsCount,
      paidTours,
      totalGuidedTours: allTours.filter(t => !t.cancelled).length
    });
  };


  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-terracotta-500"></div>
      </div>
    );
  }

  return (
    <div className="space-y-4 md:space-y-6">
      {/* Header with Tuscan styling */}
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <h1 className="text-xl md:text-3xl font-bold text-stone-900">Dashboard</h1>
          <p className="text-stone-500 text-xs md:text-sm mt-1">Welcome to Florence with Locals</p>
        </div>
        <div className="flex items-center space-x-2 flex-shrink-0">
          <span className="hidden sm:inline text-sm text-stone-500">
            Last sync: {formatTimeAgo(lastSync)}
          </span>
          <button
            onClick={syncNow}
            disabled={isSyncing}
            className={`p-3 min-h-[44px] min-w-[44px] rounded-tuscan-lg hover:bg-stone-100 active:bg-stone-200 transition-all touch-manipulation flex items-center justify-center ${
              isSyncing ? 'text-terracotta-500' : 'text-stone-500 hover:text-terracotta-600'
            }`}
            title={isSyncing ? 'Syncing...' : 'Sync now'}
          >
            <FiRefreshCw className={`w-5 h-5 ${isSyncing ? 'animate-spin' : ''}`} />
          </button>
        </div>
      </div>

      {/* Sync Error Alert */}
      {syncError && (
        <div className="bg-gold-50 border border-gold-200 text-gold-800 px-4 py-3 rounded-tuscan-lg flex items-center text-sm shadow-tuscan-sm">
          <FiAlertCircle className="mr-2 flex-shrink-0 text-gold-600" />
          <span>Sync error: {syncError}</span>
        </div>
      )}

      {/* Error Alert */}
      {error && (
        <div className="bg-terracotta-50 border border-terracotta-200 text-terracotta-800 px-4 py-3 rounded-tuscan-lg flex items-start shadow-tuscan-sm">
          <FiAlertCircle className="text-xl mr-3 mt-0.5 flex-shrink-0 text-terracotta-600" />
          <div className="flex-1">
            <p className="font-medium">Error</p>
            <p className="text-sm mt-1 text-terracotta-700">{error}</p>
          </div>
          <button
            onClick={() => setError(null)}
            className="ml-2 p-2 min-h-[44px] min-w-[44px] text-terracotta-500 hover:text-terracotta-700 hover:bg-terracotta-100 active:bg-terracotta-200 rounded-lg transition-colors touch-manipulation flex items-center justify-center"
          >
            <span className="text-xl font-bold">×</span>
          </button>
        </div>
      )}

      {/* Stats Grid with Tuscan styling - 2 columns on desktop */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-4">
        {/* Unassigned Tours Card */}
        <div className="group bg-gradient-to-br from-gold-50 to-gold-100/50 rounded-tuscan-xl border border-gold-200/50 p-5 md:p-6 shadow-tuscan hover:shadow-tuscan-lg transition-all duration-300">
          <div className="flex items-start justify-between">
            <div className="flex-1">
              <p className="text-sm font-medium text-gold-700 mb-2">Unassigned Tours</p>
              <p className="text-4xl md:text-5xl font-bold text-stone-900 tracking-tight">
                {stats.unassignedTours}
              </p>
              <p className="text-xs text-stone-500 mt-2 flex items-center">
                <FiCalendar className="mr-1" />
                Future tours without guide
              </p>
            </div>
            <div className="w-14 h-14 bg-gold-200/50 rounded-tuscan-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
              <FiUsers className="text-gold-600 text-2xl" />
            </div>
          </div>
          {stats.unassignedTours > 0 && (
            <div className="mt-4 pt-4 border-t border-gold-200/50">
              <Link
                to="/tours"
                className="text-sm font-medium text-gold-700 hover:text-gold-800 active:text-gold-900 flex items-center min-h-[44px] group-hover:translate-x-1 transition-transform touch-manipulation"
              >
                Assign guides now
                <span className="ml-1">→</span>
              </Link>
            </div>
          )}
        </div>

        {/* Guide Payment Status Card */}
        <div className={`group rounded-tuscan-xl border p-5 md:p-6 shadow-tuscan hover:shadow-tuscan-lg transition-all duration-300 ${
          stats.unpaidTours > 0
            ? 'bg-gradient-to-br from-terracotta-50 to-terracotta-100/50 border-terracotta-200/50'
            : 'bg-gradient-to-br from-olive-50 to-olive-100/50 border-olive-200/50'
        }`}>
          <div className="flex items-start justify-between">
            <div className="flex-1">
              <p className={`text-sm font-medium mb-2 ${
                stats.unpaidTours > 0 ? 'text-terracotta-700' : 'text-olive-700'
              }`}>
                Guide Payments Pending
              </p>
              <p className="text-4xl md:text-5xl font-bold text-stone-900 tracking-tight">
                {stats.unpaidTours}
              </p>
              <p className="text-xs text-stone-500 mt-2 flex items-center">
                <FiDollarSign className="mr-1" />
                Past guided tours awaiting payment
              </p>
              {stats.paidTours > 0 && (
                <p className="text-xs text-olive-600 mt-1">
                  {stats.paidTours} tours already paid
                </p>
              )}
            </div>
            <div className={`w-14 h-14 rounded-tuscan-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300 ${
              stats.unpaidTours > 0 ? 'bg-terracotta-200/50' : 'bg-olive-200/50'
            }`}>
              <FiDollarSign className={`text-2xl ${
                stats.unpaidTours > 0 ? 'text-terracotta-600' : 'text-olive-600'
              }`} />
            </div>
          </div>
          {stats.unpaidTours > 0 && (
            <div className="mt-4 pt-4 border-t border-terracotta-200/50">
              <Link
                to="/payments"
                className="text-sm font-medium text-terracotta-700 hover:text-terracotta-800 active:text-terracotta-900 flex items-center min-h-[44px] group-hover:translate-x-1 transition-transform touch-manipulation"
              >
                Process payments
                <span className="ml-1">→</span>
              </Link>
            </div>
          )}
        </div>
      </div>

      {/* Tours Lists */}
      <div className="grid grid-cols-1 xl:grid-cols-2 gap-4 md:gap-6">
        {/* Upcoming Tours */}
        <div className="bg-white rounded-tuscan-xl shadow-tuscan border border-stone-200/50 overflow-hidden">
          <div className="px-4 md:px-5 py-3 md:py-4 bg-gradient-to-r from-olive-500 to-olive-600 flex items-center justify-between">
            <h2 className="text-base md:text-lg font-semibold text-white flex items-center">
              <FiCalendar className="mr-2" />
              Upcoming Tours
            </h2>
            <Link to="/tours" className="min-h-[44px] flex items-center touch-manipulation">
              <span className="text-olive-100 hover:text-white text-sm font-medium flex items-center transition-colors">
                View All
                <FiEye className="ml-1" />
              </span>
            </Link>
          </div>

          <div className="p-3 md:p-4">
            {upcomingTours.length === 0 ? (
              <div className="text-center py-8 text-stone-400">
                <FiCalendar className="text-4xl mx-auto mb-3 opacity-50" />
                <p className="font-medium">No upcoming tours</p>
                <p className="text-sm mt-1">New bookings will appear here</p>
              </div>
            ) : (
              <div className="space-y-2">
                {upcomingTours.map((tour) => (
                  <div
                    key={tour.id}
                    className="group p-3 rounded-tuscan-lg border border-stone-100 hover:border-olive-200 hover:bg-olive-50/30 active:bg-olive-50/50 transition-all duration-200 cursor-pointer touch-manipulation min-h-[44px]"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="font-medium text-stone-800 text-sm md:text-base line-clamp-1 md:truncate group-hover:text-olive-700 transition-colors flex-1 min-w-0">
                        {tour.title}
                      </h3>
                      <div className="flex items-center gap-1.5 flex-shrink-0">
                        {tour.language && (
                          <span className="hidden sm:inline-flex items-center px-2 py-0.5 bg-renaissance-50 text-renaissance-700 text-xs font-medium rounded-full">
                            {tour.language}
                          </span>
                        )}
                        {tour.booking_channel && (
                          <span className="inline-flex px-2 py-0.5 bg-stone-100 text-stone-600 text-xs font-medium rounded-full">
                            {tour.booking_channel}
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 md:gap-3 text-xs md:text-sm text-stone-500 mt-1">
                      <span className="flex items-center">
                        <FiCalendar className="mr-1 text-stone-400 w-3 h-3 md:w-4 md:h-4" />
                        {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
                      </span>
                      <span className="flex items-center">
                        <FiClock className="mr-1 text-stone-400 w-3 h-3 md:w-4 md:h-4" />
                        {tour.time}
                      </span>
                      <span className={`flex items-center ${tour.guide_name ? 'text-olive-600' : 'text-gold-600'}`}>
                        <FiUsers className="mr-1 w-3 h-3 md:w-4 md:h-4" />
                        {tour.guide_name || 'Unassigned'}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Unassigned Tours */}
        <div className="bg-white rounded-tuscan-xl shadow-tuscan border border-stone-200/50 overflow-hidden">
          <div className="px-4 md:px-5 py-3 md:py-4 bg-gradient-to-r from-gold-500 to-gold-600 flex items-center justify-between">
            <h2 className="text-base md:text-lg font-semibold text-white flex items-center">
              <FiAlertCircle className="mr-2" />
              Needs Attention
            </h2>
            <Link to="/tours" className="min-h-[44px] flex items-center touch-manipulation">
              <span className="text-gold-100 hover:text-white text-sm font-medium flex items-center transition-colors">
                View All
                <FiEye className="ml-1" />
              </span>
            </Link>
          </div>

          <div className="p-3 md:p-4">
            {recentTours.length === 0 ? (
              <div className="text-center py-8 text-stone-400">
                <FiClock className="text-4xl mx-auto mb-3 opacity-50" />
                <p className="font-medium">All tours are assigned!</p>
                <p className="text-sm mt-1">Great job keeping up</p>
              </div>
            ) : (
              <div className="space-y-2">
                {recentTours.map((tour) => (
                  <div
                    key={tour.id}
                    className="group p-3 rounded-tuscan-lg border border-stone-100 hover:border-gold-200 hover:bg-gold-50/30 active:bg-gold-50/50 transition-all duration-200 cursor-pointer touch-manipulation min-h-[44px]"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="font-medium text-stone-800 text-sm md:text-base line-clamp-1 md:truncate group-hover:text-gold-700 transition-colors flex-1 min-w-0">
                        {tour.title}
                      </h3>
                      <span className={`inline-flex px-2 py-0.5 text-xs font-medium rounded-full flex-shrink-0 ${
                        tour.payment_status === 'paid' || tour.paid
                          ? 'bg-olive-100 text-olive-700'
                          : tour.payment_status === 'partial'
                          ? 'bg-gold-100 text-gold-700'
                          : 'bg-terracotta-100 text-terracotta-700'
                      }`}>
                        {tour.payment_status === 'paid' || tour.paid
                          ? 'Paid'
                          : tour.payment_status === 'partial'
                          ? 'Partial'
                          : 'Unpaid'}
                      </span>
                    </div>
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 md:gap-3 text-xs md:text-sm text-stone-500 mt-1">
                      <span className="flex items-center">
                        <FiCalendar className="mr-1 text-stone-400 w-3 h-3 md:w-4 md:h-4" />
                        {new Date(tour.date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}
                      </span>
                      <span className="flex items-center">
                        <FiClock className="mr-1 text-stone-400 w-3 h-3 md:w-4 md:h-4" />
                        {tour.time}
                      </span>
                      <span className="text-gold-600 font-medium">
                        Needs Guide
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
