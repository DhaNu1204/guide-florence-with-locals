import { tourCategory } from './tourCapacity';

// Step 6.17: "Counts as" on a manual merge that mixes tour types (Combo + Uffizi). The server
// stores tour_groups.billing_product_id; this only decides what the Tours page offers.

const liveTours = (group) => (group?.tours || []).filter((t) => !t.cancelled);

/** One option per distinct live member product, in booking order: {product_id, title, category}. */
export const billingOptions = (group) => {
  const seen = new Map();
  for (const t of liveTours(group)) {
    const pid = Number(t.product_id);
    if (!pid || seen.has(pid)) continue;
    seen.set(pid, { product_id: pid, title: t.title, category: tourCategory(t.title) });
  }
  return [...seen.values()];
};

/** The dropdown appears only on a manual merge whose live bookings span more than one type. */
export const showCountsAs = (group) => {
  if (!group?.is_manual_merge) return false;
  const cats = new Set(liveTours(group).map((t) => tourCategory(t.title)));
  return cats.size > 1 && billingOptions(group).length > 1;
};

/** The selected product id, or '' when none is stored or it is no longer a live member product. */
export const currentBillingId = (group) => {
  const id = Number(group?.billing_product_id);
  if (!id) return '';
  return billingOptions(group).some((o) => o.product_id === id) ? id : '';
};

export const billingOptionLabel = (o) => `${o.category} — ${o.title}`;
