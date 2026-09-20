/**
 * Step 5.3 (§2.10): a batch payment must say what it is about to write.
 * Paying three tours at €50 records €150, and before this step neither the word "per tour" nor
 * the number €150 appeared anywhere on the screen.
 */
import { describe, it, expect } from 'vitest';
import {
  buildBatchPlan,
  batchTotalLine,
  batchButtonLabel,
  batchRequests,
  summariseResults,
  formatEuro,
  tourUnitKey,
} from '../batchPayment';

const tour = (id, guideId, guideName, extra = {}) => ({
  id, guide_id: guideId, guide_name: guideName, is_group: false, ...extra,
});

describe('buildBatchPlan (step 5.3)', () => {
  it('3 tours at €50 is €150 in total, and the per-tour amount stays €50', () => {
    const plan = buildBatchPlan([tour(1, 7, 'Anna'), tour(2, 7, 'Anna'), tour(3, 7, 'Anna')], '50');
    expect(plan.count).toBe(3);
    expect(plan.amount).toBe(50);
    expect(plan.total).toBe(150);
    expect(plan.canSubmit).toBe(true);
    expect(batchTotalLine(plan)).toBe('3 tours × €50.00 = €150.00 total');
    expect(batchButtonLabel(plan)).toBe('Record €150.00');
  });

  it('the total follows the selection', () => {
    const tours = [tour(1, 7, 'Anna'), tour(2, 7, 'Anna'), tour(3, 7, 'Anna')];
    expect(buildBatchPlan(tours.slice(0, 1), '50').total).toBe(50);
    expect(buildBatchPlan(tours.slice(0, 2), '50').total).toBe(100);
    expect(buildBatchPlan(tours, '50').total).toBe(150);
    expect(batchTotalLine(buildBatchPlan(tours.slice(0, 1), '50'))).toBe('1 tour × €50.00 = €50.00 total');
  });

  it('the total follows the amount', () => {
    const tours = [tour(1, 7, 'Anna'), tour(2, 7, 'Anna')];
    expect(buildBatchPlan(tours, '45.50').total).toBe(91);
    expect(buildBatchPlan(tours, '33.33').total).toBe(66.66);
  });

  it('two guides selected: the split is shown and neither total is implied', () => {
    const plan = buildBatchPlan(
      [tour(1, 7, 'Anna'), tour(2, 7, 'Anna'), tour(3, 9, 'Caterina')], '50'
    );
    expect(plan.mixed).toBe(true);
    expect(plan.total).toBe(150);
    expect(plan.groups).toHaveLength(2);
    expect(plan.groups[0]).toMatchObject({ guideId: 7, guideName: 'Anna', count: 2, subtotal: 100 });
    expect(plan.groups[1]).toMatchObject({ guideId: 9, guideName: 'Caterina', count: 1, subtotal: 50 });
    expect(plan.groups.reduce((s, g) => s + g.subtotal, 0)).toBe(plan.total);
  });

  it('a tour is ALWAYS paid to its own guide - the override cannot move it (§2.10)', () => {
    const plan = buildBatchPlan([tour(1, 7, 'Anna'), tour(2, 9, 'Caterina')], '50', '7');
    const requests = batchRequests(plan, {
      paymentMethod: 'cash', paymentDate: '2026-09-20', paymentTime: '10:00', reference: null,
    });
    expect(requests.map(r => [r.tour_id, r.guide_id])).toEqual([[1, 7], [2, 9]]);
  });

  it('a tour with no guide uses the chosen one, and blocks until one is chosen', () => {
    const none = buildBatchPlan([tour(1, 7, 'Anna'), tour(2, null, null)], '50');
    expect(none.unassignedCount).toBe(1);
    expect(none.needsGuideChoice).toBe(true);
    expect(none.canSubmit).toBe(false);
    expect(none.blocker).toMatch(/guide/i);

    const chosen = buildBatchPlan([tour(1, 7, 'Anna'), tour(2, null, null)], '50', '9');
    expect(chosen.canSubmit).toBe(true);
    const requests = batchRequests(chosen, { paymentMethod: 'cash', paymentDate: '2026-09-20', paymentTime: '10:00' });
    expect(requests.map(r => [r.tour_id, r.guide_id])).toEqual([[1, 7], [2, 9]]);
  });

  it('an invalid amount is refused and nothing is totalled', () => {
    for (const bad of ['abc', '0', '-5', '', null, undefined]) {
      const plan = buildBatchPlan([tour(1, 7, 'Anna')], bad);
      expect(plan.amountValid).toBe(false);
      expect(plan.canSubmit).toBe(false);
      expect(plan.total).toBe(0);
      expect(batchButtonLabel(plan)).toBe('Record Payment');
    }
    expect(batchTotalLine(buildBatchPlan([tour(1, 7, 'Anna')], 'abc'))).toBe('1 tour selected');
  });

  it('no selection means nothing to record', () => {
    const plan = buildBatchPlan([], '50');
    expect(plan.canSubmit).toBe(false);
    expect(plan.blocker).toMatch(/select/i);
    expect(batchTotalLine(plan)).toBe('');
  });

  it('a group counts as ONE departure (1 group = 1 payment)', () => {
    const group = { id: 11, group_id: 4, is_group: true, guide_id: 7, guide_name: 'Anna' };
    const plan = buildBatchPlan([group, tour(2, 7, 'Anna')], '50');
    expect(plan.count).toBe(2);
    expect(plan.total).toBe(100);
    const requests = batchRequests(plan, { paymentMethod: 'cash', paymentDate: '2026-09-20', paymentTime: '10:00' });
    expect(requests).toHaveLength(2);
    expect(requests[0].tour_id).toBe(11); // the group's first tour; the server expands it
    expect(requests.every(r => r.force_group_payment === false)).toBe(true);
    expect(tourUnitKey(group)).toBe('g4');
  });
});

describe('summariseResults (step 5.3)', () => {
  it('reports what the server said it wrote, not what we asked for', () => {
    const s = summariseResults([
      { ok: true, amount: 50 }, { ok: true, amount: 50 }, { ok: true, amount: 50 },
    ]);
    expect(s).toMatchObject({ writtenCount: 3, failedCount: 0, total: 150 });
  });

  it('a partly failed batch totals only the rows that were written', () => {
    const s = summariseResults([
      { ok: true, amount: 50 },
      { ok: false, error: 'Duplicate tour payment' },
      { ok: true, amount: 50 },
    ]);
    expect(s).toMatchObject({ writtenCount: 2, failedCount: 1, total: 100 });
    expect(s.firstError).toBe('Duplicate tour payment');
  });
});

describe('formatEuro', () => {
  it('always two decimals', () => {
    expect(formatEuro(50)).toBe('€50.00');
    expect(formatEuro(150.5)).toBe('€150.50');
    expect(formatEuro('abc')).toBe('€0.00');
  });
});
