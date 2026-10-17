import React from 'react';
import Pill from '../base/Pill';
import { CancelledToggle } from '../CancelledCompact';
import { LangCell } from './RowParts';
import { shortChannel, leadGuest } from './rowText';

// Step 9.2: the step 6.23 one-line cancelled booking in the 40 px muted grammar - same columns,
// Cancelled pill in Status, the chevron in the last column. Clicking the line or the chevron opens
// the full row (state held by the caller, as in 6.23). `member` = inside an expanded group.
const CancelledLine = ({ tour, time, language, pax, guideLabel, member = false, onToggle }) => (
  <div
    role="row"
    data-testid={`cancelled-compact-${tour.id}`}
    className="fwl-tours-grid h-10 bg-muted-row text-canc-ink border-b border-line-soft last:border-b-0 cursor-pointer"
    onClick={onToggle}
  >
    <div className="text-[13px] tabular-nums">{member ? '' : time}</div>
    <div className={`min-w-0 truncate text-[13px] ${member ? 'pl-7' : ''}`}>
      {member ? (leadGuest(tour) || 'N/A') : tour.title}
      {!member && leadGuest(tour) && <span className="ml-2 text-ink-3">{leadGuest(tour)}</span>}
      {member && tour.bokun_confirmation_code && <span className="ml-2 text-ink-3 font-mono text-xs">{tour.bokun_confirmation_code}</span>}
    </div>
    <div className="truncate text-[13px]">{shortChannel(tour.booking_channel)}</div>
    <div><LangCell language={language} /></div>
    <div className="text-[13px] tabular-nums">{pax}</div>
    <div className="truncate text-[13px]">{guideLabel}</div>
    <div><Pill tone="cancelled">Cancelled</Pill></div>
    <div />
    <div><CancelledToggle expanded={false} onToggle={onToggle} /></div>
  </div>
);

export default CancelledLine;
