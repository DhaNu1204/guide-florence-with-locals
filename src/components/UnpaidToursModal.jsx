import React, { useEffect, useState } from 'react';
import { format } from 'date-fns';

const UnpaidToursModal = ({ unpaidTours, onClose, onPaidToggle, calculatePrice }) => {
  // Add pagination state
  const [currentPage, setCurrentPage] = useState(1);
  const [toursPerPage] = useState(10);

  // Auto-close the modal if all tours have been marked as paid
  useEffect(() => {
    if (unpaidTours.length === 0) {
      onClose();
    }
  }, [unpaidTours.length, onClose]);

  const handlePaidToggle = (tourId, currentPaidStatus) => {
    onPaidToggle(tourId, currentPaidStatus);
  };

  // Calculate the total amount to pay
  const totalAmount = unpaidTours.reduce((total, tour) => {
    return total + calculatePrice(tour.duration);
  }, 0);

  // Get current page of tours
  const indexOfLastTour = currentPage * toursPerPage;
  const indexOfFirstTour = indexOfLastTour - toursPerPage;
  const currentTours = unpaidTours.slice(indexOfFirstTour, indexOfLastTour);
  const totalPages = Math.ceil(unpaidTours.length / toursPerPage);

  // Create pagination range with ellipsis
  const getPaginationRange = () => {
    const delta = 1; // Number of page buttons to show on each side of current page
    const range = [];
    
    // Always include first page
    range.push(1);
    
    // Calculate start and end of range around current page
    let start = Math.max(2, currentPage - delta);
    let end = Math.min(totalPages - 1, currentPage + delta);
    
    // Add ellipsis before range if needed
    if (start > 2) {
      range.push('...');
    }
    
    // Add pages in range
    for (let i = start; i <= end; i++) {
      range.push(i);
    }
    
    // Add ellipsis after range if needed
    if (end < totalPages - 1) {
      range.push('...');
    }
    
    // Always include last page if it exists and isn't already in range
    if (totalPages > 1) {
      range.push(totalPages);
    }
    
    return range;
  };
  
  // Generate pagination range
  const paginationRange = totalPages > 5 ? getPaginationRange() : [...Array(totalPages)].map((_, i) => i + 1);

  // Handle page change
  const handlePageChange = (pageNumber) => {
    setCurrentPage(pageNumber);
  };

  return (
    <div className="fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div className="p-4 border-b flex justify-between items-center bg-red-50">
          <h2 className="text-xl font-bold text-gray-800 flex items-center">
            <svg className="h-6 w-6 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            Completed Unpaid Tours ({unpaidTours.length})
          </h2>
          <button 
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700"
          >
            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        
        <div className="overflow-y-auto flex-grow">
          {unpaidTours.length === 0 ? (
            <div className="p-6 text-center text-gray-500">
              No unpaid tours found.
            </div>
          ) : (
            <div className="divide-y">
              {currentTours.map(tour => {
                const price = calculatePrice(tour.duration);
                return (
                  <div key={tour.id} className="p-4 hover:bg-gray-50">
                    <div className="flex flex-col md:flex-row md:justify-between md:items-center gap-2">
                      <h3 className="font-medium text-gray-900">{tour.title}</h3>
                      <div className="flex items-center gap-2">
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                          <span className="w-2 h-2 rounded-full bg-red-500 mr-1.5"></span>
                          Unpaid
                        </span>
                        <button 
                          onClick={() => handlePaidToggle(tour.id, tour.paid)}
                          className="text-xs px-2.5 py-1 rounded-md bg-green-100 text-green-800 hover:bg-green-200 transition-colors"
                        >
                          Mark as Paid
                        </button>
                      </div>
                    </div>
                    
                    <div className="mt-2 grid grid-cols-1 md:grid-cols-4 gap-3">
                      <div className="flex items-center text-sm text-gray-700">
                        <svg className="h-4 w-4 mr-1.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        {format(new Date(tour.date), "dd/MM/yyyy")}
                      </div>
                      
                      <div className="flex items-center text-sm text-gray-700">
                        <svg className="h-4 w-4 mr-1.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {tour.time}
                      </div>
                      
                      <div className="flex items-center text-sm text-gray-700">
                        <svg className="h-4 w-4 mr-1.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        {tour.guide_name}
                      </div>
                      
                      <div className="flex items-center text-sm text-gray-700">
                        <svg className="h-4 w-4 mr-1.5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="font-medium">{price} €</span>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>
        
        {/* Pagination for UnpaidToursModal */}
        {totalPages > 1 && (
          <div className="px-4 py-2 border-t border-gray-200 bg-gray-50">
            <div className="flex justify-center">
              <nav className="flex space-x-1" aria-label="Pagination">
                <button
                  onClick={() => handlePageChange(Math.max(1, currentPage - 1))}
                  disabled={currentPage === 1}
                  className={`p-1 rounded ${currentPage === 1 ? 'text-gray-400 cursor-not-allowed' : 'text-gray-500 hover:bg-gray-100'}`}
                >
                  <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clipRule="evenodd" />
                  </svg>
                </button>
                
                {/* Page numbers */}
                {paginationRange.map((page, idx) => {
                  // If it's ellipsis, render a static element
                  if (page === '...') {
                    return (
                      <span key={`ellipsis-${idx}`} className="px-3 py-1 text-gray-400">
                        ...
                      </span>
                    );
                  }
                  
                  // Otherwise render a page button
                  return (
                    <button
                      key={idx}
                      onClick={() => handlePageChange(page)}
                      className={`px-3 py-1 rounded text-sm ${
                        currentPage === page
                          ? 'bg-purple-100 text-purple-700 font-medium'
                          : 'text-gray-600 hover:bg-gray-100'
                      }`}
                    >
                      {page}
                    </button>
                  );
                })}
                
                <button
                  onClick={() => handlePageChange(Math.min(totalPages, currentPage + 1))}
                  disabled={currentPage === totalPages}
                  className={`p-1 rounded ${currentPage === totalPages ? 'text-gray-400 cursor-not-allowed' : 'text-gray-500 hover:bg-gray-100'}`}
                >
                  <svg className="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
                  </svg>
                </button>
              </nav>
            </div>
          </div>
        )}
        
        {unpaidTours.length > 0 && (
          <div className="p-4 bg-gray-100 border-t border-gray-200">
            <div className="flex justify-between items-center">
              <span className="font-medium text-gray-700">Total amount to pay:</span>
              <span className="font-bold text-lg text-purple-700">{totalAmount} €</span>
            </div>
          </div>
        )}
        
        <div className="p-4 border-t bg-gray-50 flex justify-end">
          <button
            onClick={onClose}
            className="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition-colors focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
};

export default UnpaidToursModal; 