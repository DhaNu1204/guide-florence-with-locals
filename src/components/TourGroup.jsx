import React, { useEffect, useState } from 'react';
import { pickableGuides } from '../utils/guidePicker'; // step 7.2b: inactive guides take no new work
import { FiChevronDown, FiChevronRight, FiScissors, FiTrash2, FiFileText, FiInfo } from 'react-icons/fi';
import { groupMemberLanguages } from '../utils/groupLanguages';
import { useGroupNote, GroupNoteEditor } from './GroupNote';
import GroupCountsAs from './GroupCountsAs'; // step 6.17
import BookedTimeChip from './BookedTimeChip';
import { groupDepartureTime } from '../utils/groupTime';
import { CancelledToggle, toggleId } from './CancelledCompact'; // step 6.23
import { tourGroupsAPI, downloadParticipantsPdf } from '../services/mysqlDB';
import { isVasari, countVasariPax } from '../utils/vasari';
import { getMaxPax, countActivePax, countActiveBookings, getPaxBreakdown, aggregateBreakdown, formatBreakdown, tourCategory } from '../utils/tourCapacity';
import Pill from './base/Pill';
import IconButton from './base/IconButton';
import { LangCell, GuideCell, GuideEditor, NoteButton, RowExtra } from './tours/RowParts';
import RowMenu from './tours/RowMenu';
import CancelledLine from './tours/CancelledLine';
import { shortChannel, leadGuest } from './tours/rowText';

