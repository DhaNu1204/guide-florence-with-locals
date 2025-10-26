# Bokun Integration

## ✅ PRODUCTION READY & OPERATIONAL

**Last Updated**: September 29, 2025

## Production Configuration ✅

- **Live URL**: https://withlocals.deetech.cc/bokun-integration
- **Vendor ID**: 96929
- **API Access Key**: 2c413c402bd9402092b4a3f5157c899e
- **API Base URL**: https://api.bokun.is
- **Booking Channel**: www.florencewithlocals.com
- **Authentication**: HMAC-SHA1 signatures working ✅
- **Connection Status**: **LIVE AND SYNCING** ✅
- **Last Sync**: 47 bookings successfully synchronized to production database
- **Integration Status**: **FULLY OPERATIONAL ON PRODUCTION** ✅

## ✅ BREAKTHROUGH: New Working Endpoint Confirmed

**Date**: September 25, 2025
**Bokun Support Confirmation**: Sanjeet Sisodia (Connectivity Solutions Specialist)

- **NEW ENDPOINT**: `POST /booking.json/booking-search` ✅
- **API Permissions**: CONFIRMED enabled (Admin role verified)
- **Booking Channels**: CONFIRMED associated with API key
- **Test Results**: **76 total bookings** returned successfully
- **Integration Status**: **FULLY FUNCTIONAL** ✅

## Working API Details

### Endpoint

```
POST https://api.bokun.is/booking.json/booking-search
```

### Payload Example

```json
{
  "bookingRole": "SELLER",
  "bookingStatuses": ["CONFIRMED"],
  "pageSize": 50,
  "startDateRange": {
    "from": "2025-08-25T10:00:14.359Z",
    "includeLower": true,
    "includeUpper": true,
    "to": "2025-09-25T19:00:14.359Z"
  }
}
```

### Response

Returns full booking data with 1600+ total bookings accessible

## Current Production Status ✅

- **Real Bookings Synchronized**: ✅ 47 tours with live Bokun data on production
- **Data Sources**: Viator.com, GetYourGuide confirmed working
- **API Monitor**: Updated to show **"Integration Ready"** status on https://withlocals.deetech.cc
- **Automatic Sync**: Fully operational with working endpoint on production server
- **Dashboard Integration**: Tours appearing correctly in dashboard and tour list
- **Production Database**: All Bokun tours properly stored and displayed

## Features

### Automatic Language Detection

The system automatically detects tour language from Bokun booking data using multiple methods:

1. **Method 1 (Viator)**: Extract from booking notes using regex pattern `GUIDE : English`
2. **Method 2 (GetYourGuide)**: Match rateId to product rate titles (Italian Tour, Spanish Tour, etc.)
3. **Method 3**: Check field locations for language indicators
4. **Method 4**: Parse product title for language keywords

### Booking Status Tracking

- **CONFIRMED**: Active confirmed bookings
- **PENDING**: Pending bookings awaiting confirmation
- **CANCELLED**: Cancelled bookings with red visual indicators
- **RESCHEDULED**: Date/time changes with orange badges and original scheduling preservation

### Data Synchronization

- Full booking details including customer contact information
- Participant breakdown (adults/children)
- Special requests and notes
- Pricing information
- Confirmation codes
- Museum/tour details
