import React, { useState } from 'react';
import { FiSave, FiX, FiUsers, FiChevronDown } from 'react-icons/fi';

const getChannelColor = (channel) => {
  if (!channel) return 'bg-stone-100 text-stone-600';
  const ch = channel.toLowerCase();
  if (ch.includes('viator')) return 'bg-purple-100 text-purple-700';
  if (ch.includes('getyourguide')) return 'bg-orange-100 text-orange-700';
  return 'bg-renaissance-50 text-renaissance-700';
};

const parseNames = (tour) => {
  if (!tour.participant_names) return [];
  try {
    const p = typeof tour.participant_names === 'string' ? JSON.parse(tour.participant_names) : tour.participant_names;
    return Array.isArray(p) ? p : [];
  } catch { return []; }
};

const MobileParticipantNames = ({ tour }) => {
  const [expanded, setExpanded] = useState(false);
  const names = parseNames(tour);
  if (names.length === 0) return null;

  const first = `${names[0].first} ${names[0].last}`;
  const rest = names.length - 1;

  return (
    <div className="text-xs text-stone-500 mt-0.5" onClick={(e) => e.stopPropagation()}>
      {expanded ? (
        <div className="space-y-0.5">
          {names.map((p, i) => (
            <div key={i}>{p.first} {p.last}</div>
          ))}
          <button
            onClick={() => setExpanded(false)}
            className="text-terracotta-500 text-xs"
          >
            show less
          </button>
        </div>
      ) : (
        <span
          className="cursor-pointer"
          onClick={() => { if (rest > 0) setExpanded(true); }}
        >
          {first}{rest > 0 && <span className="text-terracotta-500 ml-1">+{rest} more</span>}
        </span>
      )}
    </div>
  );
};

