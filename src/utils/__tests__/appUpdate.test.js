/**
 * Step 4.10: an installed app picks up a new version (build compare + reload decision).
 */
import { describe, it, expect } from 'vitest';
import { currentBuild, checkForUpdate, shouldAutoReload } from '../appUpdate';

const docWith = (src) => {
  const d = document.implementation.createHTMLDocument('x');
  if (src) {
    const s = d.createElement('script');
    s.setAttribute('type', 'module');
    s.setAttribute('src', src);
    d.head.appendChild(s);
  }
  return d;
};

const answer = (body, ok = true) => async () => ({ ok, json: async () => body });

describe('currentBuild', () => {
  it('reads the hash of the entry script', () => {
    expect(currentBuild(docWith('/assets/index-R8cDs97j.js'))).toBe('R8cDs97j');
  });
  it('is null in dev (no hashed entry) - no update check there', () => {
    expect(currentBuild(docWith('/src/main.jsx'))).toBeNull();
    expect(currentBuild(docWith(null))).toBeNull();
  });
});

describe('checkForUpdate', () => {
  it('same build -> same', async () => {
    const r = await checkForUpdate({ current: 'R8cDs97j', fetcher: answer({ ok: true, build: 'R8cDs97j' }) });
    expect(r.status).toBe('same');
  });
  it('a different live build -> new, with the server build', async () => {
    const r = await checkForUpdate({ current: 'R8cDs97j', fetcher: answer({ ok: true, build: 'Xy12Zz90' }) });
    expect(r).toEqual({ status: 'new', current: 'R8cDs97j', server: 'Xy12Zz90' });
  });
  it('no answer, an error or an old server without build -> unknown (never a reload)', async () => {
    const stalled = async () => { const e = new Error('No answer within 8 s'); e.name = 'TimeoutError'; throw e; };
    expect((await checkForUpdate({ current: 'a1b2c3d4', fetcher: stalled })).status).toBe('unknown');
    expect((await checkForUpdate({ current: 'a1b2c3d4', fetcher: answer({}, false) })).status).toBe('unknown');
    expect((await checkForUpdate({ current: 'a1b2c3d4', fetcher: answer({ ok: true, sha: 'abc' }) })).status).toBe('unknown');
  });
  it('asks the no-database probe with a cache buster', async () => {
    let url;
    await checkForUpdate({ current: 'a1b2c3d4', fetcher: async (u) => { url = u; return { ok: true, json: async () => ({ build: 'a1b2c3d4' }) }; } });
    expect(url).toMatch(/\/health\.php\?probe=1&_=\d+$/);
  });
});

describe('shouldAutoReload', () => {
  const base = { sinceShownMs: 3000, interacted: false, editing: false, alreadyReloadedFor: null, server: 'NEW1234x' };
  it('just opened or resumed, untouched -> reload straight into the new version', () => {
    expect(shouldAutoReload(base)).toBe(true);
  });
  it('already in use, typing, or opened a while ago -> the tap-to-update bar instead', () => {
    expect(shouldAutoReload({ ...base, interacted: true })).toBe(false);
    expect(shouldAutoReload({ ...base, editing: true })).toBe(false);
    expect(shouldAutoReload({ ...base, sinceShownMs: 60000 })).toBe(false);
  });
  it('a reload for this very build already happened and did not help -> no loop, the bar', () => {
    expect(shouldAutoReload({ ...base, alreadyReloadedFor: 'NEW1234x' })).toBe(false);
    expect(shouldAutoReload({ ...base, alreadyReloadedFor: 'OLDER999' })).toBe(true);
  });
});
