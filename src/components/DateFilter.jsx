import React, { useState, useRef, useEffect } from 'react';
import { FiCalendar, FiChevronLeft, FiChevronRight } from 'react-icons/fi';
import {
  format, startOfMonth, endOfMonth, startOfWeek, endOfWeek,
  eachDayOfInterval, addMonths, subMonths, isSameDay, isSameMonth, isToday
} from 'date-fns';

const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

/**
 * Themed in-app date filter for the Tours page.
 * Owns only the UI; all filtering state + setters come from the parent, so the
 * parent's load effect (which depends on these) fires exactly as before.
 */
const DateFilter = ({
  filterDate, setFilterDate,
  showUpcoming, setShowUpcoming,
  showPast, setShowPast,
  showDateRange, setShowDateRange,
  rangeStartDate, setRangeStartDate,
  rangeEndDate, setRangeEndDate,
}) => {
  const [open, setOpen] = useState(false);
  const [viewMonth, setViewMonth] = useState(filterDate || new Date());
  const wrapRef = useRef(null);

  const singleMode = !showUpcoming && !showPast && !showDateRange;
  const todayStr = format(new Date(), 'yyyy-MM-dd');
  const isTodaySelected = singleMode && filterDate && format(filterDate, 'yyyy-MM-dd') === todayStr;

  // Re-center the calendar on the selected date each time it opens.
  useEffect(() => {
    if (open) setViewMonth(filterDate || new Date());
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  // Close on outside-click and Escape.
  useEffect(() => {
    if (!open) return;
    const onDown = (e) => { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const clearFlags = () => { setShowUpcoming(false); setShowPast(false); setShowDateRange(false); };

  const selectDay = (day) => {
    setFilterDate(day);
    clearFlags();
    setOpen(false);
  };

  // Month arrows land on the 1st of the target month (owner's preferred behavior),
  // and keep the popover open so another day can be picked.
  const jumpMonth = (delta) => {
    const base = filterDate || new Date();
    const target = startOfMonth(delta > 0 ? addMonths(base, 1) : subMonths(base, 1));
    setFilterDate(target);
    clearFlags();
    setViewMonth(target);
  };

  const gridDays = eachDayOfInterval({
    start: startOfWeek(startOfMonth(viewMonth), { weekStartsOn: 0 }),
    end: endOfWeek(endOfMonth(viewMonth), { weekStartsOn: 0 }),
  });

  const triggerLabel = singleMode && filterDate
    ? format(filterDate, 'EEE, d MMM yyyy')
    : 'Pick a date';

  const segments = [
    {
      key: 'today', label: 'Today', active: isTodaySelected,
      onClick: () => { setFilterDate(new Date()); clearFlags(); setOpen(false); },
    },
    {
      key: 'upcoming', label: 'Upcoming', active: showUpcoming && !showPast && !showDateRange,
      onClick: () => { setShowUpcoming(true); setShowPast(false); setShowDateRange(false); },
    },
    {
      key: 'past', label: 'Past 40 Days', active: showPast && !showDateRange,
      onClick: () => { setShowPast(true); setShowUpcoming(false); setShowDateRange(false); },
    },
    {
      key: 'range', label: 'Date Range', active: showDateRange,
      onClick: () => { setShowDateRange(true); setShowUpcoming(false); setShowPast(false); },
    },
  ];

  return (
    <div>
      <label className="block text-sm font-medium text-stone-700 mb-1.5 md:mb-2">
        {showDateRange ? 'Date Range' : 'Select Date'}
      </label>

      {showDateRange ? (
        /* ---- Date range: two native inputs, restyled to match the theme ---- */
        <div className="flex gap-2 items-center mb-2">
          <input
            type="date"
            value={rangeStartDate}
            onChange={(e) => setRangeStartDate(e.target.value)}
            className="flex-1 min-w-0 px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
          />
          <span className="text-stone-400 text-sm flex-shrink-0">to</span>
          <input
            type="date"
            value={rangeEndDate}
            min={rangeStartDate || undefined}
            onChange={(e) => setRangeEndDate(e.target.value)}
            className="flex-1 min-w-0 px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500"
          />
        </div>
      ) : (
        /* ---- Single date: themed calendar popover ---- */
        <div className="relative mb-2" ref={wrapRef}>
          <button
            type="button"
            onClick={() => setOpen((o) => !o)}
            aria-haspopup="dialog"
            aria-expanded={open}
            aria-label="Select date"
            className={`w-full flex items-center gap-2 px-3 py-2.5 md:py-2 text-base md:text-sm text-left bg-white border rounded-tuscan transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-500 ${
              open ? 'border-terracotta-400 ring-2 ring-terracotta-200' : 'border-stone-300 hover:border-stone-400'
            }`}
          >
            <FiCalendar className="h-4 w-4 text-terracotta-500 flex-shrink-0" />
            <span className={singleMode && filterDate ? 'text-stone-900' : 'text-stone-400'}>
              {triggerLabel}
            </span>
          </button>

          {open && (
            <div
              role="dialog"
              aria-label="Calendar"
              className="absolute left-0 right-0 sm:right-auto sm:w-72 z-30 mt-2 p-3 bg-white border border-stone-200 rounded-tuscan-lg shadow-tuscan-md"
            >
              {/* Header */}
              <div className="flex items-center justify-between mb-2">
                <button
                  type="button"
                  onClick={() => jumpMonth(-1)}
                  aria-label="Previous month (jump to the 1st)"
                  className="h-8 w-8 flex items-center justify-center rounded-tuscan bg-renaissance-50 text-renaissance-700 hover:bg-renaissance-100 active:bg-renaissance-200 transition-colors touch-manipulation focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-500"
                >
                  <FiChevronLeft className="h-4 w-4" />
                </button>
                <span className="text-sm font-semibold text-stone-900">
                  {format(viewMonth, 'MMMM yyyy')}
                </span>
                <button
                  type="button"
                  onClick={() => jumpMonth(1)}
                  aria-label="Next month (jump to the 1st)"
                  className="h-8 w-8 flex items-center justify-center rounded-tuscan bg-renaissance-50 text-renaissance-700 hover:bg-renaissance-100 active:bg-renaissance-200 transition-colors touch-manipulation focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-500"
                >
                  <FiChevronRight className="h-4 w-4" />
                </button>
              </div>

              {/* Weekday row */}
              <div className="grid grid-cols-7 mb-1">
                {WEEKDAYS.map((d) => (
                  <div key={d} className="h-7 flex items-center justify-center text-xs font-medium text-stone-400">
                    {d}
                  </div>
                ))}
              </div>

              {/* Day grid */}
              <div className="grid grid-cols-7 gap-0.5">
                {gridDays.map((day) => {
                  const selected = singleMode && filterDate && isSameDay(day, filterDate);
                  const inMonth = isSameMonth(day, viewMonth);
                  const today = isToday(day);
                  let cls = 'text-stone-700 hover:bg-stone-100';
                  if (selected) cls = 'bg-terracotta-500 text-white font-semibold hover:bg-terracotta-600';
                  else if (today) cls = 'ring-2 ring-inset ring-terracotta-300 text-stone-900 hover:bg-stone-100';
                  else if (!inMonth) cls = 'text-stone-300 hover:bg-stone-50';
                  return (
                    <button
                      key={day.toISOString()}
                      type="button"
                      onClick={() => selectDay(day)}
                      className={`h-9 w-full min-w-[32px] flex items-center justify-center text-sm rounded-tuscan transition-colors touch-manipulation focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-500 ${cls}`}
                    >
                      {format(day, 'd')}
                    </button>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}

      {/* View segmented control */}
      <div className="flex flex-wrap gap-1 p-1 bg-stone-100 rounded-tuscan mt-2">
        {segments.map((s) => (
          <button
            key={s.key}
            type="button"
            onClick={s.onClick}
            className={`flex-1 min-w-[72px] min-h-[44px] px-3 py-2 rounded-tuscan text-sm font-medium whitespace-nowrap transition-colors touch-manipulation active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-terracotta-500 ${
              s.active
                ? 'bg-terracotta-500 text-white shadow-sm'
                : 'text-stone-600 hover:bg-stone-200 active:bg-stone-300'
            }`}
          >
            {s.label}
          </button>
        ))}
      </div>
    </div>
  );
};

export default DateFilter;
