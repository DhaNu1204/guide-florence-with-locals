import React, { useEffect, useState } from 'react';
import { FiX, FiSave } from 'react-icons/fi';

/**
 * Step 6.4 — add or edit a departure by hand.
 *
 * Some GetYourGuide listings are not connected to Bokun ("Connectivity Settings:
 * Not connected"), so no sync can ever produce them. This form records the departure
 * so it still gets a guide, a radio line, a digest line and a P&L row.
 *
 * The guide is deliberately optional: a departure is normally entered long before
 * anyone is free to run it. The amount is optional too, and is the NET figure the
 * channel actually pays — the P&L takes it as net and subtracts no commission.
 */

export const DEFAULT_MANUAL_CHANNEL = 'GetYourGuide (direct)';

const LANGUAGES = ['English', 'Italian', 'Spanish', 'French', 'German', 'Portuguese'];

const emptyForm = () => ({
  title: '',
  date: '',
  time: '',
  participants: '',
  language: 'English',
  guide_id: '',
  booking_channel: DEFAULT_MANUAL_CHANNEL,
  manual_revenue: '',
  manual_currency: 'EUR',
  notes: '',
});

const toForm = (tour) => ({
  title: tour.title || '',
  date: (tour.date || '').slice(0, 10),
  time: (tour.time || '').slice(0, 5),
  participants: tour.participants != null ? String(tour.participants) : '',
  language: tour.language || '',
  guide_id: tour.guide_id != null ? String(tour.guide_id) : '',
  booking_channel: tour.booking_channel || DEFAULT_MANUAL_CHANNEL,
  manual_revenue: tour.manual_revenue != null ? String(tour.manual_revenue) : '',
  manual_currency: tour.manual_currency || 'EUR',
  notes: tour.notes || '',
});

/**
 * The same checks the API applies, so the owner is told what is wrong before a
 * round trip. The server remains the authority — it validates again.
 */
export const validateManualTour = (form) => {
  if (!form.title || !form.title.trim()) return 'A tour name is required';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.date || '')) return 'Date must be a real date in YYYY-MM-DD format';
  const [y, m, d] = (form.date || '').split('-').map(Number);
  const probe = new Date(Date.UTC(y, m - 1, d));
  if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== m - 1 || probe.getUTCDate() !== d) {
    return 'Date must be a real date in YYYY-MM-DD format';
  }
  if (!/^\d{1,2}:\d{2}$/.test(form.time || '')) return 'Start time must be in HH:MM format';
  const [hh, mm] = form.time.split(':').map(Number);
  if (hh > 23 || mm > 59) return 'Start time must be in HH:MM format';
  if (!/^\d+$/.test(String(form.participants || '')) || Number(form.participants) < 1) {
    return 'Participants must be a whole number of 1 or more';
  }
  if (Number(form.participants) > 200) return 'Participants looks wrong (over 200)';
  if (!form.booking_channel || !form.booking_channel.trim()) {
    return 'A channel is required (e.g. GetYourGuide (direct))';
  }
  const amount = String(form.manual_revenue ?? '').trim();
  if (amount !== '') {
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0) return 'Amount must be a number greater than 0';
    if (value > 100000) return 'Amount looks wrong (over 100000)';
  }
  return null;
};

