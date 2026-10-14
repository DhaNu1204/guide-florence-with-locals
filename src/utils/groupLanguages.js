// Step 6.12: a guide speaks one language, so a departure is one language. Auto-grouping never
// mixes languages any more; a group that still does (a manual merge, or an auto group the
// migration left for the owner to decide) gets a visible "Mixed languages" chip.

// Distinct known languages of a group's active (non-cancelled) members, sorted.
export function groupMemberLanguages(group) {
  const langs = new Set();
  for (const t of (group && group.tours) || []) {
    if (!t || t.cancelled === true || t.cancelled === 1 || t.cancelled === '1') continue;
    const l = typeof t.language === 'string' ? t.language.trim() : '';
    if (l && l.toLowerCase() !== 'unknown') langs.add(l);
  }
  return [...langs].sort();
}

export function isMixedLanguageGroup(group) {
  return groupMemberLanguages(group).length > 1;
}

// Step 6.20: before a manual merge - the question to ask when the bookings speak more than one
// known language, or null when there is nothing to ask (bookings with no language, "Unknown"
// and cancelled bookings do not count, same rule as the chip).
export function mergeLanguageWarning(tours) {
  const langs = groupMemberLanguages({ tours });
  if (langs.length < 2) return null;
  return `These bookings are in different languages (${langs.join(', ')}). Merge anyway?`;
}

// Step 6.20: true = go on with the merge. `confirmFn` is window.confirm on the page; Cancel
// (false) means no merge. A one-language merge never asks.
export function confirmMergeLanguages(tours, confirmFn) {
  const question = mergeLanguageWarning(tours);
  return question === null ? true : Boolean(confirmFn(question));
}
