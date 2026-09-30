import React, { useEffect, useState } from 'react';
import { FiRefreshCw } from 'react-icons/fi';
import { startUpdateWatch, reloadToNewVersion } from '../utils/appUpdate';

/**
 * Step 4.10: "New version — tap to update". Shown when a newer build is live and the app could
 * not reload by itself (the user was already using it). See src/utils/appUpdate.js.
 */
const UpdateBanner = () => {
  const [server, setServer] = useState(null);

  useEffect(() => startUpdateWatch((build) => setServer(build)), []);

  if (!server) return null;
  return (
    <div
      role="status"
      data-testid="update-banner"
      className="fixed inset-x-0 bottom-0 z-[60] px-4 pb-[max(env(safe-area-inset-bottom),12px)] pt-2 pointer-events-none"
    >
      <div className="mx-auto max-w-md flex items-center justify-between gap-3 rounded-tuscan-lg bg-stone-900 text-white px-4 py-3 shadow-tuscan-lg pointer-events-auto">
        <span className="text-sm font-medium">A new version of the app is ready.</span>
        <button
          type="button"
          onClick={() => reloadToNewVersion(server)}
          className="min-h-[44px] px-4 rounded-tuscan bg-terracotta-600 hover:bg-terracotta-700 font-medium inline-flex items-center gap-2"
        >
          <FiRefreshCw className="h-4 w-4" aria-hidden="true" />
          Update
        </button>
      </div>
    </div>
  );
};

export default UpdateBanner;
