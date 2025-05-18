import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { getGuides, addGuide, deleteGuide } from '../services/mysqlDB';
import { usePageTitle } from '../contexts/PageTitleContext';

// Fallback to local storage instead of remote API
const Guides = () => {
  const { setPageTitle } = usePageTitle();
  const [guides, setGuides] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [showAddForm, setShowAddForm] = useState(false);
  const [isEditing, setIsEditing] = useState(false);
  const [deleteConfirmation, setDeleteConfirmation] = useState({ show: false, guideId: null, guideName: '' });
  
  const [formData, setFormData] = useState({
    id: null,
    name: '',
    phone: ''
  });
  
  useEffect(() => {
    // Set the page title when component mounts
    setPageTitle('Guides Dashboard');
    
    fetchGuides();
    
    // Clean up function to reset page title when component unmounts
    return () => setPageTitle('');
  }, [setPageTitle]);
  
  const fetchGuides = async () => {
    try {
      setLoading(true);
      console.log('Fetching guides from MySQL database');
      
      const data = await getGuides();
      setGuides(data);
      setError(null);
    } catch (err) {
      console.error('Error fetching guides:', err);
      setError('Failed to load guides. Please try again later.');
    } finally {
      setLoading(false);
    }
  };
  
  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData({
      ...formData,
      [name]: value
    });
  };
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.name || !formData.phone) return;
    
    try {
      setLoading(true);
      console.log(isEditing ? 'Updating guide:' : 'Adding guide:', formData);
      
      const updatedGuide = await addGuide(formData);
      
      if (isEditing) {
        // Update the guide in the local state
        setGuides(guides.map(guide => guide.id === updatedGuide.id ? updatedGuide : guide));
      } else {
        // Add the new guide to the local state
        setGuides([...guides, updatedGuide]);
      }
      
      // Reset form and state
      setFormData({ id: null, name: '', phone: '' });
      setShowAddForm(false);
      setIsEditing(false);
      setError(null);
    } catch (err) {
      console.error('Error saving guide:', err);
      setError('Failed to save guide. Please try again.');
    } finally {
      setLoading(false);
    }
  };
  
  const startEdit = (guide) => {
    setFormData({
      id: guide.id,
      name: guide.name,
      phone: guide.phone
    });
    setIsEditing(true);
    setShowAddForm(true);
  };
  
  const cancelForm = () => {
    setFormData({ id: null, name: '', phone: '' });
    setShowAddForm(false);
    setIsEditing(false);
  };
  
  const handleDelete = async () => {
    if (!deleteConfirmation.guideId) return;
    
    try {
      setLoading(true);
      await deleteGuide(deleteConfirmation.guideId);
      
      // Update local state
      setGuides(guides.filter(guide => guide.id !== deleteConfirmation.guideId));
      setDeleteConfirmation({ show: false, guideId: null, guideName: '' });
      setError(null);
    } catch (err) {
      console.error('Error deleting guide:', err);
      if (err.message === 'Cannot delete guide with associated tours') {
        setError('Cannot delete this guide because they have associated tours.');
      } else {
        setError('Failed to delete guide. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };
  
  const confirmDelete = (guide) => {
    setDeleteConfirmation({
      show: true,
      guideId: guide.id,
      guideName: guide.name
    });
  };
  
  const cancelDelete = () => {
    setDeleteConfirmation({ show: false, guideId: null, guideName: '' });
  };
  
  return (
    <div className="max-w-6xl mx-auto p-4">
      <div className="flex justify-end items-center space-x-3 mb-6">
        <Link
          to="/tours"
          className="relative px-6 py-3 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
        >
          <span className="relative z-10 flex items-center">
            <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
            </svg>
            Add Tour
          </span>
          <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
        </Link>
        <button 
          onClick={() => {
            if (showAddForm) {
              cancelForm();
            } else {
              setShowAddForm(true);
            }
          }}
          className="relative px-6 py-3 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
        >
          <span className="relative z-10 flex items-center">
            <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              {showAddForm ? (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
              ) : (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              )}
            </svg>
            {showAddForm ? 'Cancel' : 'Add Guide'}
          </span>
          <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
        </button>
      </div>
      
      {error && (
        <div className="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded mb-6">
          <div className="flex items-center">
            <svg className="h-5 w-5 mr-2 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <p>{error}</p>
          </div>
        </div>
      )}
      
      {/* Add/Edit Guide Form */}
      {showAddForm && (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6 mb-6">
          <h2 className="text-xl font-semibold text-gray-800 mb-6 flex items-center">
            <svg className="w-6 h-6 mr-2 text-[#8207c5]" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            {isEditing ? 'Edit Guide' : 'Add New Guide'}
          </h2>
          
          <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-1">
                Guide Name
              </label>
              <div className="relative rounded-md">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </div>
                <input
                  type="text"
                  id="name"
                  name="name"
                  value={formData.name}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  placeholder="Enter guide's full name"
                  required
                />
              </div>
            </div>
            
            <div>
              <label htmlFor="phone" className="block text-sm font-medium text-gray-700 mb-1">
                Phone Number
              </label>
              <div className="relative rounded-md">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <svg className="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                  </svg>
                </div>
                <input
                  type="tel"
                  id="phone"
                  name="phone"
                  value={formData.phone}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                  placeholder="+39 055 123 4567"
                  required
                />
              </div>
            </div>
            
            <div className="md:col-span-2 flex justify-end">
              <button
                type="button"
                className="mr-3 px-5 py-2.5 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-400 transition-colors duration-200 shadow-sm"
                onClick={cancelForm}
              >
                Cancel
              </button>
              
              <button
                type="submit"
                className="relative px-6 py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 disabled:opacity-70 disabled:cursor-not-allowed"
                disabled={loading}
              >
                <span className="relative z-10 flex items-center">
                  {loading ? (
                    <>
                      <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      Saving...
                    </>
                  ) : (
                    <>{isEditing ? 'Update Guide' : 'Save Guide'}</>
                  )}
                </span>
                <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
              </button>
            </div>
          </form>
        </div>
      )}
      
      {/* Delete Confirmation Modal */}
      {deleteConfirmation.show && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-3">Delete Guide</h3>
            <p className="text-gray-700 mb-4">
              Are you sure you want to delete <span className="font-medium">{deleteConfirmation.guideName}</span>? This action cannot be undone.
            </p>
            <div className="flex justify-end space-x-3">
              <button
                onClick={cancelDelete}
                className="px-4 py-2 border border-gray-300 rounded-md text-gray-700 bg-white hover:bg-gray-50 transition-colors"
              >
                Cancel
              </button>
              <button
                onClick={handleDelete}
                className="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 transition-colors focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
              >
                {loading ? 'Deleting...' : 'Delete'}
              </button>
            </div>
          </div>
        </div>
      )}
      
      {/* Guides List */}
      <div>
        {loading && !showAddForm ? (
          <div className="flex justify-center p-6">
            <svg className="animate-spin h-8 w-8 text-purple-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
        ) : guides.length === 0 ? (
          <div className="text-center py-8 bg-white rounded-lg border border-gray-200">
            <div className="flex justify-center items-center">
              <div className="w-12 h-12 text-purple-600">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
              </div>
            </div>
            <h3 className="text-lg font-semibold text-gray-800 mt-2 mb-2">No guides available</h3>
            <p className="text-gray-500 mb-4">Get started by adding your first guide</p>
            {!showAddForm && (
              <button 
                onClick={() => setShowAddForm(true)}
                className="relative px-5 py-2.5 rounded-md font-medium bg-purple-600 text-white overflow-hidden shadow-sm group hover:shadow-md transition-all duration-300 ease-in-out focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2"
              >
                <span className="relative z-10 flex items-center">
                  <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                  </svg>
                  Add Guide
                </span>
                <span className="absolute inset-0 bg-indigo-700 opacity-0 group-hover:opacity-100 transition-opacity duration-300 ease-in-out"></span>
              </button>
            )}
          </div>
        ) : (
          <>
            {/* Desktop view - Table */}
            <div className="hidden md:block bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                  <thead className="bg-gray-50">
                    <tr>
                      <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guide</th>
                      <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                      <th scope="col" className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="bg-white divide-y divide-gray-200">
                    {guides.map(guide => (
                      <tr key={guide.id} className="hover:bg-gray-50">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                              <span className="text-purple-600 font-semibold">{guide.name.charAt(0)}</span>
                            </div>
                            <div className="font-medium text-gray-900">{guide.name}</div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm text-gray-900">{guide.phone}</div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                          <div className="flex justify-end space-x-2">
                            <button 
                              className="text-purple-600 hover:text-indigo-700 transition-colors font-medium px-3 py-1 rounded hover:bg-purple-50"
                              onClick={() => startEdit(guide)}
                            >
                              Edit
                            </button>
                            <button 
                              className="text-red-600 hover:text-red-800 transition-colors font-medium px-3 py-1 rounded hover:bg-red-50"
                              onClick={() => confirmDelete(guide)}
                            >
                              Delete
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {/* Mobile view - Cards */}
            <div className="md:hidden space-y-4">
              {guides.map(guide => (
                <div key={guide.id} className="bg-white p-4 rounded-lg border border-gray-200 shadow-sm">
                  <div className="flex items-center mb-3">
                    <div className="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                      <span className="text-purple-600 font-semibold text-lg">{guide.name.charAt(0)}</span>
                    </div>
                    <div className="font-medium text-lg text-gray-900">{guide.name}</div>
                  </div>
                  
                  <div className="mb-3">
                    <div className="text-xs text-gray-500 uppercase">Phone</div>
                    <div className="text-sm text-gray-900">{guide.phone}</div>
                  </div>
                  
                  <div className="flex justify-end space-x-2">
                    <button 
                      className="text-purple-600 hover:text-indigo-700 transition-colors font-medium px-4 py-1.5 rounded hover:bg-purple-50 border border-purple-200"
                      onClick={() => startEdit(guide)}
                    >
                      Edit
                    </button>
                    <button 
                      className="text-red-600 hover:text-red-800 transition-colors font-medium px-4 py-1.5 rounded hover:bg-red-50 border border-red-200"
                      onClick={() => confirmDelete(guide)}
                    >
                      Delete
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
};

export default Guides; 