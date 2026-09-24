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
