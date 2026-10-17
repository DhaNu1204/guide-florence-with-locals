import React from 'react';
import Toolbar from '../base/Toolbar';
import DateFilter from '../DateFilter';

// Step 9.2: one toolbar card instead of the Filters card - the same date state and setters as
// before (DateFilter's toolbar variant), the same guide and language selects. Wraps, never scrolls.
const selectCls = 'h-9 px-2 pr-7 text-sm border border-line rounded-lg bg-surface text-ink focus:outline-none focus:ring-2 focus:ring-accent';

const ToursToolbar = ({ dateProps, guides, selectedGuideId, onGuide, languageOptions, selectedLanguage, onLanguage }) => (
  <Toolbar aria-label="Tour filters">
    <DateFilter {...dateProps} variant="toolbar" />
    <div className="flex flex-wrap items-center gap-2 ml-auto">
      <select value={selectedGuideId} onChange={(e) => onGuide(e.target.value)} aria-label="Filter by guide" className={selectCls}>
        <option value="all">All guides</option>
        {guides.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
      </select>
      <select value={selectedLanguage} onChange={(e) => onLanguage(e.target.value)} aria-label="Filter by language"
        className={selectCls} data-testid="tours-language-filter-desktop">
        <option value="all">All languages</option>
        {languageOptions.map((l) => <option key={l.language} value={l.language}>{l.language} ({l.departures})</option>)}
      </select>
    </div>
  </Toolbar>
);

export default ToursToolbar;
