# Triage 2026-09-20 — "Something went wrong" when opening Tours on the phone

## Symptom
On 2026-09-20 at 07:38 UTC, on production, tapping **Tours** on the owner's phone (Mobile Safari 26.6.1 / iOS 18.7, 5G) showed the red "Something went wrong" screen instead of the Tours page.
Sentry issue 148176284, release `fwl@0.0.2`: `TypeError: Importing a module script failed.` Breadcrumbs: navigation `/` → `/tours`, two API calls answered 200 just before.

The error is the browser rejecting a dynamic `import()`, i.e. one of the lazy route chunks added in step 4.1 failed to load, and `Sentry.ErrorBoundary` caught it.

## Candidate causes and what the evidence says

### B — stale chunk after a deploy: **RULED OUT for this incident**
`scripts/deploy.sh` **does delete** the previous release's hashed files. The frontend step calls the remote sync helper with the delete scope `assets`:

```
rssh "bash -s -- '$REMOTE_TMP/dist' '$REMOTE_PATH' 'assets'" <<< "$REMOTE_SYNC"   # deploy.sh:190
```

and inside that helper (`deploy.sh:152-159`) every file under `$DEST/assets` that is not in the new `dist/` is `rm -f`'d and logged as `removed stale:`. So a phone holding an old `index-*.js` across a frontend deploy **would** ask for chunk names that no longer exist. That is a real latent risk — it just is not what happened here:

- Production `index.html` currently points at **`assets/index-BzSOUwhB.js`** — the very file the owner's page was running.
- That file and **all 12 chunks it references** are present on the server right now (checked each name against disk: `referenced chunks: 12, missing: 0`), including `Tours-B-YVitPs.js`.
- Every asset is dated **2026-09-18 20:19 UTC** and nothing has replaced them since: both 2026-09-19 deploys (step 3.4) reported `sync: 0 updated, 42 unchanged, 0 removed` for the frontend — backend-only changes. The error is ~35 h after the last frontend change.
- The chunk serves correctly from outside: `GET /assets/Tours-B-YVitPs.js` → **200, 66,744 bytes, 0.41 s**, `Content-Type: application/x-javascript` (a valid JavaScript MIME type), `Cache-Control: public, max-age=31536000, immutable`.

### A — transient network failure on a weak mobile link: **the remaining explanation**
Nothing was wrong with the server, the file, or its name, so the request itself must have failed in flight. It fits the rest of the evidence: a phone on 5G, a navigation that triggers a first-time fetch of a 66 KB chunk, and `React.lazy` with **no retry at all** — a single dropped request is fatal and permanent for that route, because React caches the rejected lazy promise. The two earlier API calls succeeding does not contradict this; they were smaller and earlier.

This is not fully provable after the fact (there is no client-side network log), so it is stated as the surviving hypothesis rather than a proof, and the fix defends against both A and B.

## Root cause in code
`src/App.jsx:17-27` — eleven `lazy(() => import('...'))` calls with no retry and no recovery:

```js
const Tours = lazy(() => import('./pages/Tours'));
```

and `src/App.jsx:213-226` — the `ErrorFallback` "Try Again" button calls `resetError()`, which only re-renders; React replays the same rejected promise, so the button can never fix this class of error.

## Fix — plan step 4.1b
(`4.1a` is already taken by the 2026-09-18 payment-views hotfix, so this is tracked as **4.1b**.)

1. One `lazyWithRetry()` helper used by all 11 routes: retry the import 2–3 times with backoff (300 ms, 900 ms).
2. If it still fails, reload the page once (guarded by a `sessionStorage` flag so it can never loop); the reload refetches `index.html`, which is `no-store` since step 2.3, so a stale build self-heals. Flag already set → fall through to the error screen.
3. `ErrorFallback` "Try Again" must reload the page, and say to check the connection.
4. Keep the previous release's `assets/` for one more deploy, so cause B cannot happen at all.
5. Service-worker precaching of route chunks is **step 4.5** (`vite-plugin-pwa` / `generateSW`), not this step.
