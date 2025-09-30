# Bokun Support Request - API Key Booking Channel Permissions

## Email Draft for Bokun Support

**Subject:** API Key Configuration - Enable Booking Channel Access

---

**Dear Bokun Support Team,**

I hope this message finds you well. I am reaching out regarding API integration for my Florence tour business.

### **Business Information:**
- **Company:** Florence with Locals Guide Services
- **Vendor ID:** 96929
- **Business Type:** Tour guide services in Florence, Italy
- **Website:** [Your website URL if applicable]

### **Issue Description:**
I am implementing API integration to automatically sync bookings from Bokun to my internal tour management system. I have successfully set up the API integration and can connect to your endpoints, but I'm receiving the following error when testing:

```
HTTP Error 400: This API key has no booking channel.
```

### **Current API Key Configuration:**
- I have generated API keys through my Bokun admin panel
- My integration is working correctly (successful authentication and communication with your API)
- I can access the API endpoints, but they return the "no booking channel" error

### **Request:**
Could you please help me with the following:

1. **Enable booking channel access** for my API key
2. **Confirm the required permissions** for my use case:
   - Reading booking information (`BOOKINGS_READ`)
   - Updating booking statuses (`BOOKINGS_WRITE`) 
   - Reading product/activity data (`PRODUCTS_READ`)
   - API access (`LEGACY_API`)

3. **Provide guidance** on any additional setup or approval process required

### **Technical Details:**
- **Integration Type:** REST API using HMAC-SHA1 authentication
- **Use Case:** Automatic synchronization of bookings to internal guide assignment system
- **Environment:** Currently testing with test API credentials, will move to production once configured

### **Urgency:**
This integration is important for streamlining our booking operations and improving customer service. Any guidance you can provide would be greatly appreciated.

### **Contact Information:**
- **Primary Contact:** [Your Name]
- **Email:** [Your Email]
- **Phone:** [Your Phone Number]
- **Preferred Contact Method:** Email

Thank you for your time and assistance. I look forward to hearing from you soon.

Best regards,

**[Your Name]**  
**[Your Title]**  
Florence with Locals Guide Services  
**Email:** [Your Email]  
**Phone:** [Your Phone Number]

---

### **Additional Information to Include if Asked:**

**Technical Implementation Details:**
- Using PHP 8.2 with cURL for API requests
- Implementing proper HMAC-SHA1 signature authentication as per your documentation
- Following rate limiting guidelines (400 requests/minute)
- Planning to implement webhook endpoints for real-time booking updates

**Business Context:**
- We provide guided tours in Florence, Italy
- Need to automatically assign local guides to incoming Bokun bookings
- Integration will improve efficiency and reduce manual booking management
- Committed to following all Bokun API best practices and guidelines

---

**Note:** Please customize the placeholders [Your Name], [Your Email], etc. with your actual information before sending.