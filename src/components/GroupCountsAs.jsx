import React, { useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { tourGroupsAPI } from '../services/mysqlDB';
import { billingOptions, showCountsAs, currentBillingId, billingOptionLabel } from '../utils/billingProduct';

// Step 6.17: "Counts as" on a manual merge that mixes tour types. The chosen product is the
// group title and sets the guide rate (Daily P&L, Guide Tour Report). Only the P&L owner sees
// it; the server refuses everyone else (403), so hiding it here is only for honesty.
const GroupCountsAs = ({ group, onRefresh, onSuccess, onError, className = '' }) => {
  let canSeePnl = null;
  try { ({ canSeePnl } = useAuth()); } catch { canSeePnl = null; } // rendered outside the provider (tests): hidden
  const [saving, setSaving] = useState(false);

  if (!canSeePnl || !canSeePnl() || !showCountsAs(group)) return null;

  const options = billingOptions(group);
  const value = currentBillingId(group);

  const handleChange = async (e) => {
    const productId = Number(e.target.value);
    if (!productId || productId === value) return;
    setSaving(true);
    try {
      await tourGroupsAPI.setBillingProduct(group.id, productId);
      const chosen = options.find((o) => o.product_id === productId);
      onSuccess?.(`Counts as ${chosen ? chosen.category : 'the chosen product'}`);
      onRefresh?.();
    } catch (err) {
      onError?.(err?.response?.data?.error || 'Could not change what the group counts as');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className={`flex items-center gap-2 ${className}`} onClick={(e) => e.stopPropagation()}>
      <label htmlFor={`counts-as-${group.id}`} className="text-xs font-medium text-stone-600 whitespace-nowrap">
        Counts as
      </label>
      <select
        id={`counts-as-${group.id}`}
        data-testid="counts-as"
        value={value}
        onChange={handleChange}
        disabled={saving}
        className="min-w-0 flex-1 max-w-md text-sm border border-stone-300 rounded-tuscan px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-terracotta-500 disabled:opacity-60"
      >
        {value === '' && <option value="">Choose…</option>}
        {options.map((o) => (
          <option key={o.product_id} value={o.product_id}>{billingOptionLabel(o)}</option>
        ))}
      </select>
    </div>
  );
};

export default GroupCountsAs;
