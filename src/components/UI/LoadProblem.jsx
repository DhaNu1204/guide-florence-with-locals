import React from 'react';
import { FiAlertTriangle, FiRefreshCw } from 'react-icons/fi';
import { describeLoadError, formatShownAt } from '../../services/netPolicy';
import { markUserRetry } from '../../utils/perfBeacon';

/**
 * Step 4.8: the one way a page says its data could not be loaded.
 *
 *  - `shownAt` set   -> the page still shows the last data that did arrive, and this banner
 *                       says so: "Could not refresh — showing data from 09:12".
 *  - `shownAt` unset -> nothing trustworthy to show: "Could not load the tickets." The page
 *                       must then NOT render an empty list underneath - "no tickets" and
 *                       "we don't know" are different answers.
 *
 * Both carry a Retry button (44 px, thumb-sized) and a one-line plain reason.
 */
const LoadProblem = ({ error, what = 'the data', shownAt = null, onRetry, retrying = false }) => {
  if (!error) return null;
  const reason = typeof error === 'string' ? error : describeLoadError(error);
  const stale = Boolean(shownAt);
  const handleRetry = () => {
    markUserRetry();
    if (onRetry) onRetry();
  };

  return (
    <div
      role="alert"
      data-testid={stale ? 'load-problem-stale' : 'load-problem'}
      className={`rounded-tuscan-lg border px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 ${
        stale ? 'border-gold-300 bg-gold-50 text-stone-800' : 'border-terracotta-200 bg-terracotta-50 text-terracotta-800'
      }`}
    >
      <div className="flex items-start gap-3">
        <FiAlertTriangle className="h-5 w-5 mt-0.5 flex-shrink-0" aria-hidden="true" />
        <div>
          <p className="font-medium">
            {stale
              ? `Could not refresh — showing data from ${formatShownAt(shownAt)}`
              : `Could not load ${what}.`}
          </p>
          <p className="text-sm opacity-90">{reason}</p>
        </div>
      </div>
      {onRetry && (
        <button
          type="button"
          onClick={handleRetry}
          disabled={retrying}
          className="min-h-[44px] px-4 rounded-tuscan bg-terracotta-600 text-white font-medium inline-flex items-center justify-center gap-2 hover:bg-terracotta-700 disabled:opacity-60"
        >
          <FiRefreshCw className={`h-4 w-4 ${retrying ? 'animate-spin' : ''}`} aria-hidden="true" />
          {retrying ? 'Retrying…' : 'Retry'}
        </button>
      )}
    </div>
  );
};

export default LoadProblem;
