import React, { useState } from 'react';
import { FiMessageCircle, FiX, FiCheck, FiCopy, FiRefreshCw, FiAlertCircle } from 'react-icons/fi';
import { createGuideRequest } from '../services/mysqlDB';

// Format a tour date as dd/MM/yyyy (parse from parts to avoid TZ drift).
const formatAskDate = (dateStr) => {
  const m = String(dateStr || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  return m ? `${m[3]}/${m[2]}/${m[1]}` : (dateStr || '');
};

/**
 * Reusable "Ask a guide" availability-request modal.
 *
 * Props:
 *  - tour: the tour object (needs id, title, date; time/language optional)
 *  - guides: array of guides ({ id, name, phone, languages: [] })
 *  - language: optional explicit tour language (falls back to tour.language)
 *  - time: optional explicit tour time (falls back to tour.time)
 *  - onClose(): close the modal
 *  - onRequested({ tourId, guideName, request }): fired after a request is created
 */
const AskGuideModal = ({ tour, guides = [], language, time, onClose, onRequested }) => {
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null); // { guideName, link, waUrl, hasPhone, message }
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');

  if (!tour) return null;

  const lingua = (language || tour.language || '').trim();
  const langLc = lingua.toLowerCase();
  const ora = String(time || tour.time || '').slice(0, 5);

  const speaksLang = (g) =>
    Array.isArray(g.languages) && g.languages.some((l) => String(l).toLowerCase() === langLc);
  const speakers = langLc ? guides.filter(speaksLang) : [];
  const others = langLc ? guides.filter((g) => !speaksLang(g)) : guides;

  const requestGuide = async (guide) => {
    setSubmitting(true);
    setError('');
    try {
      const res = await createGuideRequest({ tourId: tour.id, guideId: guide.id });
      const link = res.link;
      const dataStr = formatAskDate(tour.date);
      const message =
        `Ciao ${guide.name}, sei disponibile il ${dataStr} alle ${ora} per ${tour.title} (${lingua})? Conferma qui: ${link}`;
      const phoneDigits = (guide.phone || '').replace(/\D/g, '');
      const waUrl = phoneDigits ? `https://wa.me/${phoneDigits}?text=${encodeURIComponent(message)}` : '';
      setResult({ guideName: guide.name, link, waUrl, hasPhone: !!phoneDigits, message });
      if (onRequested) onRequested({ tourId: tour.id, guideName: guide.name, request: res });
    } catch (e) {
      console.error('Error creating guide request:', e);
      setError('Errore nella creazione della richiesta. Riprova.');
    } finally {
      setSubmitting(false);
    }
  };

  const copyLink = async () => {
    if (!result?.link) return;
    try {
      await navigator.clipboard.writeText(result.link);
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    } catch (e) {
      console.warn('Clipboard copy failed:', e);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 backdrop-blur-sm p-0 sm:p-4"
      onClick={onClose}
    >
      <div
        className="bg-white w-full sm:max-w-md rounded-t-tuscan-xl sm:rounded-tuscan-xl shadow-tuscan-xl max-h-[85vh] flex flex-col"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-start justify-between p-4 border-b border-stone-200">
          <div className="min-w-0">
            <h3 className="text-lg font-bold text-stone-800 flex items-center gap-2">
              <FiMessageCircle className="text-olive-600" /> Ask a guide
            </h3>
            <p className="text-xs text-stone-500 mt-0.5 truncate">
              {formatAskDate(tour.date)}{ora ? ` · ${ora}` : ''} · {tour.title}
            </p>
          </div>
          <button
            onClick={onClose}
            className="p-2 min-h-[40px] min-w-[40px] text-stone-400 hover:text-stone-600 hover:bg-stone-100 rounded-tuscan flex items-center justify-center flex-shrink-0"
            title="Close"
          >
            <FiX size={20} />
          </button>
        </div>

        {/* Body */}
        <div className="p-4 overflow-y-auto">
          {result ? (
            // ---- Result: created → send via WhatsApp ----
            <div className="space-y-4">
              <div className="bg-olive-50 border border-olive-200 rounded-tuscan-lg p-3">
                <p className="text-sm text-olive-800 font-medium">Richiesta creata per {result.guideName}.</p>
              </div>

              {result.hasPhone ? (
                <a
                  href={result.waUrl}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center justify-center gap-2 w-full min-h-[52px] px-5 rounded-tuscan-lg bg-olive-600 hover:bg-olive-700 active:bg-olive-800 text-white text-base font-semibold shadow-tuscan transition-colors"
                >
                  <FiMessageCircle /> Apri WhatsApp
                </a>
              ) : (
                <div className="bg-gold-50 border border-gold-200 rounded-tuscan-lg p-3 text-sm text-gold-800">
                  Nessun numero di telefono per {result.guideName}. Copia il link qui sotto e invialo manualmente, oppure aggiungi il numero nella pagina Guides.
                </div>
              )}

              <div className="bg-stone-50 border border-stone-200 rounded-tuscan-lg p-3">
                <p className="text-xs text-stone-500 mb-1">Link richiesta</p>
                <p className="text-xs text-stone-700 break-all">{result.link}</p>
              </div>

              <button
                onClick={copyLink}
                className="flex items-center justify-center gap-2 w-full min-h-[44px] px-5 rounded-tuscan-lg border-2 border-stone-300 hover:border-stone-400 hover:bg-stone-50 text-stone-700 font-medium transition-colors"
              >
                {copied ? <><FiCheck className="text-olive-600" /> Copiato!</> : <><FiCopy /> Copia link</>}
              </button>

              <button onClick={onClose} className="w-full text-sm text-stone-500 hover:text-stone-700 min-h-[40px]">
                Chiudi
              </button>
            </div>
          ) : (
            // ---- Guide picker ----
            <div className="space-y-4">
              <p className="text-sm text-stone-600">
                Lingua del tour: <span className="font-semibold text-stone-800">{lingua || 'n/a'}</span>. Scegli una guida da contattare:
              </p>

              {error && (
                <div className="flex items-center gap-2 text-sm text-terracotta-700 bg-terracotta-50 border border-terracotta-200 rounded-tuscan-lg px-3 py-2">
                  <FiAlertCircle className="flex-shrink-0" />{error}
                </div>
              )}

              {speakers.length > 0 && (
                <div>
                  <p className="text-xs font-semibold text-olive-700 uppercase tracking-wide mb-2">Parla {lingua}</p>
                  <div className="space-y-2">
                    {speakers.map((g) => (
                      <button
                        key={g.id}
                        onClick={() => requestGuide(g)}
                        disabled={submitting}
                        className="flex items-center justify-between w-full p-3 min-h-[48px] rounded-tuscan-lg border border-olive-200 bg-olive-50/50 hover:bg-olive-50 active:bg-olive-100 disabled:opacity-50 transition-colors text-left touch-manipulation"
                      >
                        <span className="font-medium text-stone-800">{g.name}</span>
                        <span className="text-xs text-olive-700">parla {lingua}{!g.phone ? ' · no tel' : ''}</span>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {others.length > 0 && (
                <div>
                  <p className="text-xs font-semibold text-stone-400 uppercase tracking-wide mb-2">
                    {speakers.length > 0 ? 'Altre guide' : 'Guide'}
                  </p>
                  <div className="space-y-2">
                    {others.map((g) => (
                      <button
                        key={g.id}
                        onClick={() => requestGuide(g)}
                        disabled={submitting}
                        className="flex items-center justify-between w-full p-3 min-h-[48px] rounded-tuscan-lg border border-stone-200 hover:bg-stone-50 active:bg-stone-100 disabled:opacity-50 transition-colors text-left touch-manipulation"
                      >
                        <span className="font-medium text-stone-700">{g.name}</span>
                        <span className="text-xs text-gold-700">
                          {langLc ? `non parla ${lingua}` : ''}{!g.phone ? `${langLc ? ' · ' : ''}no tel` : ''}
                        </span>
                      </button>
                    ))}
                  </div>
                </div>
              )}

              {guides.length === 0 && (
                <p className="text-sm text-stone-500 text-center py-4">Nessuna guida disponibile.</p>
              )}

              {submitting && (
                <div className="flex items-center justify-center gap-2 text-sm text-stone-500 pt-1">
                  <FiRefreshCw className="animate-spin" /> Creazione richiesta…
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default AskGuideModal;
