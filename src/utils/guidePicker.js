// Step 7.2b: which guides a dropdown offers for NEW work.
// An inactive guide (guides.active = 0) is left out - except the one already assigned, which is
// kept (labelled "(inactive)") so the dropdown keeps showing it and nothing is unassigned silently.
// History views (payments, reports, filters) use the full list, not this.

/** Missing flag = active (rows from before step 7.2b). */
export const isGuideActive = (g) => !g || g.active === undefined || g.active === null || Number(g.active) !== 0;

/**
 * @param guides   the full guide list
 * @param keepIds  ids already assigned to what is being edited (strings or numbers; empty values ignored)
 */
export const pickableGuides = (guides, ...keepIds) => {
  const keep = new Set(keepIds.filter((v) => v !== undefined && v !== null && v !== '').map(String));
  return (guides || [])
    .filter((g) => isGuideActive(g) || keep.has(String(g.id)))
    .map((g) => (isGuideActive(g) ? g : { ...g, name: `${g.name} (inactive)` }));
};
