/**
 * Step 1.2: masked Bokun config shape helpers.
 */
import { describe, it, expect } from 'vitest';
import {
  MASKED_CONFIG_FIELDS,
  maskedConfigFromResponse,
  maskApiKey,
  isSyncDisabledResponse,
} from '../bokunConfig';

describe('maskedConfigFromResponse (step 1.2)', () => {
  it('keeps only the masked fields and drops any key or secret the server might send', () => {
    const out = maskedConfigFromResponse({
      configured: true,
      sync_enabled: 1,
      vendor_id: 96929,
      last_sync: '2026-09-15 22:42:50',
      api_key_masked: 'H2pn…',
      updated_at: '2026-09-15 22:42:50',
      api_key: 'SHOULD-NOT-PASS',
      api_secret: 'SHOULD-NOT-PASS',
      access_key: 'SHOULD-NOT-PASS',
      secret_key: 'SHOULD-NOT-PASS',
      id: 1,
    });
    expect(Object.keys(out).sort()).toEqual([...MASKED_CONFIG_FIELDS].sort());
    expect(JSON.stringify(out)).not.toContain('SHOULD-NOT-PASS');
    expect(out).toEqual({
      configured: true,
      sync_enabled: true,
      vendor_id: '96929',
      last_sync: '2026-09-15 22:42:50',
      api_key_masked: 'H2pn…',
      updated_at: '2026-09-15 22:42:50',
    });
  });

  it('returns safe defaults for an empty, null or non-object response', () => {
    const empty = { configured: false, sync_enabled: false, vendor_id: '', last_sync: null, api_key_masked: '', updated_at: null };
    expect(maskedConfigFromResponse(null)).toEqual(empty);
    expect(maskedConfigFromResponse('nope')).toEqual(empty);
    expect(maskedConfigFromResponse({ configured: false })).toEqual(empty);
  });

  it('never sets configured/sync_enabled truthy from strings like "0"', () => {
    const out = maskedConfigFromResponse({ configured: 0, sync_enabled: 0 });
    expect(out.configured).toBe(false);
    expect(out.sync_enabled).toBe(false);
  });
});

describe('maskApiKey', () => {
  it('shows the first 4 characters and an ellipsis', () => {
    expect(maskApiKey('abcdefgh12345678')).toBe('abcd…');
    expect(maskApiKey('')).toBe('');
    expect(maskApiKey(null)).toBe('');
  });
});

describe('isSyncDisabledResponse', () => {
  it('recognises the server skip answer and nothing else', () => {
    expect(isSyncDisabledResponse({ success: false, error: 'sync_disabled' })).toBe(true);
    expect(isSyncDisabledResponse({ success: false, error: 'boom' })).toBe(false);
    expect(isSyncDisabledResponse({ success: true })).toBe(false);
    expect(isSyncDisabledResponse(null)).toBe(false);
  });
});
