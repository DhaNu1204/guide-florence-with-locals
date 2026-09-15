import React from 'react';
import ReactDOM from 'react-dom/client';
import * as Sentry from "@sentry/react";
import App from './App';
import './index.css';

// Initialize Sentry for error monitoring
Sentry.init({
  dsn: "https://507cab62a48d15caa7d1a535f389c36c@o4510711031201792.ingest.de.sentry.io/4510766649114704",
  integrations: [
    Sentry.browserTracingIntegration(),
    Sentry.replayIntegration(),
  ],
  // Performance Monitoring
  tracesSampleRate: 1.0, // Capture 100% of transactions (adjust in production)
  // Session Replay
  replaysSessionSampleRate: 0.1, // Sample 10% of sessions
  replaysOnErrorSampleRate: 1.0, // Sample 100% of sessions with errors
  // Send default PII data (IP address, etc.)
  sendDefaultPii: true,
  // Environment tag
  environment: import.meta.env.MODE,
});

// PWA: register the service worker (production only — never in dev, so
// localhost:5173 hot reload is unaffected). sw.js never intercepts /api/.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((err) => {
      console.warn('Service worker registration failed:', err);
    });
  });
}

const container = document.getElementById('root');
const root = ReactDOM.createRoot(container);

root.render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
); 