// Step 7.2b: product settings (admin only). The products table decides Tours vs Tickets for every
// page (since step 3.8) - this is the UI for it, plus each product's duration (used by the
// assistant's "who is free", later by clash checks). Empty duration = unknown (120 min assumed).
// Step 6.22: "Counts as" links a product to the original it stands for (e.g. the new Viator
// account's copy of a tour). A linked product takes the original's type, duration, grouping,
// category and costs everywhere; its bookings keep their own title. Never linked automatically.
import React, { useEffect, useState } from 'react';
import { FiPackage, FiSave, FiRefreshCw } from 'react-icons/fi';
import { getProducts, updateProduct } from '../services/mysqlDB';
import { usePageTitle } from '../contexts/PageTitleContext';
import { useToast } from '../components/Toast/ToastProvider';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';

const durationError = (v) => {
  if (v === '' || v === null || v === undefined) return null;
  if (!/^\d+$/.test(String(v))) return 'Whole minutes';
  const n = Number(v);
  return n < 5 || n > 1440 ? '5 to 1440 minutes' : null;
};

/** Step 6.22: the type Tours/Tickets use - the original's when the product counts as another. */
export const effectiveTypeOf = (product, all) => {
  if (product.groups_as_product_id == null) return product.product_type;
  const orig = (all || []).find((p) => p.bokun_product_id === product.groups_as_product_id);
  return orig ? orig.product_type : (product.effective_type || product.product_type);
};

/** Step 6.22: products this one may count as - any other product that has no link itself. */
export const linkOptionsFor = (product, all) => (all || [])
  .filter((p) => p.bokun_product_id !== product.bokun_product_id && p.groups_as_product_id == null)
  .sort((a, b) => String(a.title).localeCompare(String(b.title)));

