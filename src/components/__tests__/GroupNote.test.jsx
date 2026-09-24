/**
 * Step 6.13: a note on a merged group. Internal (owner + Sudesh), edited inline in the group's
 * Notes cell, saved as a write under the 4.8 rules.
 */
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  tourGroupsAPI: { update: vi.fn(), unmerge: vi.fn(), dissolve: vi.fn() },
  downloadParticipantsPdf: vi.fn(),
}));

import { tourGroupsAPI } from '../../services/mysqlDB';
import { WRITE_UNKNOWN_MESSAGE, timeoutFor, mayAutoRetry, WRITE_TIMEOUT_MS } from '../../services/netPolicy';
import GroupNote, { cleanGroupNote } from '../GroupNote';
import TourGroup from '../TourGroup';
import TourGroupCardMobile from '../TourGroupCardMobile';

const tour = (id, extra = {}) => ({
  id, language: 'English', participants: 2, cancelled: 0, customer_name: `Guest ${id}`,
  title: 'Uffizi Gallery Small Group Guided Tour with Tickets', date: '2026-09-25', time: '14:30:00', ...extra,
});
const group = (extra = {}) => ({
  id: 77, group_date: '2026-09-25', group_time: '14:30:00', display_name: 'Uffizi Gallery Small Group Guided Tour with Tickets',
  total_pax: 4, max_pax: 9, is_manual_merge: false, guide_id: null, guide_name: null, notes: null,
  tours: [tour(1), tour(2)], ...extra,
});

