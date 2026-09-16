// Step 1.2: the Bokun config shape the client is allowed to hold.
//
// The API's GET/POST action=config answers only with these fields; this helper
// is the single place that turns an API response into component state and it
// drops anything else (so a key or secret can never be stored or rendered by
// accident, whatever the server sends).

export const MASKED_CONFIG_FIELDS = [
  'configured',
  'sync_enabled',
  'vendor_id',
  'last_sync',
  'api_key_masked',
  'updated_at',
];

export const emptyMaskedConfig = () => ({
  configured: false,
  sync_enabled: false,
  vendor_id: '',
  last_sync: null,
  api_key_masked: '',
  updated_at: null,
});

export const maskedConfigFromResponse = (data) => {
  const out = emptyMaskedConfig();
  if (!data || typeof data !== 'object') return out;
  MASKED_CONFIG_FIELDS.forEach((field) => {
    if (field in data && data[field] !== null && data[field] !== undefined) {
      out[field] = data[field];
    }
  });
  out.configured = Boolean(out.configured);
  out.sync_enabled = Boolean(out.sync_enabled);
  out.vendor_id = out.vendor_id === null || out.vendor_id === undefined ? '' : String(out.vendor_id);
  out.api_key_masked = out.api_key_masked ? String(out.api_key_masked) : '';
  return out;
};

// Mirror of the server-side mask: first 4 characters + an ellipsis.
export const maskApiKey = (key) => (key ? `${String(key).slice(0, 4)}…` : '');

// action=sync answers HTTP 200 {success:false, error:'sync_disabled'} when the
// server has sync switched off (env flag or row flag). That is a skip, not an error.
export const isSyncDisabledResponse = (data) =>
  Boolean(data) && data.success === false && data.error === 'sync_disabled';
