import React, { useState, useEffect, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import {
  FiMapPin,
  FiCalendar,
  FiClock,
  FiUsers,
  FiGlobe,
  FiNavigation,
  FiCheck,
  FiX,
  FiLoader,
  FiAlertCircle
} from 'react-icons/fi';

// Plain API base — NB: we deliberately use window.fetch here, NOT the axios
// instance in mysqlDB.js, because that injects a Bearer token. Guides have no
// login, so this public page must call the endpoint with no auth header.
const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8080/api';

// Parse 'YYYY-MM-DD' from parts to avoid a UTC off-by-one, then format in Italian.
const formatDateIt = (dateStr) => {
  if (!dateStr) return '-';
  const m = String(dateStr).match(/^(\d{4})-(\d{2})-(\d{2})/);
  const d = m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : new Date(dateStr);
  if (isNaN(d.getTime())) return String(dateStr);
  return d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};

const formatTime = (t) => (t ? String(t).slice(0, 5) : '-');

const GuideRespond = () => {
  const { token } = useParams();

  // phase: 'loading' | 'invalid' | 'pending' | 'result' | 'error'
  const [phase, setPhase] = useState('loading');
  const [guideName, setGuideName] = useState('');
  const [tour, setTour] = useState(null);
  const [resultStatus, setResultStatus] = useState(''); // accepted|declined|taken|conflict|expired|cancelled
  const [submitting, setSubmitting] = useState(false);

  const loadRequest = useCallback(async () => {
    setPhase('loading');
    try {
      const res = await fetch(`${API_BASE_URL}/guide-requests.php?token=${encodeURIComponent(token)}`);
      if (res.status === 404) {
        setPhase('invalid');
        return;
      }
      if (!res.ok) {
        setPhase('error');
        return;
      }
      const data = await res.json();
      if (!data || !data.success) {
        setPhase('invalid');
        return;
      }
      setGuideName(data.guide_name || '');
      setTour(data.tour || null);

      if (data.status === 'pending') {
        setPhase('pending');
      } else {
        // Already responded / closed
        setResultStatus(data.status);
        setPhase('result');
      }
    } catch (e) {
      setPhase('error');
    }
  }, [token]);

  useEffect(() => {
    loadRequest();
  }, [loadRequest]);

  const respond = async (action) => {
    setSubmitting(true);
    try {
      const res = await fetch(`${API_BASE_URL}/guide-requests.php?token=${encodeURIComponent(token)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action })
      });
      if (!res.ok && res.status !== 200) {
        setPhase('error');
        return;
      }
      const data = await res.json();
      // Backend returns the resulting status: accepted | declined | taken | conflict | expired ...
      setResultStatus(data.status || (action === 'accept' ? 'accepted' : 'declined'));
      setPhase('result');
    } catch (e) {
      setPhase('error');
    } finally {
      setSubmitting(false);
    }
  };

  // ---- Small presentational pieces ----------------------------------------

  const Shell = ({ children }) => (
    <div className="min-h-screen bg-tuscan-gradient flex items-center justify-center p-4">
      <div className="w-full max-w-md">
        {/* Branding */}
        <div className="flex items-center justify-center gap-3 mb-5">
          <div className="w-11 h-11 bg-gradient-to-br from-terracotta-500 to-terracotta-700 rounded-tuscan-lg flex items-center justify-center shadow-tuscan">
            <FiMapPin className="text-white text-xl" />
          </div>
          <div className="text-left">
            <h1 className="text-lg font-bold text-stone-800 leading-tight">Florence with Locals</h1>
            <p className="text-xs text-stone-500">Disponibilità guida</p>
          </div>
        </div>
        <div className="bg-white rounded-tuscan-xl shadow-tuscan border border-stone-200/60 p-6">
          {children}
        </div>
        <p className="text-center text-xs text-stone-400 mt-4">Florence with Locals</p>
      </div>
    </div>
  );

  const TourCard = () => {
    if (!tour) return null;
    const rows = [
      { icon: FiCalendar, label: 'Data', value: formatDateIt(tour.date) },
      { icon: FiClock, label: 'Orario', value: formatTime(tour.time) },
      { icon: FiMapPin, label: 'Tour', value: tour.title || '-' },
      { icon: FiGlobe, label: 'Lingua', value: tour.language || '-' },
      { icon: FiUsers, label: 'Persone', value: (tour.participants ?? 0) + '' }
    ];
    if (tour.meeting_point) {
      rows.push({ icon: FiNavigation, label: "Punto d'incontro", value: tour.meeting_point });
    }
    return (
      <div className="bg-stone-50 border border-stone-200 rounded-tuscan-lg p-4 space-y-3">
        {rows.map((r) => {
          const Icon = r.icon;
          return (
            <div key={r.label} className="flex items-start gap-3">
              <Icon className="text-terracotta-600 mt-0.5 flex-shrink-0" />
              <div className="min-w-0">
                <p className="text-xs font-medium text-stone-500 uppercase tracking-wide">{r.label}</p>
                <p className="text-sm font-semibold text-stone-800 break-words first-letter:uppercase">{r.value}</p>
              </div>
            </div>
          );
        })}
      </div>
    );
  };

  // ---- Phase renderers -----------------------------------------------------

  if (phase === 'loading') {
    return (
      <Shell>
        <div className="flex flex-col items-center py-8 text-stone-500">
          <FiLoader className="animate-spin text-3xl text-terracotta-500 mb-3" />
          <p className="text-sm">Caricamento…</p>
        </div>
      </Shell>
    );
  }

  if (phase === 'invalid') {
    return (
      <Shell>
        <div className="text-center py-6">
          <FiAlertCircle className="text-4xl text-gold-500 mx-auto mb-3" />
          <h2 className="text-lg font-bold text-stone-800 mb-1">Link non valido o scaduto.</h2>
          <p className="text-sm text-stone-500">Contatta l'ufficio per un nuovo link.</p>
        </div>
      </Shell>
    );
  }

  if (phase === 'error') {
    return (
      <Shell>
        <div className="text-center py-6">
          <FiAlertCircle className="text-4xl text-terracotta-500 mx-auto mb-3" />
          <h2 className="text-lg font-bold text-stone-800 mb-3">Si è verificato un errore.</h2>
          <button
            onClick={loadRequest}
            className="inline-flex items-center justify-center min-h-[48px] px-5 rounded-tuscan-lg bg-terracotta-500 hover:bg-terracotta-600 active:bg-terracotta-700 text-white font-medium transition-colors"
          >
            Riprova
          </button>
        </div>
      </Shell>
    );
  }

  if (phase === 'pending') {
    return (
      <Shell>
        <p className="text-stone-700 mb-1">
          Ciao{guideName ? <span className="font-semibold"> {guideName}</span> : ''},
        </p>
        <p className="text-sm text-stone-600 mb-4">sei disponibile per questo tour?</p>

        <TourCard />

        <div className="grid grid-cols-1 gap-3 mt-5">
          <button
            onClick={() => respond('accept')}
            disabled={submitting}
            className="inline-flex items-center justify-center min-h-[52px] px-5 rounded-tuscan-lg bg-olive-600 hover:bg-olive-700 active:bg-olive-800 text-white text-base font-semibold shadow-tuscan transition-colors disabled:opacity-60 touch-manipulation"
          >
            {submitting ? <FiLoader className="animate-spin mr-2" /> : <FiCheck className="mr-2" />}
            Accetto
          </button>
          <button
            onClick={() => respond('decline')}
            disabled={submitting}
            className="inline-flex items-center justify-center min-h-[52px] px-5 rounded-tuscan-lg border-2 border-stone-300 hover:border-stone-400 hover:bg-stone-50 active:bg-stone-100 text-stone-700 text-base font-semibold transition-colors disabled:opacity-60 touch-manipulation"
          >
            <FiX className="mr-2" />
            Non posso
          </button>
        </div>
      </Shell>
    );
  }

  // phase === 'result'
  const renderResult = () => {
    switch (resultStatus) {
      case 'accepted':
        return (
          <div className="text-center py-2">
            <div className="w-14 h-14 bg-olive-100 rounded-full flex items-center justify-center mx-auto mb-3">
              <FiCheck className="text-olive-600 text-3xl" />
            </div>
            <h2 className="text-xl font-bold text-stone-800 mb-1">Grazie! Tour confermato ✅</h2>
            <p className="text-sm text-stone-500 mb-4">Ci vediamo al tour.</p>
            <TourCard />
          </div>
        );
      case 'declined':
        return (
          <div className="text-center py-6">
            <div className="w-14 h-14 bg-stone-100 rounded-full flex items-center justify-center mx-auto mb-3">
              <FiX className="text-stone-500 text-3xl" />
            </div>
            <h2 className="text-lg font-bold text-stone-800 mb-1">Grazie per la risposta.</h2>
            <p className="text-sm text-stone-500">Abbiamo registrato che non sei disponibile.</p>
          </div>
        );
      case 'taken':
        return (
          <div className="text-center py-6">
            <FiAlertCircle className="text-4xl text-gold-500 mx-auto mb-3" />
            <h2 className="text-lg font-bold text-stone-800 mb-1">Tour già assegnato</h2>
            <p className="text-sm text-stone-500">Questo tour è già stato assegnato a un'altra guida.</p>
          </div>
        );
      case 'conflict':
        return (
          <div className="text-center py-6">
            <FiAlertCircle className="text-4xl text-terracotta-500 mx-auto mb-3" />
            <h2 className="text-lg font-bold text-stone-800 mb-1">Sovrapposizione</h2>
            <p className="text-sm text-stone-500">Hai già un tour in questo giorno e orario.</p>
          </div>
        );
      case 'expired':
        return (
          <div className="text-center py-6">
            <FiAlertCircle className="text-4xl text-gold-500 mx-auto mb-3" />
            <h2 className="text-lg font-bold text-stone-800 mb-1">Richiesta scaduta</h2>
            <p className="text-sm text-stone-500">Questa richiesta non è più attiva.</p>
          </div>
        );
      case 'cancelled':
        return (
          <div className="text-center py-6">
            <FiAlertCircle className="text-4xl text-stone-400 mx-auto mb-3" />
            <h2 className="text-lg font-bold text-stone-800 mb-1">Richiesta annullata</h2>
            <p className="text-sm text-stone-500">Questa richiesta è stata annullata dall'ufficio.</p>
          </div>
        );
      default:
        return (
          <div className="text-center py-6">
            <FiAlertCircle className="text-4xl text-stone-400 mx-auto mb-3" />
            <h2 className="text-lg font-bold text-stone-800 mb-1">Grazie.</h2>
            <p className="text-sm text-stone-500">Risposta registrata.</p>
          </div>
        );
    }
  };

  return <Shell>{renderResult()}</Shell>;
};

export default GuideRespond;
