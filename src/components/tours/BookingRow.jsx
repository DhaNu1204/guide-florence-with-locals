import React from 'react';
import Pill from '../base/Pill';
import { CancelledToggle } from '../CancelledCompact';
import { isVasari } from '../../utils/vasari';
import { isGuidePaid } from '../../utils/paymentBadges';
import { LangCell, GuideCell, NoteButton } from './RowParts';
import RowMenu from './RowMenu';
import { shortChannel, leadGuest, isRescheduled } from './rowText';

// Step 9.2: one booking that is not in a group - 56 px, title line over a meta line, the same
// nine columns as every other row. Every action the old row carried is either on the row (click =
// booking details, guide cell = assign, note icon = note) or in the ⋯ menu the page passes in.
const BookingRow = ({
  tour, time, language, pax, paxBreakdown, guideName,
  guideEditor, onEditGuide, noteEditor, onEditNote, requestStatus,
  menuItems, onOpen, onCollapseCancelled, dragProps = {}, isDragOver = false, isDragged = false,
}) => {
  const lead = leadGuest(tour);
  const meta = [];
  if (lead) meta.push(<span key="lead">{lead}</span>);
  if (paxBreakdown) meta.push(<span key="pb">{paxBreakdown}</span>);
  if (isVasari(tour)) meta.push(<span key="vas" data-testid="vasari-chip">Vasari</span>);
  if (Number(tour.is_private) === 1) meta.push(<span key="priv">Private</span>);
  // step 6.9 / 6.18 as words: the old account, or an account we could not tell
  if (tour.viator_account === 'legacy') meta.push(<span key="via" data-testid="viator-legacy-chip" title="Booked through the old Viator account - check it with Viator before the tour">old Viator</span>);
  if (tour.viator_account === 'unknown') meta.push(<span key="via" data-testid="viator-unknown-chip" title="We could not tell which Viator account this booking came from">Viator ?</span>);
  if (tour.is_manual) meta.push(<span key="man" data-testid="manual-tour-badge" title="Added by hand - the Bokun sync never changes this row">Added by hand</span>);
  if (isGuidePaid(tour)) meta.push(<span key="paid">Guide paid</span>);
  if (requestStatus && !tour.guide_id && !tour.cancelled) {
    meta.push(requestStatus.status === 'pending'
      ? <span key="req" className="text-note-ink">Richiesto a {requestStatus.guide_name} — in attesa</span>
      : <span key="req">Rifiutato da {requestStatus.guide_name}</span>);
  }
  if (tour.notes) meta.push(<span key="note" className="text-note-ink">Note: “{tour.notes}”</span>);

  const needsGuide = !tour.guide_id && !tour.cancelled;
  const duplicate = tour.possible_duplicate_of && tour.possible_duplicate_of.length > 0;

  return (
    <div
      data-testid={`tour-row-${tour.id}`}
      data-row-tour-id={tour.id}
      className={`border-b border-line-soft last:border-b-0 transition-colors
        ${isDragOver ? 'bg-accent-soft ring-2 ring-inset ring-accent/40' : tour.cancelled ? 'bg-muted-row' : 'hover:bg-muted-row'}
        ${isDragged ? 'opacity-50' : ''}`}
      {...dragProps}
    >
      <div role="row" className="fwl-tours-grid min-h-14 py-2 cursor-pointer" onClick={() => onOpen(tour)}>
        <div className={`text-sm font-semibold tabular-nums ${tour.cancelled ? 'text-canc-ink' : 'text-ink'}`}>{time}</div>
        <div className="min-w-0">
          <div className={`truncate text-sm font-medium ${tour.cancelled ? 'text-canc-ink line-through' : 'text-ink'}`} title={tour.title}>{tour.title}</div>
          {meta.length > 0 && (
            <div className="truncate text-[12.5px] text-ink-2" title={tour.notes || undefined}>
              {meta.map((m, i) => <React.Fragment key={i}>{i > 0 && ' · '}{m}</React.Fragment>)}
            </div>
          )}
        </div>
        <div className="truncate text-[13px] text-ink-2" title={tour.booking_channel || 'Website'}>{shortChannel(tour.booking_channel)}</div>
        <div><LangCell language={language} /></div>
        <div className="text-sm font-semibold text-ink tabular-nums">{pax}</div>
        <GuideCell name={guideName} onEdit={onEditGuide}>{guideEditor}</GuideCell>
        <div className="flex flex-wrap gap-1 min-w-0">
          {needsGuide && <Pill tone="need">Needs guide</Pill>}
          {isRescheduled(tour) && <Pill tone="resched" title={`Originally scheduled for ${tour.original_date} at ${tour.original_time}`}>Rescheduled</Pill>}
          {duplicate && (
            <Pill tone="need" data-testid="duplicate-badge"
              title={`Same date, time and PAX as tour ${tour.possible_duplicate_of.join(', ')} - nothing was merged`}>
              Possible duplicate
            </Pill>
          )}
          {tour.cancelled && <Pill tone="cancelled">Cancelled</Pill>}
        </div>
        <div>
          {tour.cancelled && onCollapseCancelled
            ? <CancelledToggle expanded onToggle={onCollapseCancelled} />
            : <NoteButton hasNote={Boolean(tour.notes)} onClick={onEditNote} />}
        </div>
        <div><RowMenu items={menuItems} label={`Actions for ${time} ${tour.title}`} /></div>
      </div>
      {noteEditor}
    </div>
  );
};

export default BookingRow;
