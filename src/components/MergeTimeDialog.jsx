import { useEffect, useState } from 'react';

/**
 * Step 6.16: merging bookings booked at different times - which time does the group leave at?
 *
 * `options` = [{ time: 'HH:MM', bookings, pax }], earliest first. `defaultTime` is pre-selected
 * (the time of the departure being merged into). onConfirm(time) / onCancel().
 */
const MergeTimeDialog = ({ options, defaultTime, onConfirm, onCancel }) => {
  const [time, setTime] = useState(defaultTime);

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onCancel(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onCancel]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4" onClick={onCancel}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="merge-time-title"
        className="w-full max-w-sm rounded-tuscan-lg bg-white p-5 shadow-tuscan-lg"
        onClick={(e) => e.stopPropagation()}
      >
        <h2 id="merge-time-title" className="text-base font-semibold text-stone-900">
          What time does the group leave?
        </h2>
        <p className="mt-1 text-sm text-stone-600">
          These bookings were made for different times. Bookings at another time will show “booked …”.
        </p>
        <div className="mt-4 space-y-2">
          {options.map((o) => (
            <label
              key={o.time}
              className={`flex min-h-[44px] cursor-pointer items-center gap-3 rounded-tuscan border px-3 py-2 ${
                time === o.time ? 'border-terracotta-500 bg-terracotta-50' : 'border-stone-200'
              }`}
            >
              <input
                type="radio"
                name="merge-time"
                value={o.time}
                checked={time === o.time}
                onChange={() => setTime(o.time)}
                className="h-4 w-4 accent-terracotta-600"
              />
              <span className="text-sm font-semibold text-stone-900">{o.time}</span>
              <span className="text-xs text-stone-500">
                {o.bookings} booking{o.bookings === 1 ? '' : 's'}, {o.pax} PAX
              </span>
            </label>
          ))}
        </div>
        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            className="min-h-[44px] rounded-tuscan border border-stone-300 px-4 text-sm text-stone-700 hover:bg-stone-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={() => onConfirm(time)}
            disabled={!time}
            className="min-h-[44px] rounded-tuscan bg-terracotta-600 px-4 text-sm font-medium text-white hover:bg-terracotta-700 disabled:opacity-50"
          >
            Merge at {time}
          </button>
        </div>
      </div>
    </div>
  );
};

export default MergeTimeDialog;
