/**
 * Step 9.1: base components (docs/DESIGN.md) - one render test each.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { FiMoreHorizontal } from 'react-icons/fi';
import Button from '../base/Button';
import IconButton from '../base/IconButton';
import Pill, { PILL_TONES } from '../base/Pill';
import Avatar, { initials } from '../base/Avatar';
import SectionHeader from '../base/SectionHeader';
import Toolbar from '../base/Toolbar';

describe('base components (step 9.1)', () => {
  it('Button renders each variant with the 36 px / 44 px touch sizing', () => {
    const { rerender } = render(<Button>Save</Button>);
    const btn = screen.getByRole('button', { name: 'Save' });
    expect(btn).toHaveAttribute('type', 'button');
    expect(btn.className).toContain('bg-accent');
    expect(btn.className).toContain('h-9');
    expect(btn.className).toContain('[@media(pointer:coarse)]:h-11');
    rerender(<Button variant="danger-outline">Remove</Button>);
    expect(screen.getByRole('button', { name: 'Remove' }).className).toContain('border-danger-border');
    rerender(<Button variant="secondary">Cancel</Button>);
    expect(screen.getByRole('button', { name: 'Cancel' }).className).toContain('border-line');
    rerender(<Button variant="ghost">More</Button>);
    expect(screen.getByRole('button', { name: 'More' }).className).toContain('bg-transparent');
  });

  it('IconButton is named by its label and has a 32 px / 44 px hit area', () => {
    render(<IconButton icon={FiMoreHorizontal} label="More actions" />);
    const btn = screen.getByRole('button', { name: 'More actions' });
    expect(btn.className).toContain('w-8');
    expect(btn.className).toContain('[@media(pointer:coarse)]:w-11');
  });

  it('Pill renders every tone with its token colours', () => {
    expect(Object.keys(PILL_TONES)).toEqual(['need', 'resched', 'full', 'manual', 'cancelled', 'lang', 'note']);
    render(<Pill tone="need">Needs guide</Pill>);
    expect(screen.getByText('Needs guide').className).toContain('bg-danger-pill');
  });

  it('Avatar shows initials', () => {
    render(<Avatar name="Maria Rossi" />);
    expect(screen.getByText('MR')).toBeInTheDocument();
    expect(initials('dhanu')).toBe('D');
    expect(initials('')).toBe('?');
  });

  it('SectionHeader renders title and meta', () => {
    render(<SectionHeader title="Morning" meta="6 tours · 22 PAX" />);
    expect(screen.getByRole('heading', { name: 'Morning' })).toBeInTheDocument();
    expect(screen.getByText('6 tours · 22 PAX')).toBeInTheDocument();
  });

  it('Toolbar is a wrapping surface row', () => {
    render(<Toolbar><button>A</button></Toolbar>);
    const bar = screen.getByRole('toolbar');
    expect(bar.className).toContain('flex-wrap');
    expect(bar.className).toContain('bg-surface');
  });
});
