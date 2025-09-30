# Bokun API Configuration Guide

## Overview
Based on the official Bokun documentation, here's how to properly configure the Bokun API for your Florence Guides system.

## 1. Getting Bokun API Credentials

### Step 1: Access Bokun Platform
1. **Login to Bokun**: Go to your Bokun admin panel
2. **Navigate to Settings**: Look for API or Integration settings
3. **Create API Key**: Generate a new API key set

### Step 2: API Key Components
You'll receive:
- **Access Key**: Your public API identifier (e.g., `de235a6a15c340b6b1e1cb5f3687d04a`)
- **Secret Key**: Your private key for signature generation (e.g., `23e2c7da7f7048e5b46f96bc91324800`)
- **Vendor ID**: Your unique vendor identifier

### Step 3: Set Appropriate Permissions
Ensure your API key has the following scopes:
- `BOOKINGS_READ` - To read booking information
- `BOOKINGS_WRITE` - To update booking statuses
- `PRODUCTS_READ` - To read your tour products
- `LEGACY_API` - For REST API access

## 2. Understanding Bokun Authentication

### Authentication Method
Bokun uses **HMAC-SHA1 signature authentication** with these headers:

```http
X-Bokun-Date: 2025-01-29 10:00:00
X-Bokun-AccessKey: your_access_key_here
X-Bokun-Signature: generated_signature_here
Content-Type: application/json;charset=UTF-8
```

### Signature Calculation Process
1. **Create concatenated string**: `Date + AccessKey + HTTPMethod + Path`
2. **Generate HMAC-SHA1**: Using your secret key
3. **Base64 encode**: The resulting hash

**Example**:
```
String to sign: 2013-11-09 14:33:46de235a6a15c340b6b1e1cb5f3687d04aPOST/activity.json/search?lang=EN&currency=ISK
Resulting Signature: XrOiTYa9Y34zscnLCsAEh8ieoyo=
```

## 3. Bokun API Endpoints

### Base URLs
- **Production**: `https://api.bokun.is`
- **Test Environment**: `https://api.bokuntest.com`
- **API Documentation**: `https://api-docs.bokun.dev/rest-v1`

### Key Endpoints for Tour Management

#### Get Activities/Products
```http
POST /activity.json/search
Content-Type: application/json

{
  "page": 1,
  "pageSize": 20
}
```

#### Get Bookings
```http
GET /booking.json/search?start=2025-01-29&end=2025-02-05
```

#### Get Activity Availability
```http
GET /activity.json/{id}/availabilities?start=2025-01-29&end=2025-02-05&currency=EUR
```

#### Get Booking Details
```http
GET /booking.json/{bookingId}
```

## 4. Rate Limiting
- **Maximum**: 400 requests per minute per vendor
- **Response on limit**: HTTP 429 with `Retry-After` header
- **Exception**: Reservation/confirmation calls are not throttled

## 5. Webhooks Configuration

### Webhook Events to Subscribe
- `bookings/create` - New booking created
- `bookings/update` - Booking modified
- `bookings/cancel` - Booking cancelled
- `experiences/availability_update` - Product availability changed

### Webhook URL
Set your webhook endpoint to:
```
https://yourdomain.com/api/bokun_webhook.php
```

### Webhook Authentication
Bokun includes these headers with each webhook:
- `x-bokun-apikey` - Your API key
- `x-bokun-hmac` - HMAC signature for validation
- `x-bokun-topic` - Event type
- `x-bokun-vendor-id` - Your vendor ID

## 6. Testing Your Configuration

### Test Endpoints
Use Bokun's test environment first:
- **Test API**: `https://api.bokuntest.com`
- **Swagger UI**: `https://api-docs.bokun.dev/rest-v1`

### Sample Test Request
```bash
curl -X POST "https://api.bokuntest.com/activity.json/search" \
  -H "X-Bokun-Date: 2025-01-29 10:00:00" \
  -H "X-Bokun-AccessKey: your_test_access_key" \
  -H "X-Bokun-Signature: calculated_signature" \
  -H "Content-Type: application/json;charset=UTF-8" \
  -d '{"page": 1, "pageSize": 5}'
```

## 7. Common Configuration Issues

### Invalid Signature
- Check your concatenation order: Date + AccessKey + Method + Path
- Ensure UTC timezone for X-Bokun-Date
- Verify HMAC-SHA1 algorithm and Base64 encoding

### Permissions Error
- Verify API key has required scopes
- Check if API key is active and not expired

### Rate Limiting
- Implement proper retry logic with exponential backoff
- Cache frequently accessed data
- Use webhooks instead of polling when possible

## 8. Implementation Checklist

- [ ] Obtain API credentials from Bokun
- [ ] Test credentials with simple API call
- [ ] Implement HMAC signature generation
- [ ] Set up webhook endpoint
- [ ] Configure webhook subscriptions in Bokun
- [ ] Test webhook reception
- [ ] Implement rate limiting and retry logic
- [ ] Set up error handling and logging

## 9. Next Steps

Once you have your Bokun credentials:
1. **Enter them in the Florence Guides system**:
   - Go to Tours page → Bokun Integration → Configure
   - Enter Access Key, Secret Key, and Vendor ID
   
2. **Test the integration**:
   - Click "Sync Now" to test API connection
   - Check for any unassigned tours
   - Test webhook reception

## 10. Support Resources

- **Bokun Developer Docs**: https://bokun.dev/
- **API Reference**: https://api-docs.bokun.dev/
- **Support**: Contact Bokun support for API key issues

---

**Important**: Always test with Bokun's test environment before using production credentials!