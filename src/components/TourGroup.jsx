import React, { useState } from 'react';
import { FiChevronDown, FiChevronRight, FiUsers, FiUser, FiSave, FiX, FiScissors, FiTrash2 } from 'react-icons/fi';
import { tourGroupsAPI } from '../services/mysqlDB';

const GroupTourNames = ({ tour }) => {
  const names = (() => {
    if (!tour.participant_names) return [];
    try {
      const p = typeof tour.participant_names === 'string' ? JSON.parse(tour.participant_names) : tour.participant_names;
      return Array.isArray(p) ? p : [];
    } catch { return []; }
  })();

  if (names.length === 0) return null;

  // In expanded group, show all names since there's room
  return (
    <div className="text-xs text-stone-500 mt-0.5">
      {names.map((p, i) => (
        <span key={i}>
          {i > 0 && ', '}
          {p.first} {p.last}
        </span>
      ))}
    </div>
  );
};

const TourGroup = ({
  group,
  guides,
  onRefresh,
  onError,
  onSuccess,
  onTourClick,
  // Drag-and-drop props
  draggable,
  onDragStart,
  onDragOver,
  onDragLeave,
  onDrop,
  isDragOver,
}) => {
  const [expanded, setExpanded] = useState(false);
  const [editingGuide, setEditingGuide] = useState(false);
  const [selectedGuideId, setSelectedGuideId] = useState(group.guide_id || '');
  const [savingGuide, setSavingGuide] = useState(false);
  const [actionLoading, setActionLoading] = useState({});

  const totalPax = group.total_pax || group.tours?.reduce((sum, t) => sum + (parseInt(t.participants) || 0), 0) || 0;
  const bookingCount = group.booking_count || group.tours?.length || 0;
  const guideName = group.assigned_guide_name || group.guide_name || guides.find(g => g.id == group.guide_id)?.name || null;

  // Collect unique languages from tours
  const languages = [...new Set(
    (group.tours || [])
      .map(t => t.language)
      .filter(Boolean)
  )];

  const handleGuideUpdate = async () => {
    setSavingGuide(true);
    try {
      await tourGroupsAPI.update(group.id, {
        guide_id: selectedGuideId || null
      });
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

  const groupTime = group.group_time ? group.group_time.substring(0, 5) : '';

  return (
    <div
      className={`border rounded-tuscan-lg overflow-hidden transition-all duration-200 ${
        isDragOver
          ? 'border-terracotta-400 bg-terracotta-50 shadow-md ring-2 ring-terracotta-200'
          : 'border-renaissance-200 bg-renaissance-50/30'
      }`}
      draggable={draggable}
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
      data-group-id={group.id}
    >
      {/* Collapsed header row */}
      <div
        className="flex items-center gap-3 px-4 py-3 cursor-pointer hover:bg-renaissance-50/60 transition-colors"
        onClick={() => setExpanded(!expanded)}
      >
        {/* Expand/collapse icon */}
        <button className="text-stone-400 flex-shrink-0">
          {expanded ? <FiChevronDown size={16} /> : <FiChevronRight size={16} />}
        </button>

        {/* Time */}
        <span className="text-sm font-medium text-stone-900 w-14 flex-shrink-0">
          {groupTime}
        </span>

        {/* Tour name */}
        <div className="flex-1 min-w-0">
          <span className="text-sm font-medium text-stone-900 truncate block">
            {group.display_name}
          </span>
        </div>

        {/* Language badges */}
        <div className="flex gap-1 flex-shrink-0">
          {languages.map(lang => (
            <span
              key={lang}
              className="inline-block px-2 py-0.5 bg-renaissance-50 text-renaissance-700 text-xs font-medium rounded-tuscan"
            >
              {lang}
            </span>
          ))}
        </div>

        {/* PAX badge */}
        <div className="flex items-center gap-1.5 flex-shrink-0">
          <FiUsers size={14} className="text-stone-500" />
          <span className="text-sm font-semibold text-stone-900">{totalPax} PAX</span>
          <span className="text-xs text-stone-500 bg-stone-100 px-1.5 py-0.5 rounded-full">
            {bookingCount} {bookingCount === 1 ? 'booking' : 'bookings'}
          </span>
          {totalPax >= 9 && (
            <span className="text-xs text-terracotta-700 bg-terracotta-100 px-1.5 py-0.5 rounded-full font-medium">
              FULL
            </span>
          )}
        </div>

        {/* Guide */}
        <div className="flex-shrink-0 w-32" onClick={(e) => e.stopPropagation()}>
          {editingGuide ? (
            <div className="flex items-center gap-1">
              <select
                value={selectedGuideId}
                onChange={(e) => setSelectedGuideId(e.target.value)}
                className="px-2 py-1 border border-stone-300 rounded-tuscan text-xs focus:outline-none focus:ring-2 focus:ring-terracotta-500 w-20"
              >
                <option value="">None</option>
                {guides.map(guide => (
                  <option key={guide.id} value={guide.id}>{guide.name}</option>
                ))}
              </select>
              <button
                onClick={handleGuideUpdate}
                disabled={savingGuide}
                className="p-1 text-olive-600 hover:text-olive-800 disabled:opacity-50"
                title="Save"
              >
                <FiSave size={14} />
              </button>
              <button
                onClick={() => {
                  setEditingGuide(false);
                  setSelectedGuideId(group.guide_id || '');
                }}
                className="p-1 text-stone-400 hover:text-stone-600"
                title="Cancel"
              >
                <FiX size={14} />
              </button>
            </div>
          ) : (
            <span
              className={`text-sm cursor-pointer hover:bg-stone-100 px-2 py-1 rounded-tuscan block truncate ${
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

        {/* Notes indicator */}
        <div className="flex-shrink-0 w-16 text-xs text-stone-500 truncate">
          {group.notes || ''}
        </div>

        {/* Manual merge badge */}
        {group.is_manual_merge && (
          <span className="text-xs bg-gold-100 text-gold-700 px-1.5 py-0.5 rounded-full flex-shrink-0">
            Manual
          </span>
        )}
      </div>

      {/* Expanded: individual bookings */}
      {expanded && (
        <div className="border-t border-renaissance-200 bg-white">
          <table className="min-w-full divide-y divide-stone-100">
            <thead className="bg-stone-50">
              <tr>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase w-8"></th>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Customer</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Channel</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">PAX</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase">Confirmation</th>
                <th className="px-4 py-2 text-left text-xs font-medium text-stone-500 uppercase w-24">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-stone-100">
              {(group.tours || []).map((tour) => (
                <tr
                  key={tour.id}
                  className={`hover:bg-stone-50 ${tour.cancelled ? 'bg-red-50 line-through' : ''}`}
                >
                  <td className="px-4 py-2">
                    <FiUser size={12} className="text-stone-400" />
                  </td>
                  <td
                    className="px-4 py-2 text-sm text-stone-900 cursor-pointer hover:text-terracotta-600"
                    onClick={() => onTourClick?.(tour)}
                  >
                    <div>{tour.customer_name || 'N/A'}</div>
                    <GroupTourNames tour={tour} />
                  </td>
                  <td className="px-4 py-2 text-sm text-stone-600">
                    {tour.booking_channel || 'Direct'}
                  </td>
                  <td className="px-4 py-2 text-sm text-stone-900 font-medium">
                    {tour.participants || 1}
                  </td>
                  <td className="px-4 py-2 text-xs text-stone-500 font-mono">
                    {tour.bokun_confirmation_code || '-'}
                  </td>
                  <td className="px-4 py-2">
                    <button
                      onClick={() => handleUnmerge(tour.id)}
                      disabled={actionLoading[`unmerge_${tour.id}`]}
                      className="inline-flex items-center gap-1 px-2 py-1 text-xs text-stone-600 hover:text-terracotta-600 hover:bg-terracotta-50 rounded-tuscan transition-colors disabled:opacity-50"
                      title="Remove from group"
                    >
                      <FiScissors size={12} />
                      Unmerge
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Group actions */}
          <div className="flex items-center justify-end gap-2 px-4 py-2 bg-stone-50 border-t border-stone-100">
            <button
              onClick={handleDissolve}
              disabled={actionLoading.dissolve}
              className="inline-flex items-center gap-1 px-3 py-1.5 text-xs text-terracotta-600 hover:text-terracotta-800 hover:bg-terracotta-50 rounded-tuscan transition-colors disabled:opacity-50"
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

export default TourGroup;
