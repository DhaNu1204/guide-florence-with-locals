import React from 'react';
import { FiAlertCircle } from 'react-icons/fi';
import Button from '../base/Button';

// Step 9.2 (rule 5): the actionable number at the top. `count` is the server's needs-a-guide
// number (the same one the old Summary showed); `first` is the first such departure on screen.
const AttentionStrip = ({ count, first, onReport, onAssign }) => {
  if (!(count > 0)) return null;
  return (
    <div
      role="status"
      data-testid="attention-strip"
      className="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 bg-danger-strip border border-danger-border rounded-xl text-sm"
    >
      <FiAlertCircle aria-hidden="true" className="w-4 h-4 text-danger flex-shrink-0" />
      <div className="min-w-0 flex-1 text-ink">
        <span className="font-semibold text-danger-strong" data-testid="need-guide-count">
          {count} {count === 1 ? 'departure needs' : 'departures need'} a guide
        </span>
        {first && (
          <span className="text-ink-2 tabular-nums">
            {' · '}{first.time} {first.title}{' · '}{first.channel}{' · '}{first.guests} {first.guests === 1 ? 'guest' : 'guests'}
          </span>
        )}
      </div>
      <button type="button" onClick={onReport} className="text-sm font-medium text-danger-strong underline underline-offset-2 hover:text-danger">
        Unassigned report
      </button>
      {first && <Button variant="primary" onClick={onAssign}>Assign guide</Button>}
    </div>
  );
};

export default AttentionStrip;
