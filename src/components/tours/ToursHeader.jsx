import React from 'react';
import { FiLayers, FiPlus, FiRefreshCw, FiDownload } from 'react-icons/fi';
import Button from '../base/Button';
import IconButton from '../base/IconButton';
import UpdatedStamp from '../UpdatedStamp';
import RowMenu from './RowMenu';

// Step 9.2: "Tours" + the updated stamp on the left; Auto-group, Add tour and Sync now on the
// right - the same three actions as the old header buttons - and a ⋯ page menu (Unassigned report,
// for when the attention strip is not shown).
const ToursHeader = ({ loadedAt, loading, autoGrouping, canAdd, onAutoGroup, onAdd, onSync, onReport }) => (
  <div className="flex flex-wrap items-center justify-between gap-3">
    <div className="flex items-baseline gap-3 min-w-0">
      <h1 className="text-2xl font-semibold text-ink">Tours</h1>
      <UpdatedStamp loadedAt={loadedAt} className="!text-[13px] !text-ink-2" />
    </div>
    <div className="flex items-center gap-2">
      <Button variant="secondary" onClick={onAutoGroup} disabled={autoGrouping || loading}>
        <FiLayers aria-hidden="true" className={`w-4 h-4 ${autoGrouping ? 'animate-spin' : ''}`} />
        {autoGrouping ? 'Grouping...' : 'Auto-group'}
      </Button>
      {canAdd && (
        <Button variant="secondary" onClick={onAdd} disabled={loading} data-testid="add-manual-tour-desktop">
          <FiPlus aria-hidden="true" className="w-4 h-4" />
          Add tour
        </Button>
      )}
      <IconButton
        icon={FiRefreshCw}
        label="Sync now"
        onClick={onSync}
        disabled={loading}
        iconClassName={`w-4 h-4 ${loading ? 'animate-spin' : ''}`}
        className="!w-9 !h-9 !bg-accent !text-white hover:!bg-accent-hover"
      />
      <RowMenu label="More page actions" items={[{ label: 'Unassigned report', icon: FiDownload, onClick: onReport, testId: 'page-menu-unassigned-report' }]} />
    </div>
  </div>
);

export default ToursHeader;
