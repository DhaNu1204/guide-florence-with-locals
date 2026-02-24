import React, { useState } from 'react';
import { FiChevronDown, FiChevronRight, FiUsers, FiUser, FiSave, FiX, FiScissors, FiTrash2 } from 'react-icons/fi';
import { tourGroupsAPI } from '../services/mysqlDB';

const getChannelColor = (channel) => {
  if (!channel) return 'text-stone-500';
  const ch = channel.toLowerCase();
  if (ch.includes('viator')) return 'text-purple-600';
  if (ch.includes('getyourguide')) return 'text-orange-600';
  return 'text-renaissance-600';
};

const TourGroupCardMobile = ({
  group,
  guides,
  onRefresh,
  onError,
  onSuccess,
  onTourClick,
  // Selection mode
  selectionMode,
  isSelected,
  onToggleSelect,
}) => {
  const [expanded, setExpanded] = useState(false);
  const [editingGuide, setEditingGuide] = useState(false);
  const [selectedGuideId, setSelectedGuideId] = useState(group.guide_id || '');
  const [savingGuide, setSavingGuide] = useState(false);
  const [actionLoading, setActionLoading] = useState({});

  const totalPax = group.total_pax || group.tours?.reduce((sum, t) => sum + (parseInt(t.participants) || 0), 0) || 0;
  const bookingCount = group.booking_count || group.tours?.length || 0;
  const guideName = group.assigned_guide_name || group.guide_name || guides.find(g => g.id == group.guide_id)?.name || null;
  const groupTime = group.group_time ? group.group_time.substring(0, 5) : '';

  const languages = [...new Set(
    (group.tours || []).map(t => t.language).filter(Boolean)
  )];

  const handleGuideUpdate = async () => {
    setSavingGuide(true);
    try {
      await tourGroupsAPI.update(group.id, { guide_id: selectedGuideId || null });
      const name = guides.find(g => g.id == selectedGuideId)?.name || 'None';
      onSuccess?.(`Guide "${name}" assigned to group`);
      setEditingGuide(false);
      onRefresh?.();
    } catch (err) {
      onError?.('Failed to assign guide: ' + (err.response?.data?.error || err.message));
    } finally {
      setSavingGuide(false);
    }
  };

  const handleUnmerge = async (tourId) => {
    setActionLoading(prev => ({ ...prev, [`unmerge_${tourId}`]: true }));
    try {
      await tourGroupsAPI.unmerge(tourId);
      onSuccess?.('Tour removed from group');
      onRefresh?.();
    } catch (err) {
      onError?.('Failed to unmerge: ' + (err.response?.data?.error || err.message));
    } finally {
      setActionLoading(prev => ({ ...prev, [`unmerge_${tourId}`]: false }));
    }
  };

  const handleDissolve = async () => {
    setActionLoading(prev => ({ ...prev, dissolve: true }));
    try {
      await tourGroupsAPI.dissolve(group.id);
      onSuccess?.('Group dissolved');
      onRefresh?.();
    } catch (err) {
      onError?.('Failed to dissolve group: ' + (err.response?.data?.error || err.message));
    } finally {
      setActionLoading(prev => ({ ...prev, dissolve: false }));
    }
  };

  return (
    <div
      className={`border rounded-tuscan-lg overflow-hidden transition-all shadow-tuscan ${
        isSelected
          ? 'border-terracotta-400 ring-2 ring-terracotta-200 bg-terracotta-50/30'
          : 'border-renaissance-200 bg-renaissance-50/30'
      }`}
      data-group-id={group.id}
      data-type="group"
    >
      {/* Header — collapsed view */}
      <div
        className="px-3 pt-3 pb-2 cursor-pointer active:bg-renaissance-50/60 touch-manipulation"
        onClick={() => {
          if (selectionMode) {
            onToggleSelect?.(group.id, 'group');
          } else {
            setExpanded(!expanded);
          }
        }}
      >
        {/* Row 1: Time, Language, Booking count, Selection */}
        <div className="flex items-center gap-2 mb-1">
          <span className="text-sm font-bold text-stone-900">{groupTime}</span>
          <span className="text-stone-300">·</span>
          {languages.map(lang => (
            <span
              key={lang}
              className="text-xs font-medium px-2 py-0.5 rounded-full bg-renaissance-50 text-renaissance-700"
            >
              {lang}
            </span>
          ))}
          <span className="ml-auto text-xs text-stone-500 bg-stone-100 px-2 py-0.5 rounded-full">
            {bookingCount} {bookingCount === 1 ? 'booking' : 'bookings'}
          </span>

          {selectionMode && (
            <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-colors flex-shrink-0 ${
              isSelected
                ? 'bg-terracotta-500 border-terracotta-500'
                : 'border-stone-300 bg-white'
            }`}>
              {isSelected && (
                <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                </svg>
              )}
            </div>
          )}
        </div>

        {/* Row 2: Tour name */}
        <p className="text-sm text-stone-900 line-clamp-2 leading-snug mb-1">
          {group.display_name}
        </p>

        {/* Row 3: PAX + Guide */}
        <div className="flex items-center gap-2">
          <div className="flex items-center gap-1 text-sm flex-shrink-0">
            <FiUsers size={14} className="text-stone-500" />
            <span className="font-semibold text-stone-900">{totalPax}/9 PAX</span>
            {totalPax >= 9 && (
              <span className="text-xs text-terracotta-700 bg-terracotta-100 px-1.5 py-0.5 rounded-full font-medium">
                FULL
              </span>
            )}
          </div>
          <span className="text-stone-300">·</span>

          {/* Guide display/edit */}
          <div className="flex-1 min-w-0" onClick={(e) => e.stopPropagation()}>
            {editingGuide ? (
              <div className="flex items-center gap-1">
                <select
                  value={selectedGuideId}
                  onChange={(e) => setSelectedGuideId(e.target.value)}
                  className="flex-1 min-w-0 px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
                >
                  <option value="">Unassigned</option>
                  {guides.map(guide => (
                    <option key={guide.id} value={guide.id}>{guide.name}</option>
                  ))}
                </select>
                <button
                  onClick={handleGuideUpdate}
                  disabled={savingGuide}
                  className="p-1.5 text-olive-600 hover:text-olive-800 disabled:opacity-50 rounded-tuscan touch-manipulation"
                >
                  <FiSave size={16} />
                </button>
                <button
                  onClick={() => {
                    setEditingGuide(false);
                    setSelectedGuideId(group.guide_id || '');
                  }}
                  className="p-1.5 text-stone-400 hover:text-stone-600 rounded-tuscan touch-manipulation"
                >
                  <FiX size={16} />
                </button>
              </div>
            ) : (
              <span
                className={`text-sm truncate block cursor-pointer active:bg-stone-100 px-1 py-0.5 rounded-tuscan ${
                  guideName ? 'text-stone-900' : 'text-stone-400 italic'
                }`}
                onClick={() => {
                  setEditingGuide(true);
                  setSelectedGuideId(group.guide_id || '');
                }}
              >
                {guideName || 'Unassigned'}
              </span>
            )}
          </div>
        </div>

        {/* Row 4: Expand indicator */}
        {!selectionMode && (
          <div className="flex items-center gap-1 mt-1.5 text-xs text-stone-400">
            {expanded ? <FiChevronDown size={14} /> : <FiChevronRight size={14} />}
            <span>{expanded ? 'Tap to collapse' : 'Tap to expand'}</span>
            {group.is_manual_merge && (
              <span className="ml-auto text-xs bg-gold-100 text-gold-700 px-1.5 py-0.5 rounded-full">
                Manual
              </span>
            )}
          </div>
        )}
      </div>

      {/* Expanded: individual bookings list */}
      {expanded && (
        <div className="border-t border-renaissance-200 bg-white">
          <div className="divide-y divide-stone-100">
            {(group.tours || []).map((tour) => (
              <div
                key={tour.id}
                className={`px-3 py-2.5 flex items-center gap-2 ${tour.cancelled ? 'bg-red-50' : ''}`}
              >
                <FiUser size={12} className="text-stone-400 flex-shrink-0" />

                {/* Customer name — tap to view details */}
                <div
                  className="flex-1 min-w-0 cursor-pointer active:text-terracotta-600"
                  onClick={() => onTourClick?.(tour)}
                >
                  <span className="text-sm text-stone-900 truncate block">
                    {tour.customer_name || 'N/A'}
                  </span>
                </div>

                {/* PAX */}
                <span className="text-sm font-medium text-stone-700 flex-shrink-0">
                  {tour.participants || 1} PAX
                </span>

                {/* Channel */}
                <span className={`text-xs font-medium flex-shrink-0 ${getChannelColor(tour.booking_channel)}`}>
                  {tour.booking_channel ? (
                    tour.booking_channel.toLowerCase().includes('getyourguide') ? 'GYG' :
                    tour.booking_channel.toLowerCase().includes('viator') ? 'VIA' :
                    tour.booking_channel.substring(0, 4)
                  ) : 'DIR'}
                </span>

                {/* Unmerge button */}
                <button
                  onClick={() => handleUnmerge(tour.id)}
                  disabled={actionLoading[`unmerge_${tour.id}`]}
                  className="p-1.5 text-stone-400 hover:text-terracotta-600 hover:bg-terracotta-50 rounded-tuscan transition-colors disabled:opacity-50 touch-manipulation flex-shrink-0"
                  title="Remove from group"
                >
                  <FiScissors size={14} />
                </button>
              </div>
            ))}
          </div>

          {/* Group actions */}
          <div className="flex items-center justify-end gap-2 px-3 py-2 bg-stone-50 border-t border-stone-100">
            <button
              onClick={handleDissolve}
              disabled={actionLoading.dissolve}
              className="inline-flex items-center gap-1 px-3 py-1.5 text-xs text-terracotta-600 hover:text-terracotta-800 hover:bg-terracotta-50 rounded-tuscan transition-colors disabled:opacity-50 touch-manipulation"
            >
              <FiTrash2 size={12} />
              Dissolve Group
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default React.memo(TourGroupCardMobile);
