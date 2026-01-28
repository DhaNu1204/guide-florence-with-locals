import React, { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { getTours, getGuides, updateTour } from '../services/mysqlDB';
import DatePicker from "react-datepicker";
import { format } from "date-fns";
import { it } from "date-fns/locale";
import "react-datepicker/dist/react-datepicker.css";
import { usePageTitle } from '../contexts/PageTitleContext';
import { 
  FiEdit2, 
  FiCalendar, 
  FiClock, 
  FiUser, 
  FiMapPin, 
  FiAlertTriangle,
  FiArrowLeft 
} from 'react-icons/fi';
import Card from '../components/UI/Card';
import Button from '../components/UI/Button';
import Input from '../components/UI/Input';

// Predefined tour names and durations (same as in Tours.jsx)
const TOUR_NAMES = [
  'David and Accademia Gallery VIP Tour in Florence',
  'David & Accademia Gallery Florence Private Tour with Local Guide',
  'Private Tour-Pitti Palace & Palatina Gallery, Boboli Gardens Tkts',
  'Michelangelo Private Guided Tour',
  'Exclusive Evening Tour of Michelangelo\'s David',
  'Private Tour in Bargello Museum',
  'Medici Family Private Guided Walking Tour',
  'Discover Palazzo Vecchio a Private Museum Tour in Florence',
  'Renaissance Masterpiece David & Historic Florence Tour',
  'Renaissance Florence with Expert Guided Walking Tour',
  'Uffizi Gallery Small Group Guided Tour with Tickets',
  '3H Semi Private Guided Tour to Uffizi Gallery & Accademia Gallery',
  'Uffizi Gallery VIP Entrance and Private Guided Tour',
  'Accademia Gallery and Walking Semi-private Tour (6 people) - Evening Tour'
];

const DURATION_OPTIONS = [
  '1 hour',
  '1.5 hours',
  '2 hours',
  '2.5 hours',
  '3 hours',
  '3.5 hours',
  '4 hours'
];

// Booking Channel options
const BOOKING_CHANNEL_OPTIONS = [
  'Get your guide',
  'Viator',
  'Air BNB',
  'Website',
  'Other'
];

// Generate time options in 5-minute intervals
const generateTimeOptions = () => {
  const options = [];
  for (let hour = 0; hour < 24; hour++) {
    for (let minute = 0; minute < 60; minute += 5) {
      const formattedHour = hour.toString().padStart(2, '0');
      const formattedMinute = minute.toString().padStart(2, '0');
      options.push(`${formattedHour}:${formattedMinute}`);
    }
  }
  return options;
};

const TIME_OPTIONS = generateTimeOptions();

// Custom header for the DatePicker
const CustomHeader = ({
  date,
  decreaseMonth,
  increaseMonth,
  prevMonthButtonDisabled,
  nextMonthButtonDisabled,
}) => {
  const monthNames = [
    "GENNAIO", "FEBBRAIO", "MARZO", "APRILE", "MAGGIO", "GIUGNO",
    "LUGLIO", "AGOSTO", "SETTEMBRE", "OTTOBRE", "NOVEMBRE", "DICEMBRE"
  ];
  
  return (
    <div className="flex justify-between items-center bg-purple-600 text-white py-2 px-4">
      <button
        onClick={decreaseMonth}
        disabled={prevMonthButtonDisabled}
        type="button"
        className="text-white text-2xl font-bold"
      >
        &#8249;
      </button>
      <div className="text-center tracking-wide">
        <span>{monthNames[date.getMonth()]}</span>{" "}
        <span>{date.getFullYear()}</span>
      </div>
      <button
        onClick={increaseMonth}
        disabled={nextMonthButtonDisabled}
        type="button"
        className="text-white text-2xl font-bold"
      >
        &#8250;
      </button>
    </div>
  );
};

const EditTour = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const { setPageTitle } = usePageTitle();
  
  const [tour, setTour] = useState(null);
  const [guides, setGuides] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState(null);
  const [selectedDate, setSelectedDate] = useState(null);
  
  const [formData, setFormData] = useState({
    title: '',
    duration: '',
    description: '',
    date: '',
    time: '',
    guideId: '',
    bookingChannel: '',
    paid: false
  });

  useEffect(() => {
    setPageTitle('Edit Tour');
    fetchTourAndGuides();
    
    return () => setPageTitle('');
  }, [id, setPageTitle]);

  const fetchTourAndGuides = async () => {
    try {
      setIsLoading(true);
      
      // Fetch both tours and guides
      const [toursData, guidesData] = await Promise.all([
        getTours(),
        getGuides()
      ]);
      
      // Debug logging
      console.log('URL ID:', id, 'Type:', typeof id);
      console.log('Tours data:', toursData);
      console.log('Looking for tour with ID:', parseInt(id));
      
      // Find the specific tour
      const tourToEdit = toursData.find(t => {
        console.log('Comparing:', t.id, 'Type:', typeof t.id, 'with', parseInt(id));
        // Try both string and number comparison
        return t.id === parseInt(id) || t.id === id || t.id === String(id);
      });
      
      console.log('Found tour:', tourToEdit);
      
      if (!tourToEdit) {
        setError('Tour not found');
        return;
      }
      
      setTour(tourToEdit);
      // Handle paginated response - extract data array
      setGuides(Array.isArray(guidesData) ? guidesData : (guidesData?.data || []));
      
      // Set form data with tour values
      setFormData({
        title: tourToEdit.title,
        duration: tourToEdit.duration,
        description: tourToEdit.description || '',
        date: tourToEdit.date,
        time: tourToEdit.time,
        guideId: tourToEdit.guide_id ? tourToEdit.guide_id.toString() : '',
        bookingChannel: tourToEdit.booking_channel || '',
        paid: tourToEdit.paid || false
      });
      
      // Set the date picker value
      setSelectedDate(new Date(tourToEdit.date));
      
    } catch (err) {
      console.error('Error fetching data:', err);
      setError('Failed to load tour data. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData({
      ...formData,
      [name]: value
    });
  };

  const handleDateChange = (date) => {
    setSelectedDate(date);
    const formattedDate = format(date, "yyyy-MM-dd");
    setFormData({
      ...formData,
      date: formattedDate
    });
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    try {
      setIsSaving(true);
      setError(null);
      
      // Update the tour
      await updateTour(parseInt(id), {
        ...formData,
        guide_id: parseInt(formData.guideId),
        booking_channel: formData.bookingChannel
      });
      
      // Navigate back to tours page
      navigate('/');
      
    } catch (err) {
      console.error('Error updating tour:', err);
      setError('Failed to update tour. Please try again.');
    } finally {
      setIsSaving(false);
    }
  };

  const handleCancel = () => {
    navigate('/');
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (error && !tour) {
    return (
      <div className="space-y-6">
        <Card className="border-red-200 bg-red-50">
          <div className="flex items-center">
            <FiAlertTriangle className="h-5 w-5 mr-3 text-red-500" />
            <p className="text-red-700">{error}</p>
          </div>
        </Card>
        <Button 
          variant="outline" 
          icon={FiArrowLeft}
          onClick={handleCancel}
        >
          Back to Tours
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        <Button 
          variant="ghost" 
          icon={FiArrowLeft}
          onClick={handleCancel}
        >
          Back
        </Button>
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Edit Tour</h1>
          <p className="text-gray-600 mt-1">Update tour details and assignment</p>
        </div>
      </div>

      {error && (
        <Card className="border-red-200 bg-red-50">
          <div className="flex items-center">
            <FiAlertTriangle className="h-5 w-5 mr-3 text-red-500" />
            <p className="text-red-700">{error}</p>
          </div>
        </Card>
      )}

      <Card>

        <form onSubmit={handleSubmit} className="space-y-6">
          {/* First row: Tour Name and Guide */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Tour Name
              </label>
              <div className="relative">
                <FiMapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                <select
                  name="title"
                  value={formData.title}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Select a tour</option>
                  {TOUR_NAMES.map((tourName, index) => (
                    <option key={index} value={tourName}>
                      {tourName}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Guide
              </label>
              <div className="relative">
                <FiUser className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                <select
                  name="guideId"
                  value={formData.guideId}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Select a guide</option>
                  {guides.map((guide) => (
                    <option key={guide.id} value={guide.id}>
                      {guide.name}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Second row: Date, Time, Duration */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Date
              </label>
              <div className="relative">
                <div className="flex items-center border border-gray-300 rounded-md shadow-sm">
                  <span className="pl-3 text-gray-400">
                    <FiCalendar className="h-4 w-4" />
                  </span>
                  <DatePicker
                    selected={selectedDate}
                    onChange={handleDateChange}
                    dateFormat="dd/MM/yyyy"
                    locale={it}
                    renderCustomHeader={CustomHeader}
                    className="w-full border-0 p-2 pl-0 focus:ring-0 focus:outline-none"
                    required
                  />
                </div>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Time
              </label>
              <div className="relative">
                <FiClock className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                <select
                  name="time"
                  value={formData.time}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Select time</option>
                  {TIME_OPTIONS.map((time, index) => (
                    <option key={index} value={time}>
                      {time}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-2">
                Duration
              </label>
              <div className="relative">
                <FiClock className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                <select
                  name="duration"
                  value={formData.duration}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Select duration</option>
                  {DURATION_OPTIONS.map((duration, index) => (
                    <option key={index} value={duration}>
                      {duration}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          {/* Third row: Booking Channel */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-2">
              Booking Channel
            </label>
            <div className="relative">
              <FiMapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
              <select
                name="bookingChannel"
                value={formData.bookingChannel}
                onChange={handleChange}
                className="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                required
              >
                <option value="">Select booking channel</option>
                {BOOKING_CHANNEL_OPTIONS.map((channel, index) => (
                  <option key={index} value={channel}>
                    {channel}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {/* Fourth row: Description */}
          <div>
            <Input
              label="Description (optional)"
              name="description"
              type="textarea"
              value={formData.description}
              onChange={handleChange}
              rows={3}
              placeholder="Enter tour description"
            />
          </div>

          {/* Action buttons */}
          <div className="flex flex-col sm:flex-row justify-end gap-3 mt-8">
            <Button
              type="button"
              variant="outline"
              onClick={handleCancel}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={isSaving}
              loading={isSaving}
            >
              Save Changes
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
};

export default EditTour; 