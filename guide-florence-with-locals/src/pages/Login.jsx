import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

export default function Login() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      const result = await login(username, password);
      if (result.success) {
        navigate('/');
      } else {
        setError(result.message);
      }
    } catch (err) {
      setError('An error occurred. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-tuscan-gradient py-8 md:py-12 px-4 sm:px-6 lg:px-8">
      <div className="max-w-md w-full space-y-6 md:space-y-8">
        <div>
          <h2 className="mt-4 md:mt-6 text-center text-2xl md:text-3xl font-extrabold text-stone-900">
            Florence with Locals
          </h2>
          <p className="mt-2 text-center text-sm text-terracotta-600">
            Tour Management System
          </p>
        </div>
        <form className="mt-6 md:mt-8 space-y-5 md:space-y-6" onSubmit={handleSubmit}>
          <div className="rounded-tuscan-lg shadow-tuscan -space-y-px">
            <div>
              <label htmlFor="username" className="sr-only">
                Username
              </label>
              <input
                id="username"
                name="username"
                type="text"
                required
                className="appearance-none rounded-none relative block w-full px-4 py-3 min-h-[48px] border border-stone-300 placeholder-stone-500 text-stone-900 text-base rounded-t-tuscan-lg focus:outline-none focus:ring-terracotta-500 focus:border-terracotta-500 focus:z-10"
                placeholder="Username"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
              />
            </div>
            <div>
              <label htmlFor="password" className="sr-only">
                Password
              </label>
              <input
                id="password"
                name="password"
                type="password"
                required
                className="appearance-none rounded-none relative block w-full px-4 py-3 min-h-[48px] border border-stone-300 placeholder-stone-500 text-stone-900 text-base rounded-b-tuscan-lg focus:outline-none focus:ring-terracotta-500 focus:border-terracotta-500 focus:z-10"
                placeholder="Password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
            </div>
          </div>

          {error && (
            <div className="text-terracotta-600 text-sm text-center">{error}</div>
          )}

          <div>
            <button
              type="submit"
              disabled={loading}
              className="group relative w-full flex justify-center py-3 px-4 min-h-[48px] border border-transparent text-base font-medium rounded-tuscan-lg text-white bg-terracotta-500 hover:bg-terracotta-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-terracotta-500 shadow-tuscan hover:shadow-tuscan-md transition-all duration-200 touch-manipulation"
            >
              {loading ? 'Signing in...' : 'Sign in'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