const ManualTourModal = ({ isOpen, onClose, onSave, guides = [], tour = null, saving = false }) => {
  const [form, setForm] = useState(emptyForm());
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!isOpen) return;
    setForm(tour ? toForm(tour) : emptyForm());
    setError(null);
  }, [isOpen, tour]);

  if (!isOpen) return null;

  const set = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }));

  const handleSubmit = async (e) => {
    e.preventDefault();
    const problem = validateManualTour(form);
    if (problem) {
      setError(problem);
      return;
    }
    setError(null);
    await onSave({
      title: form.title.trim(),
      date: form.date,
      time: form.time,
      participants: Number(form.participants),
      language: form.language || null,
      guide_id: form.guide_id === '' ? null : Number(form.guide_id),
      booking_channel: form.booking_channel.trim(),
      manual_revenue: String(form.manual_revenue).trim() === '' ? null : Number(form.manual_revenue),
      manual_currency: form.manual_currency || 'EUR',
      notes: form.notes.trim() === '' ? null : form.notes.trim(),
    });
  };

  const field = 'w-full px-3 py-2.5 md:py-2 text-base md:text-sm border border-stone-300 rounded-tuscan focus:outline-none focus:ring-2 focus:ring-terracotta-500';
  const label = 'block text-sm font-medium text-stone-700 mb-1.5';

  return (
    <div className="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/40 p-0 md:p-4">
      <div className="bg-white w-full md:max-w-2xl md:rounded-tuscan rounded-t-tuscan max-h-[92vh] overflow-y-auto shadow-xl">
        <div className="flex items-center justify-between px-5 py-4 border-b border-stone-200 sticky top-0 bg-white">
          <div>
            <h3 className="text-lg font-semibold text-stone-900">
              {tour ? 'Edit tour' : 'Add tour by hand'}
            </h3>
            <p className="text-xs text-stone-500 mt-0.5">
              For a departure that never reaches Bokun — a channel listing that is not connected.
            </p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="p-2 text-stone-500 hover:text-stone-700">
            <FiX className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="px-5 py-4 space-y-4" data-testid="manual-tour-form">
          <div>
            <label className={label} htmlFor="manual-title">Tour name</label>
            <input id="manual-title" type="text" value={form.title} onChange={set('title')}
                   className={field} placeholder="Florence: Michelangelo's Life and Legacy 3.5 Hr Guided Tour" />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className={label} htmlFor="manual-date">Date</label>
              <input id="manual-date" type="date" value={form.date} onChange={set('date')} className={field} />
            </div>
            <div>
              <label className={label} htmlFor="manual-time">Start time</label>
              <input id="manual-time" type="time" value={form.time} onChange={set('time')} className={field} />
            </div>
            <div>
              <label className={label} htmlFor="manual-pax">Participants</label>
              <input id="manual-pax" type="number" min="1" value={form.participants}
                     onChange={set('participants')} className={field} />
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className={label} htmlFor="manual-language">Language</label>
              <select id="manual-language" value={form.language} onChange={set('language')} className={field}>
                <option value="">Unknown</option>
                {LANGUAGES.map((l) => <option key={l} value={l}>{l}</option>)}
              </select>
            </div>
            <div>
              <label className={label} htmlFor="manual-guide">Guide (optional)</label>
              <select id="manual-guide" value={form.guide_id} onChange={set('guide_id')} className={field}>
                <option value="">No guide yet</option>
                {guides.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
              </select>
            </div>
          </div>

          <div>
            <label className={label} htmlFor="manual-channel">Channel</label>
            <input id="manual-channel" type="text" value={form.booking_channel}
                   onChange={set('booking_channel')} className={field} />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div className="md:col-span-2">
              <label className={label} htmlFor="manual-revenue">Revenue (optional)</label>
              <input id="manual-revenue" type="text" inputMode="decimal" value={form.manual_revenue}
                     onChange={set('manual_revenue')} className={field} placeholder="418.29" />
              <p className="text-xs text-stone-500 mt-1">
                What the channel actually pays you, after their commission. The P&amp;L uses this figure as it is.
              </p>
            </div>
            <div>
              <label className={label} htmlFor="manual-currency">Currency</label>
              <input id="manual-currency" type="text" maxLength={3} value={form.manual_currency}
                     onChange={(e) => setForm((f) => ({ ...f, manual_currency: e.target.value.toUpperCase() }))}
                     className={field} />
            </div>
          </div>

          <div>
            <label className={label} htmlFor="manual-notes">Notes (optional)</label>
            <textarea id="manual-notes" rows={2} value={form.notes} onChange={set('notes')} className={field} />
          </div>

          {error && (
            <div className="text-sm text-red-700 bg-red-50 border border-red-200 rounded-tuscan px-3 py-2"
                 data-testid="manual-tour-error" role="alert">
              {error}
            </div>
          )}

          <div className="flex gap-2 pt-1 pb-2">
            <button type="button" onClick={onClose}
                    className="flex-1 px-4 py-2.5 border border-stone-300 rounded-tuscan text-stone-700 hover:bg-stone-50">
              Cancel
            </button>
            <button type="submit" disabled={saving}
                    className="flex-1 px-4 py-2.5 bg-terracotta-500 text-white rounded-tuscan hover:bg-terracotta-600 disabled:opacity-60 flex items-center justify-center gap-2">
              <FiSave className="h-4 w-4" />
              {saving ? 'Saving…' : (tour ? 'Save changes' : 'Add tour')}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
};

export default ManualTourModal;