// Step 9.2: the desktop group row (docs/DESIGN.md rule 3). Same nine columns as a booking, 56 px;
// a click (or the chevron) shows the member rows, 40 px, indented, on the muted background.
// Everything the old group card did is still here: assign the group guide (guide cell), the group
// note (note icon, editor under the row), participant sheet + dissolve (⋯ menu), "Counts as"
// (expanded), per member: booking details (click), remove from group (member ⋯), cancelled lines.
const TourGroup = ({
  group,
  guides,
  onRefresh,
  onError,
  onSuccess,
  onTourClick,
  openGuideNonce, // step 9.2: the attention strip's "Assign guide" opens this group's guide picker
  // Drag-and-drop props
  draggable,
  onDragStart,
  onDragOver,
  onDragLeave,
  onDrop,
  isDragOver,
}) => {
  const [expanded, setExpanded] = useState(false);
  const memberLanguages = groupMemberLanguages(group); // step 6.12
  const mixedLanguages = memberLanguages.length > 1;
  const [editingGuide, setEditingGuide] = useState(false);
  const [selectedGuideId, setSelectedGuideId] = useState(group.guide_id || '');
  const [savingGuide, setSavingGuide] = useState(false);
  const [actionLoading, setActionLoading] = useState({});
  // Step 6.23: cancelled members are one grey line until opened ({tourId: true}).
  const [openCancelled, setOpenCancelled] = useState({});
  const note = useGroupNote(group.id, group.notes); // step 6.13

  useEffect(() => {
    if (!openGuideNonce) return;
    setSelectedGuideId(group.guide_id || '');
    setEditingGuide(true);
  }, [openGuideNonce]); // eslint-disable-line react-hooks/exhaustive-deps

  // PAX / booking counts EXCLUDE cancelled bookings (cancelled still shown in the
  // expanded list, but never counted toward PAX, the badge, or FULL).
  const totalPax = group.tours ? countActivePax(group.tours) : (group.total_pax || 0);
  const bookingCount = group.tours ? countActiveBookings(group.tours) : (group.booking_count || 0);
  const guideName = group.assigned_guide_name || group.guide_name || guides.find(g => g.id == group.guide_id)?.name || null;
  const maxPax = getMaxPax(group.display_name);
  const active = (group.tours || []).filter((t) => t && !t.cancelled);
  // The unassigned report's rule: no group guide and no member guide.
  const needsGuide = active.length > 0 && !group.guide_id && !active.some((t) => t.guide_id);

  const languages = [...new Set((group.tours || []).map(t => t.language).filter(Boolean))];
  const channels = [...new Set(active.map((t) => shortChannel(t.booking_channel)))];

  // Category breakdown over NON-cancelled bookings; >1 distinct category means
  // the merged group mixes tour types (matters for guide pay), so flag it.
  const categoryCounts = active.reduce((acc, t) => {
    const c = tourCategory(t.rule_title || t.title); // step 6.22: rule title (linked product = its original)
    acc[c] = (acc[c] || 0) + 1;
    return acc;
  }, {});
  const isMixed = Object.keys(categoryCounts).length > 1;
  const mixedTooltip = Object.entries(categoryCounts).map(([c, n]) => `${c} x${n}`).join(', ');

  const handleGuideUpdate = async () => {
    setSavingGuide(true);
    try {
      await tourGroupsAPI.update(group.id, {
        guide_id: selectedGuideId || null
      });
      const name = guides.find(g => g.id == selectedGuideId)?.name;
      onSuccess?.(name ? `Guide "${name}" assigned to group` : 'Guide unassigned from group');
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

  // Step 6.8: one sheet for the whole group - it is one departure at the museum door.
  const handleSheet = async () => {
    try {
      await downloadParticipantsPdf(`g${group.id}`);
    } catch (err) {
      onError?.(err?.response?.data?.error || 'Could not build the participant list');
    }
  };

  const groupTime = groupDepartureTime(group); // step 6.16: the chosen time of a manual merge, else group_time
  const breakdown = formatBreakdown(aggregateBreakdown(group.tours || []));
  const vasariPax = countVasariPax(group.tours);
  const meta = [`${bookingCount} ${bookingCount === 1 ? 'booking' : 'bookings'}`];
  if (breakdown) meta.push(breakdown);

  return (
    <div
      className={`border-b border-line-soft last:border-b-0 transition-colors ${isDragOver ? 'bg-accent-soft ring-2 ring-inset ring-accent/40' : ''}`}
      draggable={draggable}
      onDragStart={onDragStart}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
      data-group-id={group.id}
    >
      <div role="row" className="fwl-tours-grid min-h-14 py-2 cursor-pointer hover:bg-muted-row" onClick={() => setExpanded(!expanded)}>
        <div className="text-sm font-semibold text-ink tabular-nums">{groupTime}</div>
        <div className="flex items-center gap-1 min-w-0 -ml-2">
          <IconButton
            icon={expanded ? FiChevronDown : FiChevronRight}
            label={expanded ? 'Hide bookings in this group' : 'Show bookings in this group'}
            aria-expanded={expanded}
            onClick={(e) => { e.stopPropagation(); setExpanded(!expanded); }}
            className="flex-shrink-0 !w-6 !h-6"
          />
          <div className="min-w-0">
            <div className="truncate text-sm font-medium text-ink" title={group.display_name}>{group.display_name}</div>
            <div className="truncate text-[12.5px] text-ink-2" title={note.saved || undefined}>
              {meta.join(' · ')}
              {vasariPax > 0 && <> · <span data-testid="vasari-group-chip" title="Booked on a Vasari Corridor rate (Uffizi + Vasari Corridor)">Vasari · {vasariPax} PAX</span></>}
              {note.saved && <> · <span className="text-note-ink">Note: “{note.saved}”</span></>}
            </div>
          </div>
        </div>
        <div className="truncate text-[13px] text-ink-2">{channels.length > 1 ? 'Mixed' : (channels[0] || shortChannel(''))}</div>
        <div><LangCell language={languages[0]} extra={Math.max(0, languages.length - 1)} /></div>
        <div className={`text-sm font-semibold tabular-nums ${totalPax >= maxPax ? 'text-danger' : 'text-ink'}`} data-testid={`group-guests-${group.id}`}>
          {totalPax} / {maxPax}
        </div>
        <GuideCell name={guideName} onEdit={() => { setSelectedGuideId(group.guide_id || ''); setEditingGuide(true); }}>
          {editingGuide && (
            <GuideEditor
              value={selectedGuideId}
              options={pickableGuides(guides, group.guide_id)}
              onChange={setSelectedGuideId}
              onSave={handleGuideUpdate}
              onCancel={() => { setEditingGuide(false); setSelectedGuideId(group.guide_id || ''); }}
              saving={savingGuide}
              noneLabel="None"
            />
          )}
        </GuideCell>
        <div className="flex flex-wrap gap-1 min-w-0">
          {needsGuide && <Pill tone="need">Needs guide</Pill>}
          {totalPax >= maxPax && <Pill tone="full">Full</Pill>}
          {group.is_manual_merge ? <Pill tone="manual">Manual group</Pill> : null}
          {/* Step 6.12 / 6.20: one guide speaks one language - a mixed group never passes silently. */}
          {mixedLanguages && (
            <Pill tone="resched" data-testid="mixed-languages-chip" title={`Languages in this group: ${memberLanguages.join(', ')}`}>
              Mixed languages
            </Pill>
          )}
          {isMixed && <Pill tone="resched" title={mixedTooltip}>Mixed</Pill>}
        </div>
        <div>
          <NoteButton
            hasNote={Boolean(note.saved)}
            label={note.saved ? 'Edit group note' : 'Add note for this group'}
            onClick={() => (note.editing ? note.cancel() : note.startEdit())}
          />
        </div>
        <div>
          <RowMenu
            label={`Actions for group ${groupTime} ${group.display_name}`}
            items={[
              { label: 'Participant list (PDF)', icon: FiFileText, onClick: handleSheet, testId: `participants-pdf-g${group.id}` },
              { label: 'Dissolve group', icon: FiTrash2, onClick: handleDissolve, danger: true, disabled: actionLoading.dissolve },
            ]}
          />
        </div>
      </div>

      {note.editing && (
        <RowExtra>
          <div className="max-w-xl" data-testid={`group-note-${group.id}`} onKeyDown={(e) => e.stopPropagation()}
            draggable={false} onDragStart={(e) => { e.preventDefault(); e.stopPropagation(); }}>
            <GroupNoteEditor note={note} />
          </div>
        </RowExtra>
      )}

      {expanded && (
        <div className="bg-muted-row border-t border-line-soft">
          {/* Step 6.17: what a mixed merge counts as (P&L owner only) */}
          <GroupCountsAs group={group} onRefresh={onRefresh} onSuccess={onSuccess} onError={onError}
            className="px-4 py-2 pl-[96px] border-b border-line-soft" />
          {(group.tours || []).map((tour) => (tour.cancelled && !openCancelled[tour.id]) ? (
            <CancelledLine
              key={tour.id}
              tour={tour}
              member
              language={tour.language}
              pax={tour.participants || 1}
              guideLabel="Group guide"
              onToggle={() => setOpenCancelled(prev => toggleId(prev, tour.id))}
            />
          ) : (
            <div
              key={tour.id}
              role="row"
              data-testid={`group-member-${tour.id}`}
              className="fwl-tours-grid h-10 border-b border-line-soft last:border-b-0 cursor-pointer hover:bg-section/60"
              onClick={() => onTourClick?.(tour)}
            >
              <div />
              <div className="flex items-center gap-2 min-w-0 pl-7">
                <span className={`truncate text-[13px] text-ink ${tour.cancelled ? 'line-through text-canc-ink' : ''}`}>
                  {leadGuest(tour) || tour.customer_name || 'N/A'}
                </span>
                {tour.bokun_confirmation_code && (
                  <span className="text-xs font-mono text-ink-3 whitespace-nowrap">{tour.bokun_confirmation_code}</span>
                )}
                {Number(tour.is_private) === 1 && <span className="text-xs text-ink-2">Private</span>}
                {isVasari(tour) && <span className="text-xs text-ink-2" data-testid="vasari-chip">Vasari</span>}
                <BookedTimeChip tour={tour} group={group} />
              </div>
              <div className="truncate text-[13px] text-ink-2" title={tour.booking_channel || 'Direct'}>{tour.booking_channel ? shortChannel(tour.booking_channel) : 'Direct'}</div>
              <div><LangCell language={tour.language} /></div>
              <div className="text-[13px] font-medium text-ink tabular-nums" title={formatBreakdown(getPaxBreakdown(tour)) || undefined}>{tour.participants || 1}</div>
              <div className="text-[13px] text-ink-2">Group guide</div>
              <div className="flex gap-1 min-w-0">
                {isMixed && <span className="truncate text-xs text-ink-2">{tourCategory(tour.rule_title || tour.title)}</span>}
                {tour.cancelled && <Pill tone="cancelled">Cancelled</Pill>}
              </div>
              <div>
                {tour.cancelled && (
                  <CancelledToggle expanded onToggle={() => setOpenCancelled(prev => toggleId(prev, tour.id))} />
                )}
              </div>
              <div>
                <RowMenu
                  label={`Actions for ${leadGuest(tour) || tour.customer_name || 'booking'}`}
                  items={[
                    { label: 'Booking details', icon: FiInfo, onClick: () => onTourClick?.(tour) },
                    { label: 'Remove from group', icon: FiScissors, onClick: () => handleUnmerge(tour.id), disabled: actionLoading[`unmerge_${tour.id}`], testId: `unmerge-${tour.id}` },
                  ]}
                />
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
};

export default TourGroup;
