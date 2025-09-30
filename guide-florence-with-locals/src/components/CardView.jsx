import React, { useEffect } from 'react';
import { format } from 'date-fns';
import TourCards from './TourCards';

const CardView = ({ 
  tours,
  filteredTours,
  currentTours,
  calculatePrice,
  isPastTour,
  isToday,
  isTomorrow,
  isDayAfterTomorrow,
  isFutureDate,
  onPaidToggle,
  onDelete,
  onCancelTour,
  indexOfFirstTour,
  indexOfLastTour,
  totalPages,
  currentPage,
  handlePageChange,
  paginationRange,
  toursPerPage,
  handleItemsPerPageChange,
  ITEMS_PER_PAGE_OPTIONS,
  filterDate,
  selectedGuideId
}) => {
  useEffect(() => {
    // Force any parent tables to be hidden
    const style = document.createElement('style');
    style.type = 'text/css';
    style.innerHTML = `
      #tours-list table, 
      #tours-list thead, 
      #tours-list tbody, 
      #tours-list tr, 
      #tours-list th, 
      #tours-list td {
        display: none !important;
      }
      
      .card-view-container {
        display: block !important;
      }
    `;
    document.head.appendChild(style);
    
    return () => {
      // Clean up when component unmounts
      if (document.head.contains(style)) {
        document.head.removeChild(style);
      }
    };
  }, []);

  if (filteredTours.length === 0) {
    return (
      <div className="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 rounded">
        <div className="flex items-center">
          <svg className="h-5 w-5 mr-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p>
            {filterDate && selectedGuideId !== 'all'
              ? `No tours found for the selected guide and date.`
              : selectedGuideId === 'all'
                ? 'No tours found. Please adjust your filter settings.'
                : `No tours found for the selected guide.`}
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="mb-8">
      {/* Debugging info */}
      <div className="text-sm text-gray-500 mb-4">
        Showing {indexOfFirstTour + 1}-{Math.min(indexOfLastTour, filteredTours.length)} of {filteredTours.length} tours
      </div>
      
      {/* ALWAYS use TourCards component here */}
      <TourCards 
        tours={currentTours}
        onPaidToggle={onPaidToggle}
        onDelete={onDelete}
        onCancelTour={onCancelTour}
        isPastTour={isPastTour}
        isToday={isToday}
        isTomorrow={isTomorrow}
        isDayAfterTomorrow={isDayAfterTomorrow}
        isFutureDate={isFutureDate}
        calculatePrice={calculatePrice}
      />
      
      {/* Pagination UI */}
      {totalPages > 1 && (
        <div className="mt-8 flex flex-col sm:flex-row justify-between items-center gap-4">
          {/* Items per page selector */}
          <div className="flex items-center space-x-2">
            <label htmlFor="itemsPerPage" className="text-sm text-gray-600">
              Tours per page:
            </label>
            <select 
              id="itemsPerPage" 
              value={toursPerPage} 
              onChange={handleItemsPerPageChange}
              className="rounded-md border border-gray-300 text-sm py-1 px-2 bg-white shadow-sm focus:outline-none focus:ring-1 focus:ring-purple-500"
            >
              {ITEMS_PER_PAGE_OPTIONS.map(option => (
                <option key={option} value={option}>{option}</option>
              ))}
            </select>
          </div>

          <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
            {/* First Page */}
            <button
              onClick={() => handlePageChange(1)}
              disabled={currentPage === 1}
              className={`relative inline-flex items-center px-2 py-2 rounded-l-md border ${
                currentPage === 1 
                  ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                  : 'bg-white text-gray-500 hover:bg-gray-50'
              } text-sm font-medium`}
            >
              <span className="sr-only">First</span>
              <svg className="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M15.707 15.707a1 1 0 01-1.414 0l-5-5a1 1 0 010-1.414l5-5a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 010 1.414zm-6 0a1 1 0 01-1.414 0l-5-5a1 1 0 010-1.414l5-5a1 1 0 011.414 1.414L5.414 10l4.293 4.293a1 1 0 010 1.414z" clipRule="evenodd" />
              </svg>
            </button>
            
            {/* Previous Page */}
            <button
              onClick={() => handlePageChange(currentPage - 1)}
              disabled={currentPage === 1}
              className={`relative inline-flex items-center px-2 py-2 border ${
                currentPage === 1 
                  ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                  : 'bg-white text-gray-500 hover:bg-gray-50'
              } text-sm font-medium`}
            >
              <span className="sr-only">Previous</span>
              <svg className="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fillRule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clipRule="evenodd" />
              </svg>
            </button>
            
            {/* Page Numbers with Ellipsis */}
            {paginationRange.map((pageNumber, index) => {
              // If it's ellipsis, render a static element
              if (pageNumber === '...') {
                return (
                  <span key={`ellipsis-${index}`} className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-gray-700">
                    ...
                  </span>
                );
              }
              
              // Otherwise render a page button
              return (
                <button
                  key={`page-${pageNumber}`}
                  onClick={() => handlePageChange(pageNumber)}
                  className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${
                    currentPage === pageNumber
                      ? 'z-10 bg-purple-50 border-purple-500 text-purple-600' 
                      : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                  }`}
                >
                  {pageNumber}
                </button>
              );
            })}
            
            {/* Next Page */}
            <button
              onClick={() => handlePageChange(currentPage + 1)}
              disabled={currentPage === totalPages}
              className={`relative inline-flex items-center px-2 py-2 border ${
                currentPage === totalPages 
                  ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                  : 'bg-white text-gray-500 hover:bg-gray-50'
              } text-sm font-medium`}
            >
              <span className="sr-only">Next</span>
              <svg className="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fillRule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clipRule="evenodd" />
              </svg>
            </button>
            
            {/* Last Page */}
            <button
              onClick={() => handlePageChange(totalPages)}
              disabled={currentPage === totalPages}
              className={`relative inline-flex items-center px-2 py-2 rounded-r-md border ${
                currentPage === totalPages 
                  ? 'bg-gray-100 text-gray-400 cursor-not-allowed' 
                  : 'bg-white text-gray-500 hover:bg-gray-50'
              } text-sm font-medium`}
            >
              <span className="sr-only">Last</span>
              <svg className="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                <path fillRule="evenodd" d="M4.293 15.707a1 1 0 001.414 0l5-5a1 1 0 000-1.414l-5-5a1 1 0 00-1.414 1.414L8.586 10 4.293 14.293a1 1 0 000 1.414zm6 0a1 1 0 001.414 0l5-5a1 1 0 000-1.414l-5-5a1 1 0 00-1.414 1.414L14.586 10l-4.293 4.293a1 1 0 000 1.414z" clipRule="evenodd" />
              </svg>
            </button>
          </nav>
        </div>
      )}
    </div>
  );
};

export default CardView; 