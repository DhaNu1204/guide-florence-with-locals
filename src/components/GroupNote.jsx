import React, { useEffect, useId, useRef, useState } from 'react';
import { FiPlus, FiFileText } from 'react-icons/fi';
import { tourGroupsAPI } from '../services/mysqlDB';
import { writeFailureMessage } from '../services/netPolicy';

// Step 6.13: one note per merged group (tour_groups.notes) - internal, for the owner and Sudesh,
// never sent to a guide. Three states in the group's Notes cell: a quiet "Add note for this group"
// button, an inline editor, and the saved note as a pale yellow box (click to edit).
// Saving is a write (4.8): 30 s timeout, never retried, Save disabled while in flight, and a
// failed save keeps the typed text.

// '' / whitespace -> null, "\r\n" -> "\n": what the server stores.
export function cleanGroupNote(text) {
  const t = String(text ?? '').replace(/\r\n?/g, '\n').trim();
  return t === '' ? null : t;
}

const GroupNote = ({ groupId, note, onSaved, variant = 'row' }) => {
  const [saved, setSaved] = useState(cleanGroupNote(note));
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const savingRef = useRef(false);
  const textareaRef = useRef(null);
  const fieldId = useId();

  // A list reload brings the server's value - only when that value itself changes, and never
  // over what is being typed.
  const editingRef = useRef(false);
  editingRef.current = editing;
  useEffect(() => {
    if (!editingRef.current) setSaved(cleanGroupNote(note));
  }, [note]);

  useEffect(() => {
    if (editing && textareaRef.current) textareaRef.current.focus();
  }, [editing]);

  const startEdit = () => {
    setDraft(saved || '');
    setError(null);
    setEditing(true);
  };

  const cancel = () => {
    if (savingRef.current) return;
    setEditing(false);
    setError(null);
  };

  const save = async () => {
    if (savingRef.current) return; // a double tap never sends it twice
    savingRef.current = true;
    setSaving(true);
    setError(null);
    const value = cleanGroupNote(draft);
    try {
      const res = await tourGroupsAPI.update(groupId, { notes: value });
      const fromServer = res && res.group && Object.prototype.hasOwnProperty.call(res.group, 'notes')
        ? cleanGroupNote(res.group.notes) : value;
      setSaved(fromServer);
      setEditing(false);
      onSaved?.(fromServer);
    } catch (err) {
      setError(writeFailureMessage(err, 'Could not save the note. Please try again.'));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  };

  const onKeyDown = (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      cancel();
    } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      save();
    }
  };

  // Clicks, taps and keys inside the note never reach the row (no expand/collapse, no drag).
  const stop = (e) => e.stopPropagation();
  const wrapperProps = {
    onClick: stop,
    onKeyDown: stop,
    onDragStart: (e) => { e.preventDefault(); e.stopPropagation(); },
    draggable: false,
    className: variant === 'card' ? 'w-full mt-2' : 'w-full',
    'data-testid': `group-note-${groupId}`,
  };

  if (editing) {
    return (
      <div {...wrapperProps}>
        <label htmlFor={fieldId} className="block text-xs font-medium text-stone-600 mb-1">
          Note for this group
        </label>
        <textarea
          id={fieldId}
          ref={textareaRef}
          rows={3}
          maxLength={2000}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={onKeyDown}
          className="w-full px-2 py-1.5 border border-stone-300 rounded-tuscan text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 resize-y"
          placeholder="e.g. Meet at Loggia dei Lanzi 9:15"
        />
        {error && (
          <p role="alert" className="mt-1 text-xs text-terracotta-700">{error}</p>
        )}
        <div className="mt-1.5 flex gap-2">
          <button
            type="button"
            onClick={save}
            disabled={saving}
            className="min-h-[44px] px-3 rounded-tuscan bg-terracotta-600 text-white text-sm font-medium hover:bg-terracotta-700 disabled:opacity-50 touch-manipulation"
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
          <button
            type="button"
            onClick={cancel}
            disabled={saving}
            className="min-h-[44px] px-3 rounded-tuscan border border-stone-300 text-stone-700 text-sm hover:bg-stone-100 disabled:opacity-50 touch-manipulation"
          >
            Cancel
          </button>
        </div>
      </div>
    );
  }

  if (!saved) {
    return (
      <div {...wrapperProps}>
        <button
          type="button"
          onClick={startEdit}
          className="w-full min-h-[44px] px-2 flex items-center gap-1.5 border border-dashed border-stone-300 rounded-tuscan text-sm text-stone-500 hover:text-stone-700 hover:border-stone-400 hover:bg-white/60 touch-manipulation"
        >
          <FiPlus size={14} aria-hidden="true" className="flex-shrink-0" />
          <span className="truncate">Add note for this group</span>
        </button>
      </div>
    );
  }

  return (
    <div {...wrapperProps}>
      <button
        type="button"
        onClick={startEdit}
        aria-label="Edit group note"
        title={saved}
        className="w-full min-h-[44px] px-2 py-1.5 flex items-start gap-1.5 text-left rounded-tuscan border border-[#efdcaa] bg-[#fff8e8] text-stone-800 text-sm hover:border-[#e0c47f] touch-manipulation"
      >
        <FiFileText size={14} aria-hidden="true" className="flex-shrink-0 mt-0.5 text-[#a8862f]" />
        <span className="line-clamp-2 whitespace-pre-line break-words min-w-0">{saved}</span>
      </button>
    </div>
  );
};

export default GroupNote;
