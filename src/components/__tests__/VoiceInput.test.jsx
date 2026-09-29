import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

const askAssistant = vi.fn();
vi.mock('../../services/assistantService', () => ({
  askAssistant: (...a) => askAssistant(...a),
  listConversations: vi.fn(async () => []),
  getConversation: vi.fn(async () => []),
  getAssistantStatus: vi.fn(),
}));

import AssistantPanel from '../assistant/AssistantPanel';
import { __resetAssistantStore } from '../assistant/assistantStore';
import {
  getRecognitionCtor, defaultVoiceLang, loadVoiceLang, saveVoiceLang, speechErrorMessage, appendTranscript,
} from '../../utils/voiceInput';

// A stand-in for the browser's SpeechRecognition: the test drives its events.
let lastRec = null;
class FakeRecognition {
  constructor() { lastRec = this; this.started = false; this.stopped = false; }
  start() { this.started = true; }
  stop() { this.stopped = true; if (this.onend) this.onend(); }
  abort() { this.stopped = true; }
}
const result = (parts) => ({ results: parts.map(([text, isFinal]) => Object.assign([{ transcript: text }], { isFinal })) });

// The shared test setup mocks localStorage without storing; give this file a real in-memory one.
const mem = new Map();
const useMemoryStorage = () => {
  mem.clear();
  window.localStorage.getItem.mockImplementation((k) => (mem.has(k) ? mem.get(k) : null));
  window.localStorage.setItem.mockImplementation((k, v) => { mem.set(k, String(v)); });
};

const renderPanel = () => render(
  <MemoryRouter><AssistantPanel status={{ enabled: true, can_see_money: false }} userName="dhanu" isDesktop hidden={false} onClose={() => {}} /></MemoryRouter>,
);

describe('voice input rules (step 7.6)', () => {
  it('no recognition in the browser -> no constructor', () => {
    expect(getRecognitionCtor({})).toBeNull();
    expect(getRecognitionCtor({ webkitSpeechRecognition: FakeRecognition })).toBe(FakeRecognition);
    expect(getRecognitionCtor({ SpeechRecognition: FakeRecognition })).toBe(FakeRecognition);
  });

  it('default language: English only when the browser says so, else Italian', () => {
    expect(defaultVoiceLang('en-GB')).toBe('en');
    expect(defaultVoiceLang('it-IT')).toBe('it');
    expect(defaultVoiceLang('de-DE')).toBe('it');
    expect(defaultVoiceLang('')).toBe('it');
  });

  it('the choice is remembered per user', () => {
    useMemoryStorage();
    saveVoiceLang('dhanu', 'en');
    saveVoiceLang('sudesh', 'it');
    expect(loadVoiceLang('dhanu', 'it-IT')).toBe('en');
    expect(loadVoiceLang('sudesh', 'en-US')).toBe('it');
    expect(loadVoiceLang('nobody', 'en-US')).toBe('en');
  });

  it('one clear line for permission denied and no speech', () => {
    expect(speechErrorMessage('not-allowed')).toMatch(/^Microphone permission denied/);
    expect(speechErrorMessage('service-not-allowed')).toMatch(/^Microphone permission denied/);
    expect(speechErrorMessage('no-speech')).toMatch(/^No speech heard/);
    expect(speechErrorMessage('aborted')).toBeNull();
  });

  it('heard text goes after what is typed', () => {
    expect(appendTranscript('', 'chi è libero domani')).toBe('chi è libero domani');
    expect(appendTranscript('Tours ', 'tomorrow')).toBe('Tours tomorrow');
    expect(appendTranscript('abc', '  ')).toBe('abc');
  });
});

describe('VoiceInput in the chat (step 7.6)', () => {
  beforeEach(() => {
    __resetAssistantStore();
    askAssistant.mockReset();
    lastRec = null;
    useMemoryStorage();
  });
  afterEach(() => {
    delete window.SpeechRecognition;
    delete window.webkitSpeechRecognition;
  });

  it('is hidden where the browser has no speech recognition', () => {
    renderPanel();
    expect(screen.queryByTestId('voice-mic')).toBeNull();
    expect(screen.queryByTestId('voice-lang')).toBeNull();
    expect(screen.getByLabelText('Message')).toBeInTheDocument();
  });

  it('shows Listening… + live text, puts the result in the box and NEVER sends', () => {
    window.webkitSpeechRecognition = FakeRecognition;
    renderPanel();
    fireEvent.click(screen.getByTestId('voice-mic'));
    expect(lastRec.started).toBe(true);
    expect(lastRec.lang).toBe(navigator.language && /^en/i.test(navigator.language) ? 'en-GB' : 'it-IT');
    expect(lastRec.continuous).toBe(false); // stops on silence
    expect(screen.getByTestId('voice-status')).toHaveTextContent('Listening…');
    act(() => { lastRec.onresult(result([['who is free', false]])); });
    expect(screen.getByTestId('voice-status')).toHaveTextContent('Listening… who is free');
    act(() => { lastRec.onresult(result([['who is free tomorrow', true]])); lastRec.onend(); });
    expect(screen.getByLabelText('Message')).toHaveValue('who is free tomorrow');
    expect(screen.queryByTestId('voice-status')).toBeNull();
    expect(askAssistant).not.toHaveBeenCalled();
  });

  it('a second tap stops listening', () => {
    window.SpeechRecognition = FakeRecognition;
    renderPanel();
    fireEvent.click(screen.getByTestId('voice-mic'));
    expect(screen.getByLabelText('Stop listening')).toBeInTheDocument();
    fireEvent.click(screen.getByTestId('voice-mic'));
    expect(lastRec.stopped).toBe(true);
    expect(screen.getByLabelText('Speak')).toBeInTheDocument();
    expect(askAssistant).not.toHaveBeenCalled();
  });

  it('permission denied -> one clear line, nothing in the box', () => {
    window.webkitSpeechRecognition = FakeRecognition;
    renderPanel();
    fireEvent.click(screen.getByTestId('voice-mic'));
    act(() => { lastRec.onerror({ error: 'not-allowed' }); lastRec.onend(); });
    expect(screen.getByRole('alert')).toHaveTextContent('Microphone permission denied - allow the microphone for this site in your browser settings.');
    expect(screen.getByLabelText('Message')).toHaveValue('');
  });

  it('no speech -> its own line', () => {
    window.webkitSpeechRecognition = FakeRecognition;
    renderPanel();
    fireEvent.click(screen.getByTestId('voice-mic'));
    act(() => { lastRec.onerror({ error: 'no-speech' }); lastRec.onend(); });
    expect(screen.getByRole('alert')).toHaveTextContent('No speech heard - tap the mic and try again.');
  });

  it('IT / EN switch changes the recognition language and is remembered', () => {
    window.webkitSpeechRecognition = FakeRecognition;
    const { unmount } = renderPanel();
    const sw = screen.getByTestId('voice-lang');
    const before = sw.textContent;
    fireEvent.click(sw);
    const after = sw.textContent;
    expect(after).not.toBe(before);
    fireEvent.click(screen.getByTestId('voice-mic'));
    expect(lastRec.lang).toBe(after === 'EN' ? 'en-GB' : 'it-IT');
    unmount();
    __resetAssistantStore();
    renderPanel();
    expect(screen.getByTestId('voice-lang').textContent).toBe(after);
  });
});
