// Step 7.6: the mic button + IT / EN switch in the chat input. Browser speech recognition only
// (SpeechRecognition / webkitSpeechRecognition); renders nothing where the browser has none.
// The heard text goes into the input box through onText - it is NEVER sent from here.
import React, { useEffect, useRef, useState } from 'react';
import {
  getRecognitionCtor, loadVoiceLang, saveVoiceLang, recognitionLocale, speechErrorMessage,
} from '../../utils/voiceInput';

/**
 * @param userName  for the remembered language choice
 * @param disabled  while a question is being sent
 * @param onText    (finalText) => void - put into the input box
 * @param onStatus  ({ listening, transcript, error }) => void - the line above the input
 */
// Inline icons (Feather "mic" / "square"): importing them from react-icons put them in the shared
// icon chunk of the MAIN bundle; drawn here they stay in this lazy chunk.
const svgProps = { width: '1em', height: '1em', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true };
const MicIcon = () => (
  <svg {...svgProps}><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z" /><path d="M19 10v2a7 7 0 0 1-14 0v-2" /><line x1="12" y1="19" x2="12" y2="23" /><line x1="8" y1="23" x2="16" y2="23" /></svg>
);
const StopIcon = () => <svg {...svgProps}><rect x="3" y="3" width="18" height="18" rx="2" ry="2" /></svg>;

export default function VoiceInput({ userName, disabled = false, onText, onStatus }) {
  const Ctor = getRecognitionCtor();
  const [lang, setLang] = useState(() => loadVoiceLang(userName, typeof navigator !== 'undefined' ? navigator.language : ''));
  const [listening, setListening] = useState(false);
  const recRef = useRef(null);
  const report = (st) => { if (onStatus) onStatus(st); };

  useEffect(() => () => { // leaving the chat: stop the mic
    if (recRef.current) { try { recRef.current.abort(); } catch (_) { /* already stopped */ } }
  }, []);

  if (!Ctor) return null;

  const start = () => {
    let rec;
    try { rec = new Ctor(); } catch (_) { report({ listening: false, transcript: '', error: speechErrorMessage('other') }); return; }
    rec.lang = recognitionLocale(lang);
    rec.interimResults = true;
    rec.continuous = false; // stops by itself after a pause
    rec.maxAlternatives = 1;
    let finalText = '';
    let errorShown = false;
    rec.onresult = (e) => {
      let interim = '';
      finalText = '';
      for (let i = 0; i < e.results.length; i++) {
        const r = e.results[i];
        if (r.isFinal) finalText += r[0].transcript; else interim += r[0].transcript;
      }
      report({ listening: true, transcript: (finalText + ' ' + interim).trim(), error: null });
    };
    rec.onerror = (e) => {
      const msg = speechErrorMessage(e && e.error);
      if (msg) { errorShown = true; report({ listening: false, transcript: '', error: msg }); }
    };
    rec.onend = () => {
      recRef.current = null;
      setListening(false);
      if (finalText.trim()) onText && onText(finalText.trim());
      if (!errorShown) report({ listening: false, transcript: '', error: null });
    };
    recRef.current = rec;
    setListening(true);
    report({ listening: true, transcript: '', error: null });
    try { rec.start(); } catch (_) {
      recRef.current = null;
      setListening(false);
      report({ listening: false, transcript: '', error: speechErrorMessage('other') });
    }
  };

  const stop = () => { if (recRef.current) { try { recRef.current.stop(); } catch (_) { /* already stopped */ } } };

  const switchLang = () => {
    const next = lang === 'it' ? 'en' : 'it';
    setLang(next);
    saveVoiceLang(userName, next);
  };

  return (
    <div className="flex flex-shrink-0 items-center gap-1">
      <button
        type="button"
        onClick={switchLang}
        disabled={listening}
        aria-label={`Voice language: ${lang === 'it' ? 'Italian' : 'English'} (tap to switch)`}
        className="min-h-[44px] min-w-[36px] rounded-tuscan px-1 text-xs font-bold text-stone-600 hover:bg-stone-100 disabled:opacity-50 touch-manipulation"
        data-testid="voice-lang"
      >
        {lang.toUpperCase()}
      </button>
      <button
        type="button"
        onClick={listening ? stop : start}
        disabled={disabled && !listening}
        aria-label={listening ? 'Stop listening' : 'Speak'}
        aria-pressed={listening}
        className={`flex min-h-[44px] min-w-[44px] items-center justify-center rounded-tuscan-lg border-2 touch-manipulation disabled:opacity-50 ${
          listening ? 'border-red-400 bg-red-50 text-red-600' : 'border-stone-300 bg-white text-stone-600 hover:bg-stone-50'}`}
        data-testid="voice-mic"
      >
        {listening ? <StopIcon /> : <MicIcon />}
      </button>
    </div>
  );
}
