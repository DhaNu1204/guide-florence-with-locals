import React, { createContext, useContext, useState, useRef, useCallback, useEffect, useMemo } from 'react';
import { createPortal } from 'react-dom';
import { FiCheckCircle, FiAlertCircle, FiInfo, FiX } from 'react-icons/fi';

// Lightweight, dependency-free toast notification system (Tuscan-themed).
//
// Renders a FIXED-position container via a portal to document.body so toasts
// are visible regardless of page scroll or parent overflow. Top-right on
// desktop; top-center, near-full-width on mobile. Auto-dismiss (5s; errors 7s)
// plus a manual X button.
//
// Usage:
//   const toast = useToast();
//   toast.success('Saved!');  toast.error('Failed');  toast.info('Heads up');
//
// useToast() is safe to call without a <ToastProvider> ancestor — it returns
// no-op functions (with a one-time dev warning) so render smoke tests and any
// stray usage never crash.

const ToastContext = createContext(null);

const AUTO_DISMISS_MS = { success: 5000, info: 5000, error: 7000 };

const TYPE_STYLES = {
  success: {
    container: 'bg-olive-50 border-olive-200 text-olive-800',
    icon: 'text-olive-600',
    Icon: FiCheckCircle,
  },
  error: {
    container: 'bg-terracotta-50 border-terracotta-200 text-terracotta-800',
    icon: 'text-terracotta-600',
    Icon: FiAlertCircle,
  },
  info: {
    container: 'bg-stone-50 border-stone-200 text-stone-800',
    icon: 'text-stone-600',
    Icon: FiInfo,
  },
};

const ToastItem = ({ toast, onDismiss }) => {
  const styles = TYPE_STYLES[toast.type] || TYPE_STYLES.info;
  const { Icon } = styles;
  const [leaving, setLeaving] = useState(false);

  const dismiss = () => {
    setLeaving(true);
    // Let the fade-out play before removing from the list.
    setTimeout(() => onDismiss(toast.id), 200);
  };

  return (
    <div
      role={toast.type === 'error' ? 'alert' : 'status'}
      className={`pointer-events-auto flex items-start gap-3 w-full sm:w-96 max-w-full px-4 py-3 rounded-tuscan-lg border shadow-tuscan-lg transition-all duration-200 ${styles.container} ${
        leaving ? 'opacity-0 -translate-y-2' : 'opacity-100 animate-fade-in'
      }`}
    >
      <Icon className={`text-xl mt-0.5 flex-shrink-0 ${styles.icon}`} />
      <p className="text-sm leading-snug flex-1 break-words">{toast.message}</p>
      <button
        onClick={dismiss}
        aria-label="Dismiss notification"
        className="flex-shrink-0 p-1 -mr-1 rounded hover:bg-black/5 active:bg-black/10 transition-colors"
      >
        <FiX className="text-base" />
      </button>
    </div>
  );
};

export const ToastProvider = ({ children }) => {
  const [toasts, setToasts] = useState([]);
  const idRef = useRef(0);
  const timersRef = useRef(new Map());

  const removeToast = useCallback((id) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
    const timer = timersRef.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timersRef.current.delete(id);
    }
  }, []);

  const addToast = useCallback((type, message) => {
    if (message == null || message === '') return undefined;
    const text = typeof message === 'string' ? message : String(message);
    const id = ++idRef.current;
    setToasts((prev) => [...prev, { id, type, message: text }]);
    const ms = AUTO_DISMISS_MS[type] || AUTO_DISMISS_MS.info;
    const timer = setTimeout(() => removeToast(id), ms);
    timersRef.current.set(id, timer);
    return id;
  }, [removeToast]);

  // Clear any pending timers on unmount.
  useEffect(() => {
    const timers = timersRef.current;
    return () => {
      timers.forEach((t) => clearTimeout(t));
      timers.clear();
    };
  }, []);

  const api = useMemo(() => ({
    success: (msg) => addToast('success', msg),
    error: (msg) => addToast('error', msg),
    info: (msg) => addToast('info', msg),
  }), [addToast]);

  const container = typeof document !== 'undefined'
    ? createPortal(
        <div
          className="fixed z-[9999] top-3 left-1/2 -translate-x-1/2 w-[calc(100%-1.5rem)] flex flex-col items-center gap-2 pointer-events-none sm:left-auto sm:translate-x-0 sm:right-4 sm:top-4 sm:w-auto sm:items-end"
          aria-live="polite"
          aria-atomic="false"
        >
          {toasts.map((t) => (
            <ToastItem key={t.id} toast={t} onDismiss={removeToast} />
          ))}
        </div>,
        document.body
      )
    : null;

  return (
    <ToastContext.Provider value={api}>
      {children}
      {container}
    </ToastContext.Provider>
  );
};

let warnedNoProvider = false;
const NOOP_TOAST = { success: () => {}, error: () => {}, info: () => {} };

export const useToast = () => {
  const ctx = useContext(ToastContext);
  if (!ctx) {
    if (!warnedNoProvider && typeof console !== 'undefined') {
      warnedNoProvider = true;
      console.warn('useToast() called outside <ToastProvider>; notifications are disabled.');
    }
    return NOOP_TOAST;
  }
  return ctx;
};

export default ToastProvider;
