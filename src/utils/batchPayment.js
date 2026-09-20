/**
 * Step 5.3: what a batch "Record Payment" is actually about to write.
 *
 * The form takes ONE amount and posts it once per selected departure, so paying three tours at
 * €50 writes €150 - and before this step the screen said neither "per tour" nor "€150". It also
 * let a batch spanning two guides be recorded against a single chosen guide, which silently
 * attributed one guide's money to another (finding §2.10).
 *
 * This module holds the arithmetic and the grouping so the page can state both plainly, and so
 * they can be tested without a browser.
 */

export const formatEuro = (value) => {
  const n = Number(value);
  return `€${(Number.isFinite(n) ? n : 0).toFixed(2)}`;
};

/** The key the Payments page uses for a selected departure (1 group = 1 unit). */
export const tourUnitKey = (tour) => (tour.is_group ? `g${tour.group_id}` : `t${tour.id}`);

/**
 * Build the plan for a batch submit.
 *
 * @param {Array}  selectedTours     the selected unpaid departures (as the pending list returns them)
 * @param {string|number} amountInput what the owner typed in "Amount per tour"
 * @param {string|number} overrideGuideId guide chosen for departures that have none
 * @returns {{
 *   count:number, amount:number, total:number, amountValid:boolean,
 *   groups:Array<{guideId:(number|null), guideName:string, tours:Array, count:number, subtotal:number}>,
 *   mixed:boolean, unassignedCount:number, needsGuideChoice:boolean, canSubmit:boolean, blocker:(string|null)
 * }}
 */
export function buildBatchPlan(selectedTours, amountInput, overrideGuideId) {
  const tours = Array.isArray(selectedTours) ? selectedTours : [];
  const amount = parseFloat(amountInput);
  // Same rule as the server (step 3.8): a number, strictly greater than zero.
  const amountValid = Number.isFinite(amount) && amount > 0;
  const count = tours.length;

  const override = overrideGuideId === '' || overrideGuideId === null || overrideGuideId === undefined
    ? null
    : parseInt(overrideGuideId, 10);

  // One bucket per guide, in the order the departures appear, so the split reads like the list.
  const byGuide = new Map();
  let unassignedCount = 0;
  tours.forEach((tour) => {
    const ownGuide = tour.guide_id ? parseInt(tour.guide_id, 10) : null;
    if (ownGuide === null) { unassignedCount += 1; }
    // A departure without a guide falls to the chosen one; a departure WITH a guide is always
    // paid to that guide - the override must never move someone else's money.
    const guideId = ownGuide !== null ? ownGuide : (Number.isFinite(override) ? override : null);
    const key = guideId === null ? 'none' : String(guideId);
    if (!byGuide.has(key)) {
      byGuide.set(key, {
        guideId,
        guideName: (ownGuide !== null ? tour.guide_name : null) || null,
        tours: [],
      });
    }
    const bucket = byGuide.get(key);
    if (!bucket.guideName && tour.guide_name && ownGuide !== null) { bucket.guideName = tour.guide_name; }
    bucket.tours.push(tour);
  });

  const groups = [...byGuide.values()].map((g) => ({
    ...g,
    guideName: g.guideName || (g.guideId === null ? 'No guide selected' : `Guide #${g.guideId}`),
    count: g.tours.length,
    subtotal: amountValid ? Number((amount * g.tours.length).toFixed(2)) : 0,
  }));

  const total = amountValid ? Number((amount * count).toFixed(2)) : 0;
  const mixed = groups.filter((g) => g.guideId !== null).length > 1;
  const needsGuideChoice = unassignedCount > 0 && !Number.isFinite(override);

  let blocker = null;
  if (count === 0) { blocker = 'Select at least one tour'; }
  else if (!amountValid) { blocker = 'Enter an amount per tour greater than 0'; }
  else if (needsGuideChoice) { blocker = 'Choose a guide for the tours that have none'; }

  return {
    count,
    amount: amountValid ? amount : 0,
    total,
    amountValid,
    groups,
    mixed,
    unassignedCount,
    needsGuideChoice,
    canSubmit: blocker === null,
    blocker,
  };
}

/** "3 tours × €50.00 = €150.00 total" - the line under the amount field. */
export function batchTotalLine(plan) {
  if (!plan || plan.count === 0) { return ''; }
  const tourWord = plan.count === 1 ? 'tour' : 'tours';
  if (!plan.amountValid) { return `${plan.count} ${tourWord} selected`; }
  return `${plan.count} ${tourWord} × ${formatEuro(plan.amount)} = ${formatEuro(plan.total)} total`;
}

/** The confirm button always states what will be written. */
export function batchButtonLabel(plan) {
  if (!plan || !plan.amountValid || plan.count === 0) { return 'Record Payment'; }
  return `Record ${formatEuro(plan.total)}`;
}

/** The POST body for one departure, paid to that departure's own guide. */
export function batchRequests(plan, { paymentMethod, paymentDate, paymentTime, reference }) {
  const requests = [];
  plan.groups.forEach((group) => {
    group.tours.forEach((tour) => {
      requests.push({
        tour_id: parseInt(tour.id, 10),
        guide_id: group.guideId,
        amount: plan.amount,
        payment_method: paymentMethod,
        payment_date: paymentDate,
        payment_time: paymentTime,
        transaction_reference: reference || null,
        force_group_payment: false,
      });
    });
  });
  return requests;
}

/** What actually got written, for a confirmation the owner can trust. */
export function summariseResults(results) {
  const written = results.filter((r) => r.ok);
  const failed = results.filter((r) => !r.ok);
  const total = written.reduce((sum, r) => sum + (Number(r.amount) || 0), 0);
  return {
    writtenCount: written.length,
    failedCount: failed.length,
    total: Number(total.toFixed(2)),
    firstError: failed.length ? failed[0].error : null,
  };
}
