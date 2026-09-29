import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const askAssistant = vi.fn();
vi.mock('../../services/assistantService', () => ({
  askAssistant: (...a) => askAssistant(...a),
  listConversations: vi.fn(async () => [{ id: 3, title: 'how many tours today', last_at: '2026-09-29T10:00:00Z', messages: 2 }]),
  getConversation: vi.fn(async () => []),
  getAssistantStatus: vi.fn(),
}));

import AssistantPanel from '../assistant/AssistantPanel';
import { __resetAssistantStore } from '../assistant/assistantStore';

const setOnline = (v) => Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => v });
const renderPanel = (status = { enabled: true, can_see_money: false }) => render(
  <MemoryRouter><AssistantPanel status={status} userName="sudesh" isDesktop hidden={false} onClose={() => {}} /></MemoryRouter>,
);

describe('AssistantPanel (step 7.4)', () => {
  beforeEach(() => { __resetAssistantStore(); askAssistant.mockReset(); setOnline(true); });
  afterEach(() => setOnline(true));

  it('empty state: greeting, 3 suggestions without money, recent list', async () => {
    renderPanel();
    expect(screen.getByText(/Hi Sudesh/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: "Today's tours and guests" })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: "Today's income" })).toBeNull();
    expect(await screen.findByText('how many tours today')).toBeInTheDocument();
  });

  it('the owner also gets the income suggestion', () => {
    renderPanel({ enabled: true, can_see_money: true });
    expect(screen.getByRole('button', { name: "Today's income" })).toBeInTheDocument();
  });

  it('assistant text is plain text: a <script> in the answer is shown, not run', async () => {
    askAssistant.mockResolvedValue({ ok: true, data: { conversation_id: 9, text: 'Line 1\n<script>alert(1)</script>', blocks: [] } });
    const { container } = renderPanel();
    fireEvent.click(screen.getByRole('button', { name: "Today's tours and guests" }));
    expect(await screen.findByText(/<script>alert\(1\)<\/script>/)).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
  });

  it('Enter sends, the box empties on success', async () => {
    askAssistant.mockResolvedValue({ ok: true, data: { conversation_id: 9, text: 'Ok', blocks: [] } });
    renderPanel();
    const box = screen.getByLabelText('Message');
    fireEvent.change(box, { target: { value: 'who is free tomorrow' } });
    fireEvent.keyDown(box, { key: 'Enter' });
    await waitFor(() => expect(askAssistant).toHaveBeenCalledWith('who is free tomorrow', null));
    await waitFor(() => expect(box.value).toBe(''));
  });

  it('an error shows one line and keeps the typed text', async () => {
    askAssistant.mockResolvedValue({ ok: false, error: { kind: 'http', status: 429, code: 'daily_cap_reached' } });
    renderPanel();
    const box = screen.getByLabelText('Message');
    fireEvent.change(box, { target: { value: 'today income' } });
    fireEvent.click(screen.getByRole('button', { name: 'Send' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/midnight/);
    expect(box.value).toBe('today income');
  });

  it('offline: banner, message waits, sent once when the connection returns', async () => {
    setOnline(false);
    askAssistant.mockResolvedValue({ ok: true, data: { conversation_id: 9, text: 'Back online answer', blocks: [] } });
    renderPanel();
    expect(screen.getByText(/Offline — the assistant needs a connection/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: "Today's tours" }).getAttribute('href')).toMatch(/^\/tours\?date=\d{4}-\d{2}-\d{2}$/);
    const box = screen.getByLabelText('Message');
    fireEvent.change(box, { target: { value: 'unassigned this week' } });
    fireEvent.keyDown(box, { key: 'Enter' });
    expect(await screen.findByText('waiting…')).toBeInTheDocument();
    expect(askAssistant).not.toHaveBeenCalled();
    setOnline(true);
    await act(async () => { window.dispatchEvent(new Event('online')); });
    expect(await screen.findByText('Back online answer')).toBeInTheDocument();
    expect(askAssistant).toHaveBeenCalledTimes(1);
    expect(askAssistant).toHaveBeenCalledWith('unassigned this week', null);
  });
});