function ProductRow({ product, allProducts, onSaved }) {
  const toast = useToast();
  const [title, setTitle] = useState(product.title || '');
  const [duration, setDuration] = useState(product.duration_minutes === null ? '' : String(product.duration_minutes));
  const [saving, setSaving] = useState(false);
  const dirty = title.trim() !== (product.title || '').trim()
    || duration !== (product.duration_minutes === null ? '' : String(product.duration_minutes));
  const dErr = durationError(duration);

  const save = async (fields, okText) => {
    setSaving(true);
    try {
      const saved = await updateProduct(product.bokun_product_id, fields);
      toast.success(okText);
      onSaved(saved);
    } catch (e) {
      toast.error(e?.response?.data?.error || 'Could not save the product');
    } finally {
      setSaving(false);
    }
  };

  const saveTexts = () => {
    if (dErr || !title.trim()) return;
    const fields = {};
    if (title.trim() !== (product.title || '').trim()) fields.title = title.trim();
    if (duration !== (product.duration_minutes === null ? '' : String(product.duration_minutes))) {
      fields.duration_minutes = duration === '' ? null : Number(duration);
    }
    save(fields, `Saved: ${title.trim()}`);
  };

  const linkedFrom = (allProducts || []).filter((p) => p.groups_as_product_id === product.bokun_product_id);
  const linked = product.groups_as_product_id != null;
  const original = linked ? (allProducts || []).find((p) => p.bokun_product_id === product.groups_as_product_id) : null;

  const setLink = (value) => {
    const target = value === '' ? null : Number(value);
    if (target === (product.groups_as_product_id ?? null)) return;
    const name = target === null ? null : (allProducts.find((p) => p.bokun_product_id === target)?.title || `#${target}`);
    const msg = target === null
      ? `Remove the link? "${product.title}" will count as itself again (its own type, duration and grouping).`
      : `Count "${product.title}" as "${name}"? Its bookings group with that product's and use its type, duration, costs and category. Each booking keeps its own title.`;
    // eslint-disable-next-line no-alert
    if (!window.confirm(msg)) return;
    save({ groups_as_product_id: target }, target === null ? `${product.title}: no link` : `${product.title}: counts as ${name}`);
  };

  const setType = (type) => {
    if (type === product.product_type) return;
    const where = type === 'ticket' ? 'Tickets' : 'Tours';
    // eslint-disable-next-line no-alert
    if (!window.confirm(`Move "${product.title}" to ${where}? Its bookings will show on the ${where} page from now on.`)) return;
    save({ product_type: type }, `${product.title}: now a ${type}`);
  };

  return (
    <div className="grid gap-3 px-4 py-3 md:grid-cols-[1fr_auto_auto_auto] md:items-center" data-testid={`product-${product.bokun_product_id}`}>
      <div className="min-w-0">
        <input
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          aria-label={`Title of product ${product.bokun_product_id}`}
          maxLength={500}
          className="w-full rounded-tuscan border border-stone-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500"
        />
        <p className="mt-1 text-xs text-stone-500">
          Bokun #{product.bokun_product_id} · {product.upcoming_bookings} upcoming booking{product.upcoming_bookings === 1 ? '' : 's'}
          {product.last_date ? ` · last date ${product.last_date}` : ''}
        </p>
        {linked && (
          <p className="mt-0.5 text-xs text-terracotta-700">
            Type, duration and meeting point come from {original ? original.title : `#${product.groups_as_product_id}`}.
          </p>
        )}
      <div className="mt-2 max-w-md">
        {linkedFrom.length > 0 ? (
          <p className="text-xs text-stone-500" data-testid={`link-${product.bokun_product_id}`}>
            Counted as by {linkedFrom.length} product{linkedFrom.length === 1 ? '' : 's'}
          </p>
        ) : (
          <label className="block text-xs text-stone-500">
            Counts as
            <select
              value={product.groups_as_product_id ?? ''}
              disabled={saving}
              onChange={(e) => setLink(e.target.value)}
              aria-label={`Counts as (product ${product.bokun_product_id})`}
              data-testid={`link-${product.bokun_product_id}`}
              className={`mt-0.5 block w-full min-h-[40px] rounded-tuscan border px-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 ${linked ? 'border-terracotta-300 text-stone-800' : 'border-stone-300 text-stone-500'}`}
            >
              <option value="">No link</option>
              {linkOptionsFor(product, allProducts).map((p) => (
                <option key={p.bokun_product_id} value={p.bokun_product_id}>{p.title} (#{p.bokun_product_id})</option>
              ))}
            </select>
          </label>
        )}
      </div>
      </div>
      {linked ? <div /> : (
      <div className="flex rounded-tuscan border border-stone-300 p-0.5" role="group" aria-label={`Type of ${product.title}`}>
        {[['tour', 'Tour'], ['ticket', 'Ticket']].map(([key, label]) => (
          <button
            key={key}
            type="button"
            disabled={saving}
            aria-pressed={product.product_type === key}
            onClick={() => setType(key)}
            className={`min-h-[40px] rounded-tuscan px-3 text-sm font-medium touch-manipulation ${product.product_type === key ? 'bg-terracotta-500 text-white' : 'text-stone-600 hover:bg-stone-100'}`}
          >
            {label}
          </button>
        ))}
      </div>
      )}
      {linked ? <div /> : (
      <div>
        <div className="flex items-center gap-1">
          <input
            value={duration}
            onChange={(e) => setDuration(e.target.value.trim())}
            inputMode="numeric"
            placeholder="120 assumed"
            aria-label={`Duration in minutes of ${product.title}`}
            className={`w-28 rounded-tuscan border px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-terracotta-500 ${dErr ? 'border-red-400' : 'border-stone-300'}`}
          />
          <span className="text-xs text-stone-500">min</span>
        </div>
        {dErr && <p className="mt-0.5 text-xs text-red-600">{dErr}</p>}
      </div>
      )}
      <div className="md:w-24">
        {dirty && (
          <Button size="sm" icon={FiSave} loading={saving} disabled={Boolean(dErr) || !title.trim()} onClick={saveTexts}>
            Save
          </Button>
        )}
      </div>
    </div>
  );
}

export default function Products() {
  const { setPageTitle } = usePageTitle();
  const [products, setProducts] = useState(null);
  const [error, setError] = useState(null);

  const load = async () => {
    setError(null);
    try {
      setProducts(await getProducts());
    } catch (e) {
      setError('Could not load the products.');
    }
  };

  useEffect(() => {
    if (setPageTitle) setPageTitle('Products');
    load();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const onSaved = (saved) => {
    if (!saved) return;
    setProducts((prev) => prev.map((p) => (p.bokun_product_id === saved.bokun_product_id ? { ...p, ...saved } : p)));
  };

  // Step 6.22: a linked product is listed where its original is (that is the type it counts as).
  const tours = (products || []).filter((p) => effectiveTypeOf(p, products) === 'tour');
  const tickets = (products || []).filter((p) => effectiveTypeOf(p, products) === 'ticket');
  const missing = tours.filter((p) => p.duration_minutes === null && p.groups_as_product_id == null).length;

  const section = (title, list, hint) => (
    <Card className="p-0">
      <div className="border-b border-stone-200 px-4 py-3">
        <h2 className="text-base font-semibold text-stone-800">{title} ({list.length})</h2>
        <p className="text-xs text-stone-500">{hint}</p>
      </div>
      <div className="divide-y divide-stone-100">
        {list.map((p) => <ProductRow key={p.bokun_product_id} product={p} allProducts={products} onSaved={onSaved} />)}
        {list.length === 0 && <p className="px-4 py-6 text-sm text-stone-500">None.</p>}
      </div>
    </Card>
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-bold text-stone-900"><FiPackage /> Products</h1>
          <p className="mt-1 text-stone-600">Tour or ticket, how long each tour lasts, and which product a copy counts as. Products come from Bokun bookings.</p>
        </div>
        <Button variant="outline" icon={FiRefreshCw} onClick={load}>Reload</Button>
      </div>
      {error && <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</div>}
      {products === null && !error && <p className="text-sm text-stone-500">Loading…</p>}
      {products && (
        <>
          {missing > 0 && (
            <p className="rounded-tuscan-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
              {missing} tour product{missing === 1 ? ' has' : 's have'} no duration — the assistant assumes 120 minutes for them.
            </p>
          )}
          {section('Tours', tours, 'Shown on the Tours page. Duration is used to work out who is free.')}
          {section('Tickets', tickets, 'Shown on Tickets / Priority Tickets, never as tours.')}
        </>
      )}
    </div>
  );
}
