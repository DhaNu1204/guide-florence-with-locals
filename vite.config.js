import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import pkg from './package.json';

// step 4.1: react / react-dom / scheduler / react-router* -> one long-lived vendor chunk.
const REACT_CORE = new RegExp('/node_modules/(react|react-dom|scheduler|react-router|react-router-dom)/');

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  // step 2.3: the package version is baked into the bundle (Sentry release), so a
  // version bump changes the index-*.js hash - used to prove deploys are picked up.
  define: { __APP_VERSION__: JSON.stringify(pkg.version) },
  base: '/', // App is served at the domain root; absolute '/assets/...' so deep routes (e.g. /respond/:token) load JS/CSS correctly
  build: {
    rollupOptions: {
      output: {
        // step 4.1: React itself is the only library forced into a shared chunk - it is
        // needed on every page and changes rarely, so it stays cached across deploys.
        // Everything else is left to Rollup: each React.lazy() page gets its own chunk,
        // and a library used by only some pages (react-datepicker, jspdf, date-fns) is
        // downloaded with the first page that needs it, never on first paint.
        manualChunks(id) {
          // Rollup gives POSIX-style ids, also on Windows.
          if (!id.includes('/node_modules/')) return undefined;
          if (REACT_CORE.test(id)) {
            return 'vendor-react';
          }
          return undefined;
        },
      },
    },
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/test/setup.js',
    css: true,
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      exclude: [
        'node_modules/',
        'src/test/',
        '**/*.d.ts',
      ],
    },
  },
});
