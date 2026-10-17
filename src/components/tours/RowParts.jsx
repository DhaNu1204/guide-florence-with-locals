import React from 'react';
import { FiFileText, FiPlus } from 'react-icons/fi';
import Avatar from '../base/Avatar';
import Pill from '../base/Pill';
import { languageCode, isEnglish } from './rowText';

// Step 9.2: the cells every Tours row shares (docs/DESIGN.md rule 3 - one row grammar).

export const COLUMN_LABELS = ['Time', 'Tour', 'Channel', 'Lang', 'Guests', 'Guide', 'Status', '', ''];
export const STACK_DATE_PX = 44;
export const STACK_COLUMNS_PX = 32;
export const STACK_SECTION_PX = 36;

const stop = (e) => e.stopPropagation();

/** Column titles, pinned under the day header (step 6.23 stack: day 44 · columns 32 · section 36). */
export const ColumnHeader = () => (
  <div
    role="row"
    data-testid="pinned-column-head"
    className="fwl-tours-grid sticky z-20 h-8 bg-surface border-b border-line text-[11px] font-medium uppercase tracking-wider text-ink-3"
    style={{ top: STACK_DATE_PX }}
  >
    {COLUMN_LABELS.map((l, i) => (
      <div key={i} role="columnheader" className="truncate">{l || <span className="sr-only">{i === 7 ? 'Note' : 'Actions'}</span>}</div>
    ))}
  </div>
);

/** "Morning · 6:00 - 11:59" with its counts, pinned under the column titles. */
export const SectionBar = ({ period, counts }) => (
  <div
    className="sticky z-20 flex items-center justify-between gap-3 px-4 bg-section border-b border-line"
    style={{ top: STACK_DATE_PX + STACK_COLUMNS_PX, height: STACK_SECTION_PX }}
  >
    <h4 className="text-[13px] font-semibold text-ink">{period}</h4>
    <div className="text-[13px] text-ink-2 tabular-nums">{counts}</div>
  </div>
);

/** "EN" in plain ink-2; any other language an info pill; none = a dash. */
export const LangCell = ({ language, extra = 0 }) => {
  const code = languageCode(language);
  if (!code) return <span className="text-[13px] text-ink-3" title="Unknown" data-testid="tour-language-chip">—</span>;
  const label = extra > 0 ? `${code}+${extra}` : code;
  if (isEnglish(language) && !extra) {
    return <span className="text-[13px] text-ink-2" title={language} data-testid="tour-language-chip">{label}</span>;
  }
  return <Pill tone="lang" title={language} data-testid="tour-language-chip">{label}</Pill>;
};

/** Avatar + name (click edits, as before), or the dashed "+ Assign guide" button when unassigned. */
export const GuideCell = ({ name, onEdit, children }) => {
  if (children) return <div onClick={stop} className="min-w-0">{children}</div>;
  if (!name) {
    return (
      <button
        type="button"
        onClick={(e) => { stop(e); onEdit(); }}
        className="inline-flex items-center gap-1 h-8 px-2 rounded-lg border border-dashed border-danger-border text-[13px] font-medium text-danger hover:bg-danger-strip whitespace-nowrap"
      >
        <FiPlus aria-hidden="true" className="w-3.5 h-3.5" /> Assign guide
      </button>
    );
  }
  return (
    <button
      type="button"
      onClick={(e) => { stop(e); onEdit(); }}
      title={`Change guide (${name})`}
      className="flex items-center gap-2 min-w-0 max-w-full h-8 px-1 -mx-1 rounded-lg hover:bg-section text-left"
    >
      <Avatar name={name} />
      <span className="truncate text-sm text-ink">{name}</span>
    </button>
  );
};

/** The note column: filled in the note colour when a note exists, faint otherwise. */
export const NoteButton = ({ hasNote, onClick, label }) => (
  <button
    type="button"
    onClick={(e) => { stop(e); onClick(); }}
    aria-label={label || (hasNote ? 'Edit note' : 'Add note')}
    title={label || (hasNote ? 'Edit note' : 'Add note')}
    className={`inline-flex items-center justify-center w-8 h-8 rounded-lg transition-colors
      ${hasNote ? 'bg-note-bg text-note-ink hover:brightness-95' : 'text-ink-3/50 hover:text-ink-2 hover:bg-section'}`}
  >
    <FiFileText aria-hidden="true" className="w-4 h-4" />
  </button>
);

/** The inline guide picker the page always had (select + Save + Cancel), sized for the cell. */
export const GuideEditor = ({ value, options, onChange, onSave, onCancel, saving, noneLabel = 'Unassigned' }) => (
  <div className="flex items-center gap-1" onClick={stop}>
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      aria-label="Guide"
      className="min-w-0 flex-1 h-8 px-1.5 border border-line rounded-lg text-[13px] bg-surface focus:outline-none focus:ring-2 focus:ring-accent"
    >
      <option value="">{noneLabel}</option>
      {options.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
    </select>
    <button type="button" onClick={onSave} disabled={saving} title="Save guide assignment" aria-label="Save guide assignment"
      className="h-8 px-2 rounded-lg bg-accent text-white text-xs font-medium hover:bg-accent-hover disabled:opacity-50">Save</button>
    <button type="button" onClick={onCancel} title="Cancel" aria-label="Cancel"
      className="h-8 w-7 rounded-lg text-ink-2 hover:bg-section">&times;</button>
  </div>
);

/** A line under a row (note editor, guide request status ...) that spans the grid. */
export const RowExtra = ({ children, className = '' }) => (
  <div className={`px-4 pb-3 pl-[84px] ${className}`} onClick={stop}>{children}</div>
);
