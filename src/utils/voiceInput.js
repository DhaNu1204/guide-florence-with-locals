// Step 7.6: small pure rules for the assistant's voice input (unit-tested in __tests__/voiceInput.test.js).
// Lives in the lazy chat chunk only - the main bundle never imports it.

/** The browser's speech recognition constructor, or null where there is none (then no mic button). */
export const getRecognitionCtor = (win = typeof window !== 'undefined' ? window : undefined) => {
  if (!win) return null;
  return win.SpeechRecognition || win.webkitSpeechRecognition || null;
};

/** 'en' only when the browser clearly says English; anything else (or nothing) -> Italian. */
export const defaultVoiceLang = (navLang) => (/^en\b/i.test(String(navLang || '')) ? 'en' : 'it');

export const recognitionLocale = (lang) => (lang === 'en' ? 'en-GB' : 'it-IT');

const prefKey = (userName) => `fwl_voice_lang_${String(userName || 'anon').toLowerCase()}`;

/** The user's remembered IT / EN choice; storage may be blocked (private mode) - then the default. */
export const loadVoiceLang = (userName, navLang) => {
  try {
    const v = window.localStorage.getItem(prefKey(userName));
    if (v === 'it' || v === 'en') return v;
  } catch (_) { /* storage blocked */ }
  return defaultVoiceLang(navLang);
};

export const saveVoiceLang = (userName, lang) => {
  try { window.localStorage.setItem(prefKey(userName), lang); } catch (_) { /* storage blocked */ }
};

/** One clear line per recognition error code (SpeechRecognitionErrorEvent.error). null = say nothing. */
export const speechErrorMessage = (code) => {
  switch (code) {
    case 'not-allowed':
    case 'service-not-allowed':
      return 'Microphone permission denied - allow the microphone for this site in your browser settings.';
    case 'no-speech':
      return 'No speech heard - tap the mic and try again.';
    case 'audio-capture':
      return 'No microphone found on this device.';
    case 'network':
      return 'Voice input needs a connection - try again or type.';
    case 'aborted':
      return null; // our own stop
    default:
      return 'Voice input stopped - try again or type.';
  }
};

/** Put the heard text after what is already typed (never replaces the user's text). */
export const appendTranscript = (draft, heard) => {
  const t = String(heard || '').trim();
  if (!t) return draft || '';
  const d = String(draft || '');
  return d.trim() ? `${d.replace(/\s+$/, '')} ${t}` : t;
};
