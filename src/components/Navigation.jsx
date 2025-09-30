import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { usePageTitle } from '../contexts/PageTitleContext';

const Navigation = () => {
  const { pageTitle } = usePageTitle();
  const location = useLocation();
  
  const isActive = (path) => {
    return location.pathname === path;
  };
  
  return (
    <nav className="bg-white border-b border-gray-200 shadow-sm">
      <div className="container mx-auto px-4 py-3">
        <div className="flex justify-between items-center">
          {/* Logo - Clickable Link */}
          <Link to="/" className="text-xl font-semibold text-gray-800 hover:text-purple-600 transition-colors">
            Florence With Locals
          </Link>
          
          {/* Navigation Links */}
          <ul className="flex space-x-6">
            <li>
              <Link 
                to="/" 
                className={`transition-colors duration-200 ${
                  isActive('/') ? 'text-purple-600 font-medium' : 'text-gray-600 hover:text-purple-600'
                }`}
              >
                Tours
              </Link>
            </li>
            <li>
              <Link 
                to="/guides" 
                className={`transition-colors duration-200 ${
                  isActive('/guides') ? 'text-purple-600 font-medium' : 'text-gray-600 hover:text-purple-600'
                }`}
              >
                Guides
              </Link>
            </li>
            <li>
              <Link
                to="/tickets"
                className={`transition-colors duration-200 ${
                  isActive('/tickets') ? 'text-purple-600 font-medium' : 'text-gray-600 hover:text-purple-600'
                }`}
              >
                Tickets
              </Link>
            </li>
            <li>
              <Link
                to="/payments"
                className={`transition-colors duration-200 ${
                  isActive('/payments') ? 'text-purple-600 font-medium' : 'text-gray-600 hover:text-purple-600'
                }`}
              >
                Payments
              </Link>
            </li>
          </ul>
          
          {/* Page Title */}
          {pageTitle && (
            <h2 className="text-xl font-bold text-gray-800">
              {pageTitle}
            </h2>
          )}
        </div>
      </div>
    </nav>
  );
};

export default Navigation;