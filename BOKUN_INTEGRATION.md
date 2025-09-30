# Bokun API Integration Documentation

## Overview
This integration allows automatic synchronization of tour bookings from your Bokun account to the Florence with Locals guide management system. It eliminates manual data entry and enables automatic guide assignment based on availability.

## Features

### 🔄 Real-time Synchronization
- **Webhook Support**: Receives instant updates when bookings are created, updated, or cancelled
- **Manual Sync**: On-demand synchronization for specific date ranges
- **Automatic Updates**: Keeps tour data synchronized across both systems

### 🤖 Automatic Guide Assignment
- **Smart Matching**: Assigns guides based on availability, language, and tour type
- **Capacity Management**: Tracks guide availability and maximum tours per day
- **Manual Override**: Allows manual reassignment when needed

### 📊 Data Mapping
- **Tour Mapping**: Maps Bokun products to local tour types
- **Customer Information**: Syncs customer details for each booking
- **Participant Tracking**: Maintains accurate participant counts

## Setup Instructions

### 1. Database Setup
Run the SQL migration to add Bokun integration tables:
```bash
mysql -u your_user -p your_database < bokun_integration.sql
```

### 2. Bokun API Credentials
1. Log into your Bokun account
2. Navigate to Settings → API Keys
3. Create a new API key with the following permissions:
   - Read bookings
   - Read products/activities
   - Read customers
4. Copy the Access Key and Secret Key

### 3. Configure Webhook URL
In your Bokun account:
1. Go to Settings → Webhooks
2. Add a new webhook with URL: `https://yourdomain.com/api/bokun_webhook.php`
3. Select events:
   - bookings/create
   - bookings/update
   - bookings/cancel
   - experiences/availability_update

### 4. System Configuration
1. Log into the Florence with Locals admin panel
2. Navigate to Tours page
3. Click "Configure" in the Bokun Integration section
4. Enter your API credentials:
   - Access Key
   - Secret Key
   - Vendor ID
5. Enable sync and optionally enable auto-assign guides
6. Save configuration

## Usage Guide

### Manual Synchronization
1. Go to Tours page
2. In the Bokun Integration section, click "Sync Now"
3. System will fetch bookings for the next 7 days
4. New bookings appear in the "Unassigned Bokun Tours" table

### Guide Assignment

#### Automatic Assignment
When enabled, the system will:
1. Check guide availability for the tour date/time
2. Match guides based on:
   - Language requirements
   - Tour type expertise
   - Current workload
3. Assign the most suitable available guide

#### Manual Assignment
1. View unassigned tours in the Bokun Integration panel
2. Click "Auto-Assign" to use smart assignment
3. Or manually edit the tour to assign a specific guide

### Guide Availability Management
Set guide availability:
```sql
-- Mark guide as available for specific date
INSERT INTO guide_availability (guide_id, date, time_slot, available, max_tours)
VALUES (1, '2025-01-15', '09:00', 1, 2);

-- Set weekly availability pattern
INSERT INTO guide_availability (guide_id, date, available, max_tours)
SELECT 1, DATE_ADD(CURDATE(), INTERVAL n DAY), 1, 2
FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 
      UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) numbers
WHERE DAYOFWEEK(DATE_ADD(CURDATE(), INTERVAL n DAY)) NOT IN (1, 7); -- Exclude weekends
```

## API Endpoints

### Webhook Endpoint
`POST /api/bokun_webhook.php`
- Receives real-time updates from Bokun
- Automatically creates/updates tours
- Logs all webhook events for debugging

### Sync Endpoint
`POST /api/bokun_sync.php?action=sync`
```json
{
  "start_date": "2025-01-01",
  "end_date": "2025-01-07"
}
```

### Configuration Endpoint
`GET /api/bokun_sync.php?action=config`
- Returns current configuration

`POST /api/bokun_sync.php?action=config`
```json
{
  "access_key": "your_access_key",
  "secret_key": "your_secret_key",
  "vendor_id": "your_vendor_id",
  "sync_enabled": true,
  "auto_assign_guides": true
}
```

### Auto-Assignment Endpoint
`POST /api/bokun_sync.php?action=auto-assign`
```json
{
  "tour_id": 123
}
```

## Data Structure

### Tours Table Extensions
New fields added to support Bokun:
- `external_id`: Bokun booking ID
- `external_source`: Set to 'bokun'
- `bokun_booking_id`: Full Bokun booking reference
- `bokun_experience_id`: Bokun product/experience ID
- `bokun_confirmation_code`: Customer confirmation code
- `participants`: Number of participants
- `customer_name`: Main customer name
- `customer_email`: Customer email
- `customer_phone`: Customer phone
- `needs_guide_assignment`: Flag for pending assignment
- `bokun_data`: Full JSON data from Bokun
- `last_synced`: Last synchronization timestamp

### Mapping Table
`bokun_tour_mapping` table structure:
- Maps Bokun products to local tour types
- Sets default guide assignments
- Defines auto-assignment rules

## Troubleshooting

### Common Issues

1. **Sync not working**
   - Verify API credentials are correct
   - Check `bokun_webhook_logs` table for errors
   - Ensure webhook URL is accessible from Bokun

2. **Tours not appearing**
   - Check date range in sync request
   - Verify tours exist in Bokun for selected dates
   - Review `bokun_webhook_logs` for processing errors

3. **Guide assignment failing**
   - Ensure guides have availability set
   - Check `guide_availability` table
   - Verify guide capacity limits

### Debug Mode
Enable detailed logging:
```php
// In bokun_webhook.php, add at top:
define('BOKUN_DEBUG', true);
```

### Webhook Testing
Test webhook manually:
```bash
curl -X POST https://yourdomain.com/api/bokun_webhook.php \
  -H "x-bokun-topic: bookings/create" \
  -H "x-bokun-booking-id: TEST123" \
  -H "Content-Type: application/json" \
  -d '{"timestamp":"2025-01-01T10:00:00","bookingId":"TEST123"}'
```

## Security Considerations

1. **API Credentials**: Store securely, never commit to version control
2. **Webhook Validation**: Consider implementing signature verification
3. **Rate Limiting**: Bokun API has rate limits (400 req/min)
4. **Data Privacy**: Customer data is stored locally - ensure GDPR compliance

## Future Enhancements

Planned features:
- Two-way sync (update Bokun from local changes)
- Advanced assignment rules (skill matching, preferences)
- Automated customer communications
- Performance metrics and reporting
- Multi-language tour matching
- Conflict resolution for double bookings

## Support

For issues or questions:
1. Check webhook logs: `SELECT * FROM bokun_webhook_logs ORDER BY created_at DESC`
2. Review sync status: Check `last_sync_date` in `bokun_config`
3. Contact support with error messages from logs

## Version History

- **v1.0.0** (2025-01-29): Initial Bokun integration
  - Webhook support
  - Manual sync
  - Auto-assignment
  - Basic mapping