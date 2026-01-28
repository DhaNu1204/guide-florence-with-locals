/**
 * Button Component Tests
 * Florence With Locals - UI Component Tests
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import Button, { IconButton, FloatingActionButton } from '../UI/Button';
import { FiPlus, FiCheck } from 'react-icons/fi';

describe('Button Component', () => {
  // Basic rendering tests
  describe('Rendering', () => {
    it('renders children correctly', () => {
      render(<Button>Click me</Button>);
      expect(screen.getByText('Click me')).toBeInTheDocument();
    });

    it('renders with primary variant by default', () => {
      render(<Button>Primary</Button>);
      const button = screen.getByRole('button');
      expect(button).toHaveClass('bg-terracotta-500');
    });

    it('renders with different variants', () => {
      const { rerender } = render(<Button variant="secondary">Secondary</Button>);
      expect(screen.getByRole('button')).toHaveClass('bg-stone-600');

      rerender(<Button variant="success">Success</Button>);
      expect(screen.getByRole('button')).toHaveClass('bg-olive-500');

      rerender(<Button variant="danger">Danger</Button>);
      expect(screen.getByRole('button')).toHaveClass('bg-terracotta-600');
    });

    it('renders with different sizes', () => {
      const { rerender } = render(<Button size="sm">Small</Button>);
      expect(screen.getByRole('button')).toHaveClass('px-3');

      rerender(<Button size="lg">Large</Button>);
      expect(screen.getByRole('button')).toHaveClass('px-6');
    });

    it('renders full width when fullWidth prop is true', () => {
      render(<Button fullWidth>Full Width</Button>);
      expect(screen.getByRole('button')).toHaveClass('w-full');
    });
  });

  // Click event tests
  describe('Click Events', () => {
    it('handles click events', () => {
      const handleClick = vi.fn();
      render(<Button onClick={handleClick}>Click me</Button>);

      fireEvent.click(screen.getByText('Click me'));
      expect(handleClick).toHaveBeenCalledTimes(1);
    });

    it('does not trigger click when disabled', () => {
      const handleClick = vi.fn();
      render(<Button disabled onClick={handleClick}>Disabled</Button>);

      fireEvent.click(screen.getByText('Disabled'));
      expect(handleClick).not.toHaveBeenCalled();
    });

    it('does not trigger click when loading', () => {
      const handleClick = vi.fn();
      render(<Button loading onClick={handleClick}>Loading</Button>);

      const button = screen.getByRole('button');
      fireEvent.click(button);
      expect(handleClick).not.toHaveBeenCalled();
    });
  });

  // Loading state tests
  describe('Loading State', () => {
    it('shows loading spinner when loading is true', () => {
      render(<Button loading>Submit</Button>);
      const button = screen.getByRole('button');

      // Button should be disabled when loading
      expect(button).toBeDisabled();

      // Should have animate-spin class for the loader
      expect(button.querySelector('.animate-spin')).toBeInTheDocument();
    });

    it('is disabled when loading', () => {
      render(<Button loading>Loading</Button>);
      expect(screen.getByRole('button')).toBeDisabled();
    });
  });

  // Disabled state tests
  describe('Disabled State', () => {
    it('is disabled when disabled prop is true', () => {
      render(<Button disabled>Disabled</Button>);
      expect(screen.getByRole('button')).toBeDisabled();
    });

    it('has opacity class when disabled', () => {
      render(<Button disabled>Disabled</Button>);
      expect(screen.getByRole('button')).toHaveClass('disabled:opacity-50');
    });
  });

  // Icon tests
  describe('Icon Support', () => {
    it('renders icon on the left by default', () => {
      render(<Button icon={FiPlus}>Add</Button>);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('renders icon on the right when iconPosition is right', () => {
      render(<Button icon={FiCheck} iconPosition="right">Done</Button>);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('does not show icon when loading', () => {
      render(<Button icon={FiPlus} loading>Add</Button>);
      const button = screen.getByRole('button');
      // Should show loader instead of icon
      expect(button.querySelector('.animate-spin')).toBeInTheDocument();
    });
  });

  // Accessibility tests
  describe('Accessibility', () => {
    it('has proper button role', () => {
      render(<Button>Accessible</Button>);
      expect(screen.getByRole('button')).toBeInTheDocument();
    });

    it('supports aria-label', () => {
      render(<Button aria-label="Submit form">Submit</Button>);
      expect(screen.getByLabelText('Submit form')).toBeInTheDocument();
    });

    it('has minimum touch target size (44px)', () => {
      render(<Button>Touch Target</Button>);
      const button = screen.getByRole('button');
      expect(button).toHaveClass('min-h-[44px]');
    });
  });
});

describe('IconButton Component', () => {
  it('renders with icon only', () => {
    render(<IconButton icon={FiPlus} aria-label="Add item" />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('has minimum touch target size', () => {
    render(<IconButton icon={FiPlus} aria-label="Add" />);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('min-h-[44px]');
  });
});

describe('FloatingActionButton Component', () => {
  it('renders FAB with icon', () => {
    render(<FloatingActionButton icon={FiPlus} aria-label="Add" />);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('has fixed positioning', () => {
    render(<FloatingActionButton icon={FiPlus} aria-label="Add" />);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('fixed');
  });

  it('has proper size for FAB', () => {
    render(<FloatingActionButton icon={FiPlus} aria-label="Add" />);
    const button = screen.getByRole('button');
    expect(button).toHaveClass('w-14');
    expect(button).toHaveClass('h-14');
  });
});
