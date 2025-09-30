# Bokun Integration Testing Guide

## Overview
This guide will help you test the Bokun API integration in your Florence Guides system using Bokun's test environment.

## Prerequisites
- Your Florence Guides application is running locally
- You have access to your Bokun account
- You need Bokun test environment credentials

## Step 1: Get Test Environment Credentials

### From Your Bokun Account:
1. **Login to Bokun**: Go to your Bokun admin panel
2. **Navigate to Settings**: Look for API or Integration settings
3. **Create Test API Key**: Generate API credentials for testing
4. **Note the Test Environment**: Use `https://api.bokuntest.com` as base URL

### Required Information:
- **Test Access Key**: Your test API identifier
- **Test Secret Key**: Your test private key for signatures
- **Vendor ID**: Your unique vendor identifier
- **Test Base URL**: `https://api.bokuntest.com`

## Step 2: Configure Test Environment

### In Your Florence Guides System:
1. **Open the application**: Go to `http://localhost:5173`
2. **Login** with your credentials
3. **Navigate to Tours page**
4. **Find Bokun Integration section**
5. **Click "Configure"**

### Enter Test Credentials:
```
Access Key: [Your test access key]
Secret Key: [Your test secret key]  
Vendor ID: [Your vendor ID]
☑ Enable Sync
☐ Auto-Assign Guides (optional for testing)
```

6. **Click "Save Configuration"**

## Step 3: Test the Connection

### Test API Connection:
1. **Click "Test Connection"** button
2. **Expected Results**:
   - ✅ Success: "Bokun API connection successful!"
   - ❌ Failure: Shows specific error message

### Common Test Issues:
- **Invalid Signature**: Check your access key and secret key
- **401 Unauthorized**: Verify credentials are for test environment
- **Network Error**: Check internet connection

## Step 4: Test Data Sync

### Manual Sync Test:
1. **Click "Sync Now"** button
2. **Monitor the response**:
   - Shows number of bookings synced
   - Lists any errors encountered
   - Updates the unassigned tours list

### Expected Test Data:
- Test bookings from Bokun test environment
- Tours appear in "Unassigned Bokun Tours" section
- Customer information populated correctly

## Step 5: Test Guide Assignment

### Auto-Assignment Test:
1. **View unassigned tours list**
2. **Click "Auto-Assign"** for a tour
3. **Expected Results**:
   - Guide assigned automatically
   - Tour removed from unassigned list
   - Success message displayed

## Step 6: Verify Database Updates

### Check Database Tables:
```sql
-- Check synced tours
SELECT * FROM tours WHERE external_source = 'bokun';

-- Check guide assignments
SELECT t.title, t.date, t.time, g.name as guide_name 
FROM tours t 
LEFT JOIN guides g ON t.guide_id = g.id 
WHERE t.external_source = 'bokun';

-- Check Bokun configuration
SELECT * FROM bokun_config;
```

## Step 7: Test Webhook (Optional)

### Webhook Endpoint:
Your webhook URL should be set to:
```
https://yourdomain.com/api/bokun_webhook.php
```

### For Local Testing:
Use a tool like ngrok to expose your local server:
```bash
# Install ngrok (one time)
npm install -g ngrok

# Expose local PHP server
ngrok http 8080

# Use the provided HTTPS URL in Bokun webhook settings
```

## Expected Test Results

### ✅ Successful Test Scenario:
1. **Connection Test**: Returns success message
2. **Data Sync**: Retrieves test bookings from Bokun
3. **Database Storage**: Tours saved with Bokun data
4. **Guide Assignment**: Guides assigned based on availability
5. **UI Updates**: Interface shows current status

### ❌ Common Test Failures:

#### Authentication Errors:
```
HTTP Error 401: Invalid signature
```
**Solution**: Verify access key, secret key, and signature generation

#### API Limit Errors:
```
Rate limit exceeded. Please wait 60 seconds.
```
**Solution**: Wait and retry (built-in retry logic handles this)

#### Data Format Errors:
```
Error processing booking: Invalid date format
```
**Solution**: Check booking data format from Bokun test environment

## Step 8: Production Readiness Checklist

Before using production credentials:

- [ ] ✅ Test environment connection successful
- [ ] ✅ Data sync working correctly
- [ ] ✅ Guide assignment logic functional
- [ ] ✅ Error handling working properly
- [ ] ✅ Rate limiting respected
- [ ] ✅ Database updates verified
- [ ] ✅ UI responsive and informative

## Troubleshooting

### Debug Information:
1. **Check browser console** for JavaScript errors
2. **Check PHP error logs** for server-side issues
3. **Verify database connectivity**
4. **Test API endpoints individually**

### Log Files to Check:
- Browser Developer Tools > Console
- PHP Error Logs
- MySQL Error Logs
- Bokun webhook logs (if implemented)

### Support Resources:
- **Bokun Test API Documentation**: `https://api-docs.bokun.dev/rest-v1`
- **Bokun Developer Portal**: `https://bokun.dev/`
- **Test API Base URL**: `https://api.bokuntest.com`

---

## Next Steps

Once testing is successful:
1. **Get production credentials** from Bokun
2. **Update configuration** with production keys
3. **Change base URL** to `https://api.bokun.is`
4. **Set up production webhooks**
5. **Monitor initial production sync**

**Important**: Always test thoroughly in the test environment before switching to production!