import crypto from 'crypto';
import axios from 'axios';

class BokunAPIService {
  constructor(accessKey, secretKey, vendorId) {
    this.accessKey = accessKey;
    this.secretKey = secretKey;
    this.vendorId = vendorId;
    this.baseURL = 'https://api.bokun.is';
  }

  generateSignature(method, path, date, body = '') {
    const message = `${method}\n${path}\n${date}\n${body}`;
    return crypto
      .createHmac('sha256', this.secretKey)
      .update(message)
      .digest('base64');
  }

  async makeRequest(method, endpoint, data = null) {
    const date = new Date().toISOString().replace('T', ' ').substring(0, 19);
    const path = `/booking.json${endpoint}`;
    const body = data ? JSON.stringify(data) : '';
    
    const signature = this.generateSignature(method, path, date, body);
    
    const headers = {
      'X-Bokun-Date': date,
      'X-Bokun-AccessKey': this.accessKey,
      'X-Bokun-Signature': signature,
      'Content-Type': 'application/json'
    };

    try {
      const response = await axios({
        method,
        url: `${this.baseURL}${path}`,
        headers,
        data
      });
      return response.data;
    } catch (error) {
      console.error('Bokun API Error:', error.response?.data || error.message);
      throw error;
    }
  }

  // Get all bookings for a date range
  async getBookings(startDate, endDate) {
    const params = new URLSearchParams({
      start: startDate,
      end: endDate,
      vendorId: this.vendorId
    });
    
    return this.makeRequest('GET', `/search?${params}`);
  }

  // Get specific booking details
  async getBookingDetails(bookingId) {
    return this.makeRequest('GET', `/${bookingId}`);
  }

  // Get available activities
  async getActivities() {
    return this.makeRequest('POST', '/activity.json/search', {
      page: 1,
      pageSize: 100
    });
  }

  // Check availability for an activity
  async checkAvailability(activityId, startDate, endDate) {
    const params = new URLSearchParams({
      start: startDate,
      end: endDate
    });
    
    return this.makeRequest('GET', `/activity.json/${activityId}/availabilities?${params}`);
  }

  // Get upcoming bookings that need guide assignment
  async getUnassignedBookings() {
    const today = new Date().toISOString().split('T')[0];
    const nextWeek = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().split('T')[0];
    
    const bookings = await this.getBookings(today, nextWeek);
    
    // Filter bookings that need guide assignment
    return bookings.filter(booking => {
      return booking.status === 'CONFIRMED' && 
             (!booking.customFields?.assignedGuide || booking.customFields?.assignedGuide === '');
    });
  }

  // Parse webhook payload
  parseWebhookPayload(headers, body) {
    const topic = headers['x-bokun-topic'];
    const bookingId = headers['x-bokun-booking-id'];
    const experienceBookingId = headers['x-bokun-experiencebooking-id'];
    
    return {
      topic,
      bookingId,
      experienceBookingId,
      timestamp: body.timestamp,
      data: body
    };
  }

  // Transform Bokun booking to local tour format
  transformBookingToTour(bokunBooking) {
    return {
      external_id: bokunBooking.id,
      external_source: 'bokun',
      title: bokunBooking.title || bokunBooking.productTitle,
      date: bokunBooking.date,
      time: bokunBooking.startTime || '09:00',
      duration: bokunBooking.duration || '2 hours',
      description: bokunBooking.description || '',
      booking_channel: 'Bokun',
      participants: bokunBooking.participants || bokunBooking.totalParticipants,
      customer_name: `${bokunBooking.customer?.firstName} ${bokunBooking.customer?.lastName}`,
      customer_email: bokunBooking.customer?.email,
      customer_phone: bokunBooking.customer?.phone,
      status: bokunBooking.status,
      confirmation_code: bokunBooking.confirmationCode,
      needs_guide_assignment: true,
      bokun_data: JSON.stringify(bokunBooking) // Store full data for reference
    };
  }
}

export default BokunAPIService;