const TourCardMobile = ({
  tour,
  guideName,
  guides,
  tourTime,
  tourLanguage,
  participantCount,
  editingGuides,
  editingNotes,
  savingChanges,
  onGuideChange,
  onGuideSave,
  onGuideEditStart,
  onGuideEditCancel,
  onNotesChange,
  onNotesSave,
  onNotesEditStart,
  onNotesEditCancel,
  onCardClick,
  // Selection mode for merge
  selectionMode,
  isSelected,
  onToggleSelect,
  // Drag support (kept for data attributes)
  dragState,
}) => {
  const isEditingGuide = editingGuides[tour.id] !== undefined;
  const isEditingNotes = editingNotes[tour.id] !== undefined;

  return (
    <div
      className={`border rounded-tuscan-lg shadow-tuscan overflow-hidden transition-all touch-manipulation ${
        isSelected
          ? 'border-terracotta-400 ring-2 ring-terracotta-200 bg-terracotta-50/30'
          : tour.cancelled
            ? 'border-stone-200 bg-red-50'
            : 'border-stone-200 bg-white'
      }`}
      data-tour-id={tour.id}
      data-type="tour"
      onClick={() => {
        if (selectionMode) {
          onToggleSelect?.(tour.id, 'tour');
        } else {
          onCardClick(tour);
        }
      }}
    >
      {/* Row 1: Time, Channel, Language */}
      <div className="px-3 pt-3 pb-1 flex items-center gap-2 flex-wrap">
        <span className="text-sm font-bold text-stone-900">{tourTime}</span>
        <span className="text-stone-300">·</span>
        <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${getChannelColor(tour.booking_channel)}`}>
          {tour.booking_channel || 'Direct'}
        </span>
        {tourLanguage && (
          <>
            <span className="text-stone-300">·</span>
            <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-renaissance-50 text-renaissance-700">
              {tourLanguage}
            </span>
          </>
        )}
        {/* Selection checkbox */}
        {selectionMode && (
          <div className="ml-auto">
            <div className={`w-5 h-5 rounded border-2 flex items-center justify-center transition-colors ${
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
          </div>
        )}
      </div>

      {/* Row 2: Tour name (2-line truncate) + participant names */}
      <div className="px-3 pb-1">
        <p className="text-sm text-stone-900 line-clamp-2 leading-snug">
          {tour.title}
        </p>
        <MobileParticipantNames tour={tour} />
      </div>

      {/* Row 3: PAX + Guide */}
      <div className="px-3 pb-1 flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-center gap-1 text-sm text-stone-700 flex-shrink-0">
          <FiUsers size={14} className="text-stone-500" />
          <span className="font-medium">{participantCount} PAX</span>
        </div>
        <span className="text-stone-300">·</span>

        {/* Guide inline edit */}
        {isEditingGuide ? (
          <div className="flex items-center gap-1 flex-1 min-w-0">
            <select
              value={editingGuides[tour.id]}
              onChange={(e) => onGuideChange(tour.id, e.target.value)}
              className="flex-1 min-w-0 px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
            >
              <option value="">Unassigned</option>
              {guides.map(guide => (
                <option key={guide.id} value={guide.id}>{guide.name}</option>
              ))}
            </select>
            <button
              onClick={() => onGuideSave(tour.id)}
              disabled={savingChanges[`guide_${tour.id}`]}
              className="p-1.5 text-olive-600 hover:text-olive-800 disabled:opacity-50 rounded-tuscan touch-manipulation"
            >
              <FiSave size={16} />
            </button>
            <button
              onClick={() => onGuideEditCancel(tour.id)}
              className="p-1.5 text-stone-400 hover:text-stone-600 rounded-tuscan touch-manipulation"
            >
              <FiX size={16} />
            </button>
          </div>
        ) : (
          <span
            className={`text-sm truncate cursor-pointer active:bg-stone-100 px-1 py-0.5 rounded-tuscan ${
              guideName === 'Unassigned' ? 'text-stone-400 italic' : 'text-stone-900'
            }`}
            onClick={() => onGuideEditStart(tour.id)}
          >
            {guideName}
          </span>
        )}
      </div>

      {/* Row 4: Notes */}
      <div className="px-3 pb-3" onClick={(e) => e.stopPropagation()}>
        {isEditingNotes ? (
          <div className="flex items-start gap-1">
            <textarea
              value={editingNotes[tour.id]}
              onChange={(e) => onNotesChange(tour.id, e.target.value)}
              className="flex-1 px-2 py-1 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 resize-none"
              rows="2"
              placeholder="Add notes..."
            />
            <button
              onClick={() => onNotesSave(tour.id)}
              disabled={savingChanges[`notes_${tour.id}`]}
              className="p-1.5 text-olive-600 hover:text-olive-800 disabled:opacity-50 rounded-tuscan touch-manipulation"
            >
              <FiSave size={16} />
            </button>
            <button
              onClick={() => onNotesEditCancel(tour.id)}
              className="p-1.5 text-stone-400 hover:text-stone-600 rounded-tuscan touch-manipulation"
            >
              <FiX size={16} />
            </button>
          </div>
        ) : (
          <p
            className={`text-xs truncate cursor-pointer active:bg-stone-100 px-1 py-0.5 rounded-tuscan ${
              tour.notes ? 'text-stone-600' : 'text-stone-400 italic'
            }`}
            onClick={() => onNotesEditStart(tour.id)}
          >
            {tour.notes || 'Tap to add notes...'}
          </p>
        )}

        {/* Status badges */}
        {(tour.paid || tour.cancelled || tour.rescheduled) && (
          <div className="flex items-center gap-1.5 mt-1.5 flex-wrap">
            {tour.paid && (
              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-olive-100 text-olive-800">
                Paid
              </span>
            )}
            {tour.cancelled && (
              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-terracotta-100 text-terracotta-800">
                Cancelled
              </span>
            )}
            {tour.rescheduled && !tour.cancelled && tour.original_date && tour.original_time &&
             (tour.original_date !== tour.date || tour.original_time !== tour.time) && (
              <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gold-100 text-gold-800">
                Rescheduled
              </span>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default React.memo(TourCardMobile);
