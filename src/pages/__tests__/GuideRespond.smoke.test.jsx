/**
 * Render smoke test for the public guide Accept/Decline page (/respond/:token).
 * Uses a mocked global fetch (this page uses plain window.fetch, not axios) and
 * mounts under a MemoryRouter route so useParams() resolves the token.
 */
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

import GuideRespond from '../GuideRespond';

beforeEach(() => {
  global.fetch = vi.fn().mockResolvedValue({
    ok: true,
    status: 200,
    json: async () => ({
      success: true,
      status: 'pending',
      guide_name: 'Mario',
      tour: { date: '2026-06-16', time: '10:00', title: 'Test Tour', language: 'English', participants: 2 },
    }),
  });
});

describe('GuideRespond page', () => {
  it('renders without throwing', async () => {
    render(
      <MemoryRouter initialEntries={['/respond/test-token']}>
        <Routes>
          <Route path="/respond/:token" element={<GuideRespond />} />
        </Routes>
      </MemoryRouter>
    );
    // Branding heading is present in every phase (loading, pending, result) via the page shell
    const branding = await screen.findAllByText('Florence with Locals');
    expect(branding.length).toBeGreaterThan(0);
  });
});
