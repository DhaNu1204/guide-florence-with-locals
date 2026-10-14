/**
 * Step 6.19: the assistant's save call tells the server whether it is a confirm or an undo,
 * so the guide log can record source = assistant / assistant_undo.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../authFetch', () => ({ authFetch: vi.fn() }));
vi.mock('../mysqlDB', () => ({
  default: { updateTour: vi.fn() },
  tourGroupsAPI: { update: vi.fn() },
}));

import mysqlDB, { tourGroupsAPI } from '../mysqlDB';
import { saveDepartureGuide } from '../assistantService';

describe('saveDepartureGuide', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mysqlDB.updateTour.mockResolvedValue({});
    tourGroupsAPI.update.mockResolvedValue({});
  });

  it('confirm on a single tour: no assistant_undo flag', async () => {
    const res = await saveDepartureGuide({ type: 'tour', id: 6428 }, 14, null);
    expect(res).toEqual({ ok: true });
    expect(mysqlDB.updateTour).toHaveBeenCalledWith(6428, { guide_id: 14, expected_previous_guide_id: null });
  });

  it('undo on a group sends assistant_undo: true', async () => {
    await saveDepartureGuide({ type: 'group', id: 42 }, null, 7, { force: true, undo: true });
    expect(tourGroupsAPI.update).toHaveBeenCalledWith(42, { guide_id: null, expected_previous_guide_id: 7, assistant_undo: true });
  });

  it('undo on a single tour keeps force and adds assistant_undo', async () => {
    await saveDepartureGuide({ type: 'tour', id: 900 }, null, 7, { force: true, undo: true });
    expect(mysqlDB.updateTour).toHaveBeenCalledWith(900, { guide_id: null, expected_previous_guide_id: 7, assistant_undo: true, force: true });
  });
});
