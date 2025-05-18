import React from 'react';
import { Link, useLocation } from 'react-router-dom';
import { usePageTitle } from '../contexts/PageTitleContext';

const Navigation = () => {
  const { pageTitle } = usePageTitle();
  const location = useLocation();
  
  return (
    <nav className="bg-white border-b border-gray-200 shadow-sm">
      <div className="container mx-auto px-4 py-3">
        <div className="flex justify-between items-center">
          {/* Logo - Clickable Link */}
          <Link to="/" className="text-xl font-semibold text-gray-800 hover:text-purple-600 transition-colors">
            Florence With Locals
          </Link>
          
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