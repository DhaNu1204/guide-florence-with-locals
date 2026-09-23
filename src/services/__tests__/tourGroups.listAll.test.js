/**
 * Step 4.9: every upcoming group arrives in ONE request (it used to be a serial walk of
 * 100-per-page requests - two round trips on production, which has 102 upcoming groups).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('axios', () => ({
  default: {
    get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(),
    interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
  },
}));

import axios from 'axios';
import { tourGroupsAPI } from '../mysqlDB';

const page = (from, count, total, perPage = 500) => {
  const pageNo = Math.floor(from / perPage) + 1;
  const totalPages = Math.ceil(total / perPage);
  return {
    data: {
      data: Array.from({ length: count }, (_, i) => ({ id: from + i + 1 })),
      pagination: { current_page: pageNo, per_page: perPage, total, total_pages: totalPages, has_next: pageNo < totalPages },
    },
  };
};
const params = (call) => new URL(call[0]).searchParams;

beforeEach(() => { vi.clearAllMocks(); });

describe('tourGroupsAPI.listAll (step 4.9)', () => {
  it('102 upcoming groups arrive in one request of up to 500', async () => {
    axios.get.mockResolvedValueOnce(page(0, 102, 102));
    const res = await tourGroupsAPI.listAll({ upcoming: 'true' });
    expect(axios.get).toHaveBeenCalledTimes(1);
    const p = params(axios.get.mock.calls[0]);
    expect(p.get('per_page')).toBe('500');
    expect(p.get('page')).toBe('1');
    expect(p.get('upcoming')).toBe('true');
    expect(res.data).toHaveLength(102);
  });

  it('a range larger than one page fetches the remaining pages in PARALLEL, not one after another', async () => {
    const pending = [];
    axios.get.mockResolvedValueOnce(page(0, 500, 1200));
    axios.get.mockImplementation(() => new Promise((resolve) => pending.push(resolve)));
    const result = tourGroupsAPI.listAll({ start_date: '2026-01-01', end_date: '2026-12-31' });
    await vi.waitFor(() => expect(axios.get).toHaveBeenCalledTimes(3));
    // pages 2 and 3 were both asked for before either answered
    expect(pending).toHaveLength(2);
    expect(axios.get.mock.calls.slice(1).map((c) => params(c).get('page')).sort()).toEqual(['2', '3']);
    pending[0](page(500, 500, 1200));
    pending[1](page(1000, 200, 1200));
    const res = await result;
    expect(res.data).toHaveLength(1200);
  });

  it('an incomplete answer is still an error, never a half list (step 5.2)', async () => {
    axios.get.mockResolvedValueOnce({ data: { data: [{ id: 1 }], pagination: { total: 163, total_pages: 1, has_next: false } } });
    await expect(tourGroupsAPI.listAll({ upcoming: 'true' })).rejects.toThrow('Tour groups incomplete: received 1 of 163');
  });
});