describe('GroupNote (step 6.13)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('adds a note: dashed button -> labelled editor -> yellow box', async () => {
    tourGroupsAPI.update.mockResolvedValue({ success: true });
    render(<GroupNote groupId={77} note={null} />);
    fireEvent.click(screen.getByRole('button', { name: /add note for this group/i }));
    const box = screen.getByLabelText('Note for this group');
    expect(box.tagName).toBe('TEXTAREA');
    expect(box.getAttribute('rows')).toBe('3');
    fireEvent.change(box, { target: { value: '  Meet at Loggia dei Lanzi 9:15  ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(tourGroupsAPI.update).toHaveBeenCalledWith(77, { notes: 'Meet at Loggia dei Lanzi 9:15' }));
    const saved = await screen.findByRole('button', { name: 'Edit group note' });
    expect(saved.getAttribute('title')).toBe('Meet at Loggia dei Lanzi 9:15');
    expect(saved.className).toContain('bg-[#fff8e8]');
    expect(saved.querySelector('.line-clamp-2')).toBeTruthy();
  });

  it('edits an existing note, and saving empty text removes it', async () => {
    tourGroupsAPI.update.mockResolvedValue({ success: true });
    render(<GroupNote groupId={77} note="One guest uses a wheelchair" />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit group note' }));
    const box = screen.getByLabelText('Note for this group');
    expect(box.value).toBe('One guest uses a wheelchair');
    fireEvent.change(box, { target: { value: '   ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(tourGroupsAPI.update).toHaveBeenCalledWith(77, { notes: null }));
    expect(await screen.findByRole('button', { name: /add note for this group/i })).toBeTruthy();
  });

  it('Esc cancels without saving; Ctrl+Enter and Cmd+Enter save', async () => {
    tourGroupsAPI.update.mockResolvedValue({ success: true });
    render(<GroupNote groupId={77} note="Old" />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit group note' }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Changed' } });
    fireEvent.keyDown(screen.getByLabelText('Note for this group'), { key: 'Escape' });
    expect(tourGroupsAPI.update).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Edit group note' }).getAttribute('title')).toBe('Old');

    fireEvent.click(screen.getByRole('button', { name: 'Edit group note' }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Via ctrl' } });
    fireEvent.keyDown(screen.getByLabelText('Note for this group'), { key: 'Enter', ctrlKey: true });
    await waitFor(() => expect(tourGroupsAPI.update).toHaveBeenCalledWith(77, { notes: 'Via ctrl' }));

    fireEvent.click(await screen.findByRole('button', { name: 'Edit group note' }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Via cmd' } });
    fireEvent.keyDown(screen.getByLabelText('Note for this group'), { key: 'Enter', metaKey: true });
    await waitFor(() => expect(tourGroupsAPI.update).toHaveBeenLastCalledWith(77, { notes: 'Via cmd' }));
  });

  it('Save is disabled while in flight - a double tap sends one request', async () => {
    let resolve;
    tourGroupsAPI.update.mockReturnValue(new Promise((r) => { resolve = r; }));
    render(<GroupNote groupId={77} note={null} />);
    fireEvent.click(screen.getByRole('button', { name: /add note for this group/i }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Once' } });
    const saveBtn = screen.getByRole('button', { name: 'Save' });
    fireEvent.click(saveBtn);
    fireEvent.click(saveBtn);
    expect(screen.getByRole('button', { name: /saving/i }).disabled).toBe(true);
    expect(tourGroupsAPI.update).toHaveBeenCalledTimes(1);
    await act(async () => { resolve({ success: true }); });
  });

  it('a timed-out save is not retried, says the outcome is unknown and keeps the text', async () => {
    const err = new Error('timeout'); err.outcomeUnknown = true;
    tourGroupsAPI.update.mockRejectedValue(err);
    render(<GroupNote groupId={77} note={null} />);
    fireEvent.click(screen.getByRole('button', { name: /add note for this group/i }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Meet at 9:15' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect((await screen.findByRole('alert')).textContent).toBe(WRITE_UNKNOWN_MESSAGE);
    expect(tourGroupsAPI.update).toHaveBeenCalledTimes(1);
    expect(screen.getByLabelText('Note for this group').value).toBe('Meet at 9:15');
    expect(screen.getByRole('button', { name: 'Save' }).disabled).toBe(false);
  });

  it('a plain failure keeps the text too, with its own message', async () => {
    tourGroupsAPI.update.mockRejectedValue(new Error('500'));
    render(<GroupNote groupId={77} note={null} />);
    fireEvent.click(screen.getByRole('button', { name: /add note for this group/i }));
    fireEvent.change(screen.getByLabelText('Note for this group'), { target: { value: 'Keep me' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    expect((await screen.findByRole('alert')).textContent).toMatch(/could not save the note/i);
    expect(screen.getByLabelText('Note for this group').value).toBe('Keep me');
  });

  it('the save is a write under the 4.8 policy: 30 s timeout, never auto-retried', () => {
    expect(timeoutFor('put', '/api/tour-groups.php/77')).toBe(WRITE_TIMEOUT_MS);
    expect(WRITE_TIMEOUT_MS).toBe(30000);
    expect(mayAutoRetry('put', '/api/tour-groups.php/77')).toBe(false);
  });

  it('cleanGroupNote trims, normalises line ends, empty -> null', () => {
    expect(cleanGroupNote('  a\r\nb  ')).toBe('a\nb');
    expect(cleanGroupNote('   ')).toBeNull();
    expect(cleanGroupNote(null)).toBeNull();
  });
});

describe('group note inside the group row / card (step 6.13)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('desktop: clicking the note does not expand the group; the row still does', () => {
    render(<TourGroup group={group({ notes: 'Meet at Loggia 9:15' })} guides={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit group note' }));
    expect(screen.queryByText('Customer')).toBeNull(); // expanded table header absent
    expect(screen.getByLabelText('Note for this group')).toBeTruthy();
    fireEvent.click(screen.getByLabelText('Note for this group'));
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByText('Customer')).toBeNull();
    fireEvent.click(screen.getByText('14:30'));
    expect(screen.getByText('Customer')).toBeTruthy();
  });

  it('desktop: an empty group shows the dashed add button, 44px tall', () => {
    render(<TourGroup group={group()} guides={[]} />);
    const add = screen.getByRole('button', { name: /add note for this group/i });
    expect(add.className).toContain('border-dashed');
    expect(add.className).toContain('min-h-[44px]');
    fireEvent.click(add);
    expect(screen.queryByText('Customer')).toBeNull();
  });

  it('phone card: the note sits full width and a tap on it does not expand the card', () => {
    render(<TourGroupCardMobile group={group({ notes: 'One guest uses a wheelchair' })} guides={[]} />);
    const noteBtn = screen.getByRole('button', { name: 'Edit group note' });
    expect(noteBtn.className).toContain('w-full');
    expect(screen.getByText('Tap to expand')).toBeTruthy();
    fireEvent.click(noteBtn);
    expect(screen.getByText('Tap to expand')).toBeTruthy();
  });
});
