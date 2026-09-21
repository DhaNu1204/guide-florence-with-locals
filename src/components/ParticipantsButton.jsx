import React, { useState } from 'react';
import { FiFileText } from 'react-icons/fi';
import { downloadParticipantsPdf } from '../services/mysqlDB';

/**
 * Step 6.8 - download the printable participant list for ONE departure.
 *
 * Icon only on purpose: the owner opens the Tours page on his phone at a meeting point and
 * the row must not grow. The unit is the same tour-unit id the rest of the system uses, so a
 * group and a manual merge both produce a single sheet.
 */
const ParticipantsButton = ({ unit, label = 'Participant list (PDF)', onError }) => {
  const [busy, setBusy] = useState(false);

  const handle = async (e) => {
    e.stopPropagation();
    if (busy) return;
    setBusy(true);
    try {
      await downloadParticipantsPdf(unit);
    } catch (err) {
      if (onError) onError(err?.response?.data?.error || 'Could not build the participant list');
    } finally {
      setBusy(false);
    }
  };

  return (
    <button
      onClick={handle}
      disabled={busy}
      title={label}
      aria-label={label}
      data-testid={`participants-pdf-${unit}`}
      className="p-1.5 min-h-[32px] min-w-[32px] inline-flex items-center justify-center rounded-tuscan
                 text-stone-500 hover:text-terracotta-600 hover:bg-terracotta-50 active:bg-terracotta-100
                 disabled:opacity-40 transition-colors touch-manipulation"
    >
      <FiFileText size={15} className={busy ? 'animate-pulse' : ''} />
    </button>
  );
};

export default ParticipantsButton;
