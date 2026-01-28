/**
 * Login Page Tests
 * Florence With Locals - Authentication Tests
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { BrowserRouter } from 'react-router-dom';
import { AuthProvider } from '../../contexts/AuthContext';
import Login from '../Login';

// Mock fetch for auth verification
global.fetch = vi.fn(() =>
  Promise.resolve({
    ok: false,
    json: () => Promise.resolve({ error: 'Unauthorized' }),
  })
);

// Mock useNavigate
const mockNavigate = vi.fn();
vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual('react-router-dom');
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  };
});

// Helper to render with providers
const renderLogin = () => {
  return render(
    <BrowserRouter>
      <AuthProvider>
        <Login />
      </AuthProvider>
    </BrowserRouter>
  );
};

describe('Login Page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
  });

  // Form rendering tests
  describe('Rendering', () => {
    it('renders the login form title', () => {
      renderLogin();
      expect(screen.getByText('Florence with Locals')).toBeInTheDocument();
    });

    it('renders the subtitle', () => {
      renderLogin();
      expect(screen.getByText('Tour Management System')).toBeInTheDocument();
    });

    it('renders username input', () => {
      renderLogin();

      const usernameInput = screen.getByPlaceholderText('Username');
      expect(usernameInput).toBeInTheDocument();
      expect(usernameInput).toHaveAttribute('type', 'text');
    });

    it('renders password input', () => {
      renderLogin();

      const passwordInput = screen.getByPlaceholderText('Password');
      expect(passwordInput).toBeInTheDocument();
      expect(passwordInput).toHaveAttribute('type', 'password');
    });

    it('renders sign in button', () => {
      renderLogin();
      expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
    });
  });

  // Input validation tests
  describe('Input Handling', () => {
    it('username input is required', () => {
      renderLogin();
      const usernameInput = screen.getByPlaceholderText('Username');
      expect(usernameInput).toBeRequired();
    });

    it('password input is required', () => {
      renderLogin();
      const passwordInput = screen.getByPlaceholderText('Password');
      expect(passwordInput).toBeRequired();
    });

    it('allows typing in username field', async () => {
      const user = userEvent.setup();
      renderLogin();

      const usernameInput = screen.getByPlaceholderText('Username');
      await user.type(usernameInput, 'testuser');

      expect(usernameInput).toHaveValue('testuser');
    });

    it('allows typing in password field', async () => {
      const user = userEvent.setup();
      renderLogin();

      const passwordInput = screen.getByPlaceholderText('Password');
      await user.type(passwordInput, 'testpassword');

      expect(passwordInput).toHaveValue('testpassword');
    });
  });

  // UI/UX tests
  describe('UI/UX', () => {
    it('has accessible labels for inputs', () => {
      renderLogin();

      // sr-only labels
      expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
      expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    });

    it('button shows Sign in text', () => {
      renderLogin();

      const submitButton = screen.getByRole('button');
      expect(submitButton).toHaveTextContent('Sign in');
    });

    it('submit button has correct type', () => {
      renderLogin();

      const submitButton = screen.getByRole('button', { name: /sign in/i });
      expect(submitButton).toHaveAttribute('type', 'submit');
    });
  });

  // Accessibility tests
  describe('Accessibility', () => {
    it('form element exists', () => {
      renderLogin();
      expect(document.querySelector('form')).toBeInTheDocument();
    });

    it('inputs have proper IDs', () => {
      renderLogin();

      const usernameInput = screen.getByPlaceholderText('Username');
      const passwordInput = screen.getByPlaceholderText('Password');

      expect(usernameInput).toHaveAttribute('id', 'username');
      expect(passwordInput).toHaveAttribute('id', 'password');
    });

    it('labels are properly associated with inputs', () => {
      renderLogin();

      // Using aria/label association
      expect(screen.getByLabelText(/username/i)).toBe(
        screen.getByPlaceholderText('Username')
      );
      expect(screen.getByLabelText(/password/i)).toBe(
        screen.getByPlaceholderText('Password')
      );
    });
  });

  // Form structure tests
  describe('Form Structure', () => {
    it('has a form with correct structure', () => {
      renderLogin();

      const form = document.querySelector('form');
      expect(form).toBeInTheDocument();

      // Check inputs are inside form
      const usernameInput = screen.getByPlaceholderText('Username');
      const passwordInput = screen.getByPlaceholderText('Password');

      expect(form.contains(usernameInput)).toBe(true);
      expect(form.contains(passwordInput)).toBe(true);
    });
  });
});
