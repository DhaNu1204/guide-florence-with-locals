import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { FiPlus, FiUsers, FiPhone, FiX, FiCalendar, FiEdit2, FiTrash2, FiMail, FiGlobe } from 'react-icons/fi';
import { getGuides, addGuide, deleteGuide } from '../services/mysqlDB';
import { usePageTitle } from '../contexts/PageTitleContext';
import { useAuth } from '../contexts/AuthContext';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import Input from '../components/UI/Input';
import { Select } from '../components/UI/Input';

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
    phone: '',
    email: '',
    languages: []
  });
  
  const { isAdmin } = useAuth();
  
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

  const handleLanguageChange = (language) => {
    setFormData(prev => {
      const currentLanguages = prev.languages || [];
      const isSelected = currentLanguages.includes(language);
      
      if (isSelected) {
        // Remove language
        return {
          ...prev,
          languages: currentLanguages.filter(lang => lang !== language)
        };
      } else {
        // Add language (max 3)
        if (currentLanguages.length < 3) {
          return {
            ...prev,
            languages: [...currentLanguages, language]
          };
        }
        return prev; // Don't add if already at max
      }
    });
  };
  
  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!formData.name || !formData.phone || !formData.email || formData.languages.length === 0) return;
    
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
      setFormData({ id: null, name: '', phone: '', email: '', languages: [] });
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
      phone: guide.phone,
      email: guide.email || '',
      languages: guide.languages || []
    });
    setIsEditing(true);
    setShowAddForm(true);
  };
  
  const cancelForm = () => {
    setFormData({ id: null, name: '', phone: '', email: '', languages: [] });
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
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Guides Management</h1>
          <p className="text-gray-600 mt-1">Manage your Florence tour guides</p>
        </div>
        <div className="flex gap-3">
          {isAdmin() && (
            <Button
              variant={showAddForm ? "outline" : "primary"}
              icon={showAddForm ? FiX : FiPlus}
              onClick={() => {
                if (showAddForm) {
                  cancelForm();
                } else {
                  setShowAddForm(true);
                }
              }}
            >
              {showAddForm ? 'Cancel' : 'Add Guide'}
            </Button>
          )}
        </div>
      </div>
      
      {/* Error Alert */}
      {error && (
        <Card borderColor="border-l-red-500" className="bg-red-50">
          <div className="flex items-center">
            <div className="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
              <svg className="h-5 w-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <div>
              <h3 className="font-medium text-red-800">Error</h3>
              <p className="text-sm text-red-700">{error}</p>
            </div>
          </div>
        </Card>
      )}
      
      {/* Add/Edit Guide Form */}
      {isAdmin() && showAddForm && (
        <Card>
          <div className="flex items-center mb-6">
            <div className="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
              <FiUsers className="text-blue-600" />
            </div>
            <div>
              <h2 className="text-xl font-semibold text-gray-900">
                {isEditing ? 'Edit Guide' : 'Add New Guide'}
              </h2>
              <p className="text-sm text-gray-600">
                {isEditing ? 'Update guide information' : 'Add a new tour guide to your team'}
              </p>
            </div>
          </div>
          
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <Input
                label="Guide Name"
                name="name"
                type="text"
                value={formData.name}
                onChange={handleChange}
                icon={FiUsers}
                placeholder="Enter guide's full name"
                required
              />
              
              <Input
                label="Email Address"
                name="email"
                type="email"
                value={formData.email}
                onChange={handleChange}
                icon={FiMail}
                placeholder="guide@example.com"
                required
              />
              
              <Input
                label="Phone Number"
                name="phone"
                type="tel"
                value={formData.phone}
                onChange={handleChange}
                icon={FiPhone}
                placeholder="+39 055 123 4567"
                required
              />
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-2">
                  Languages <span className="text-red-500">*</span>
                  <span className="text-xs text-gray-500 ml-2">(Select up to 3)</span>
                </label>
                <div className="grid grid-cols-2 gap-3">
                  {[
                    { value: 'english', label: 'English' },
                    { value: 'italian', label: 'Italian' },
                    { value: 'spanish', label: 'Spanish' },
                    { value: 'german', label: 'German' },
                    { value: 'french', label: 'French' },
                    { value: 'portuguese', label: 'Portuguese' }
                  ].map(({ value, label }) => (
                    <label key={value} className="flex items-center">
                      <input
                        type="checkbox"
                        checked={formData.languages.includes(value)}
                        onChange={() => handleLanguageChange(value)}
                        disabled={!formData.languages.includes(value) && formData.languages.length >= 3}
                        className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                      />
                      <span className="ml-2 text-sm text-gray-700">{label}</span>
                    </label>
                  ))}
                </div>
                {formData.languages.length === 0 && (
                  <p className="text-sm text-red-600 mt-1">Please select at least one language</p>
                )}
                {formData.languages.length >= 3 && (
                  <p className="text-sm text-amber-600 mt-1">Maximum 3 languages selected</p>
                )}
              </div>
            </div>
            
            <div className="flex flex-col sm:flex-row sm:justify-end gap-3 pt-4">
              <Button
                type="button"
                variant="outline"
                onClick={cancelForm}
              >
                Cancel
              </Button>
              
              <Button
                type="submit"
                variant="primary"
                loading={loading}
              >
                {isEditing ? 'Update Guide' : 'Save Guide'}
              </Button>
            </div>
          </form>
        </Card>
      )}
      
      {/* Delete Confirmation Modal */}
      {deleteConfirmation.show && (
        <div className="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 p-4">
          <Card className="max-w-md w-full">
            <div className="flex items-center mb-4">
              <div className="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                <FiTrash2 className="h-5 w-5 text-red-600" />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-gray-900">Delete Guide</h3>
                <p className="text-sm text-gray-600">This action cannot be undone</p>
              </div>
            </div>
            <p className="text-gray-700 mb-6">
              Are you sure you want to delete <span className="font-medium">{deleteConfirmation.guideName}</span>?
            </p>
            <div className="flex gap-3">
              <Button
                variant="outline"
                onClick={cancelDelete}
                fullWidth
              >
                Cancel
              </Button>
              <Button
                variant="danger"
                onClick={handleDelete}
                loading={loading}
                fullWidth
              >
                Delete
              </Button>
            </div>
          </Card>
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
          <Card className="text-center py-12">
            <div className="w-16 h-16 mx-auto mb-4 text-gray-400">
              <FiUsers className="w-full h-full" />
            </div>
            <h3 className="text-xl font-semibold text-gray-900 mb-2">No guides available</h3>
            <p className="text-gray-600 mb-6">Get started by adding your first tour guide</p>
            {!showAddForm && (
              <Button 
                variant="primary"
                icon={FiPlus}
                onClick={() => setShowAddForm(true)}
              >
                Add Guide
              </Button>
            )}
          </Card>
        ) : (
          <>
            {/* Desktop view - Table */}
            <Card className="hidden md:block">
              <div className="overflow-x-auto">
                <table className="min-w-full">
                  <thead>
                    <tr className="border-b border-gray-200">
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Guide</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                      <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Language</th>
                      <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200">
                    {guides.map(guide => (
                      <tr key={guide.id} className="hover:bg-gray-50 transition-colors">
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center">
                            <div className="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                              <span className="text-blue-600 font-semibold text-lg">{guide.name.charAt(0)}</span>
                            </div>
                            <div className="font-medium text-gray-900">{guide.name}</div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="text-sm">
                            <div className="flex items-center text-gray-900 mb-1">
                              <FiMail className="h-4 w-4 mr-2 text-gray-400" />
                              {guide.email || 'No email'}
                            </div>
                            <div className="flex items-center text-gray-600">
                              <FiPhone className="h-4 w-4 mr-2 text-gray-400" />
                              {guide.phone}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap">
                          <div className="flex items-center text-gray-900">
                            <FiGlobe className="h-4 w-4 mr-2 text-gray-400" />
                            <div className="flex flex-wrap gap-1">
                              {Array.isArray(guide.languages) && guide.languages.length > 0 ? (
                                guide.languages.map((lang, index) => (
                                  <span key={lang} className="capitalize">
                                    {lang}
                                    {index < guide.languages.length - 1 && <span className="text-gray-400">, </span>}
                                  </span>
                                ))
                              ) : guide.languages && typeof guide.languages === 'string' ? (
                                <span className="capitalize">{guide.languages}</span>
                              ) : (
                                <span className="text-gray-500">Not specified</span>
                              )}
                            </div>
                          </div>
                        </td>
                        <td className="px-6 py-4 whitespace-nowrap text-right">
                          <div className="flex justify-end gap-2">
                            {isAdmin() && (
                              <>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  icon={FiEdit2}
                                  onClick={() => startEdit(guide)}
                                >
                                  Edit
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  icon={FiTrash2}
                                  onClick={() => confirmDelete(guide)}
                                  className="text-red-600 hover:text-red-700 hover:bg-red-50"
                                >
                                  Delete
                                </Button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Card>

            {/* Mobile view - Cards */}
            <div className="md:hidden space-y-4">
              {guides.map(guide => (
                <Card key={guide.id} hover>
                  <div className="flex items-start justify-between">
                    <div className="flex items-start">
                      <div className="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mr-4 flex-shrink-0">
                        <span className="text-blue-600 font-semibold text-lg">{guide.name.charAt(0)}</span>
                      </div>
                      <div className="flex-1">
                        <h3 className="font-medium text-gray-900 mb-2">{guide.name}</h3>
                        <div className="space-y-1">
                          <div className="flex items-center text-sm text-gray-600">
                            <FiMail className="h-4 w-4 mr-2 text-gray-400" />
                            {guide.email || 'No email'}
                          </div>
                          <div className="flex items-center text-sm text-gray-600">
                            <FiPhone className="h-4 w-4 mr-2 text-gray-400" />
                            {guide.phone}
                          </div>
                          <div className="flex items-center text-sm text-gray-600">
                            <FiGlobe className="h-4 w-4 mr-2 text-gray-400 flex-shrink-0" />
                            <div className="flex flex-wrap gap-1">
                              {Array.isArray(guide.languages) && guide.languages.length > 0 ? (
                                guide.languages.map((lang, index) => (
                                  <span key={lang} className="capitalize">
                                    {lang}
                                    {index < guide.languages.length - 1 && <span className="text-gray-400">, </span>}
                                  </span>
                                ))
                              ) : guide.languages && typeof guide.languages === 'string' ? (
                                <span className="capitalize">{guide.languages}</span>
                              ) : (
                                <span className="text-gray-500">Not specified</span>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                    
                    {isAdmin() && (
                      <div className="flex gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          icon={FiEdit2}
                          onClick={() => startEdit(guide)}
                        />
                        <Button
                          variant="ghost"
                          size="sm"
                          icon={FiTrash2}
                          onClick={() => confirmDelete(guide)}
                          className="text-red-600 hover:text-red-700 hover:bg-red-50"
                        />
                      </div>
                    )}
                  </div>
                </Card>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
};

export default Guides; 