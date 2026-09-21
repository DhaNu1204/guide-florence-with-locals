/**
 * Step 6.8: the participant-list button.
 *
 * It must ask for the departure it is attached to - a group as one sheet, a loose tour as its
 * own - and it must never widen the row it sits in, because the owner uses this page on his
 * phone at a meeting point.
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../services/mysqlDB', () => ({
  downloadParticipantsPdf: vi.fn(() => Promise.resolve('uffizi-09-30-2026-1000-participants.pdf')),
}));

import { downloadParticipantsPdf } from '../../services/mysqlDB';
import ParticipantsButton from '../ParticipantsButton';

describe('ParticipantsButton (step 6.8)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('asks for the group when it sits on a group', async () => {
    render(<ParticipantsButton unit="g1508270" />);
    fireEvent.click(screen.getByTestId('participants-pdf-g1508270'));
    await waitFor(() => expect(downloadParticipantsPdf).toHaveBeenCalledWith('g1508270'));
    expect(downloadParticipantsPdf).toHaveBeenCalledTimes(1);
  });

  it('asks for the tour when it sits on a loose booking', async () => {
    render(<ParticipantsButton unit="t6054" />);
    fireEvent.click(screen.getByTestId('participants-pdf-t6054'));
    await waitFor(() => expect(downloadParticipantsPdf).toHaveBeenCalledWith('t6054'));
  });

  it('says what it does without taking any width', () => {
    render(<ParticipantsButton unit="t1" />);
    const btn = screen.getByTestId('participants-pdf-t1');
    // no visible text: the label lives in title/aria-label so the row cannot grow
    expect(btn.textContent.trim()).toBe('');
    expect(btn.getAttribute('title')).toMatch(/Participant list/i);
    expect(btn.getAttribute('aria-label')).toMatch(/Participant list/i);
  });

  it('reports a failure instead of failing silently', async () => {
    downloadParticipantsPdf.mockRejectedValueOnce({ response: { data: { error: 'Departure not found' } } });
    const onError = vi.fn();
    render(<ParticipantsButton unit="t99" onError={onError} />);
    fireEvent.click(screen.getByTestId('participants-pdf-t99'));
    await waitFor(() => expect(onError).toHaveBeenCalledWith('Departure not found'));
  });

  it('cannot be fired twice while it is working', async () => {
    let release;
    downloadParticipantsPdf.mockReturnValueOnce(new Promise((r) => { release = r; }));
    render(<ParticipantsButton unit="t7" />);
    const btn = screen.getByTestId('participants-pdf-t7');
    fireEvent.click(btn);
    fireEvent.click(btn);
    expect(downloadParticipantsPdf).toHaveBeenCalledTimes(1);
    release('x');
  });
});
