import React from 'react';
import ReactDOM from 'react-dom/client';
import * as Sentry from "@sentry/react";
import App from './App';
import { markEntry } from './utils/perfBeacon';
import './index.css';

// Step 4.7: field instrumentation (measurement only - see src/utils/perfBeacon.js).
// First statement after the imports so "entry script executed" is honest.
markEntry(__APP_VERSION__);

// Initialize Sentry for error monitoring.
// Step 4.1: Session Replay is NOT part of the production bundle - `Sentry.replayIntegration`
// is only referenced behind `import.meta.env.PROD`, so Rollup drops Replay (~100 KB) from
// the shipped chunk. Error capture and (sampled) tracing are unchanged.
Sentry.init({
  dsn: "https://507cab62a48d15caa7d1a535f389c36c@o4510711031201792.ingest.de.sentry.io/4510766649114704",
  integrations: import.meta.env.PROD
    ? [Sentry.browserTracingIntegration()]
    : [Sentry.browserTracingIntegration(), Sentry.replayIntegration()],
  // Performance Monitoring (step 4.1: 10% of transactions instead of all of them)
  tracesSampleRate: 0.1,
  // Session Replay: off in production (the integration is not even shipped there)
  replaysSessionSampleRate: 0,
  replaysOnErrorSampleRate: 0,
  // Send default PII data (IP address, etc.)
  sendDefaultPii: true,
  // Environment tag
  environment: import.meta.env.MODE,
  release: `fwl@${__APP_VERSION__}`, // step 2.3: from package.json via vite define
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