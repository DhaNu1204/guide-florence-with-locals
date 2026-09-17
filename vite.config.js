import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import pkg from './package.json';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  // step 2.3: the package version is baked into the bundle (Sentry release), so a
  // version bump changes the index-*.js hash - used to prove deploys are picked up.
  define: { __APP_VERSION__: JSON.stringify(pkg.version) },
  base: '/', // App is served at the domain root; absolute '/assets/...' so deep routes (e.g. /respond/:token) load JS/CSS correctly
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
