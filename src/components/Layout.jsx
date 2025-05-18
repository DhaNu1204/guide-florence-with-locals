import React from 'react';
import Navigation from './Navigation';

const Layout = ({ children }) => {
  return (
    <div className="flex flex-col min-h-screen bg-gray-100">
      <Navigation />
      
      <main className="flex-grow container mx-auto px-4 py-8">
        {children}
      </main>
      
      <footer className="bg-gray-50 text-gray-600 p-4 border-t border-gray-200">
        <div className="container mx-auto text-center text-sm">
          <p>Florence with Locals &copy; {new Date().getFullYear()}</p>
          <p className="text-xs mt-1">contact@florencewithlocals.com • +39 327 249 1282</p>
        </div>
      </footer>
    </div>
  );
};

export default Layout; 