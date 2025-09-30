# Message to Bokun Support Team

---

**Subject: Urgent - Enable Booking Channel API Access - Vendor ID 96929**

Hello Bokun Support Team,

I need assistance enabling Booking Channel API access for my account. I've successfully integrated the Bokun API into our tour management system, but I'm unable to retrieve booking data.

## Account Details:
- **Vendor ID:** 96929
- **Company:** Florence with Locals
- **API Integration:** Tour Guide Management System

## Current Issue:
1. **Authentication:** ✅ Working correctly (HMAC-SHA1 signatures validated)
2. **API Connection:** ✅ Successfully connecting to https://api.bokun.is
3. **Problem:** All booking endpoints return HTTP 303 (See Other) redirects with empty responses
4. **Impact:** Cannot retrieve any booking data via API despite successful authentication

## Technical Details:
```
Endpoint Tested: https://api.bokun.is/booking.json/search
Methods Tried: GET and POST
Authentication: Valid HMAC-SHA1 signatures
Response: HTTP 303 redirect with empty body
Date Ranges Tested: Multiple (2024-2025)
Expected Result: JSON array of bookings
Actual Result: Empty response with 303 status code
```

## API Calls Attempted:
- `/booking.json/search`
- `/booking.json`
- `/bookings.json/search`
- All return 303 redirects instead of booking data

## What I Need:
Please enable the following permissions for Vendor ID 96929:
1. **Booking Channel API Access**
2. **External Sales Channel permissions**
3. **Read access to booking data**
4. **Booking synchronization capabilities**

## Use Case:
We're integrating Bokun bookings with our internal tour guide assignment system to:
- Automatically sync bookings from Bokun
- Assign local tour guides to bookings
- Track tour status and updates
- Manage customer information

## Previous Communication:
This issue was previously escalated to your Advanced Technical Team. The API integration is complete and working on our end - we just need the booking channel permissions enabled on your side.

## Testing Confirmation:
I can confirm that:
- Our API credentials are valid and working
- The signature generation is correct
- We can connect to the API successfully
- The only issue is the permission to access booking data

Could you please enable the Booking Channel API access for our account as soon as possible? Our tour management system is ready and waiting to sync with Bokun.

If you need any additional information or would like to see our implementation code, I'm happy to provide it.

Thank you for your urgent attention to this matter.

Best regards,
[Your Name]
[Your Contact Email]
[Your Phone Number]

---

**Note:** Please prioritize this request as our tour operations depend on this integration for the upcoming busy season.