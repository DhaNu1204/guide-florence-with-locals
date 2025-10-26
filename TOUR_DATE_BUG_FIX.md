# Tour Date Bug Fix - Deployment Guide

## Issue Description

**Critical Bug**: Tours are displaying under the **booking creation date** instead of the **actual tour/activity date**.

### Example from Production:
- **Booking**: Accademia Gallery Priority Entry Ticket
- **Booked on** (wrong date shown): Tuesday, September 9th, 2025
- **Tour happens** (correct date): Thursday, October 2nd, 2025
- **Current behavior**: Tour shows under September 9 in Priority Tickets page ❌
- **Expected behavior**: Tour should show under October 2 ✅

### Impact:
- Tour guides don't know which tours are on which dates
- Priority Tickets page shows tickets under wrong dates
- Scheduling and guide assignment confusion
- Customer service issues

---

## Root Cause Analysis

### Bug Location
**File**: `public_html/api/BokunAPI.php`
**Function**: `transformBookingToTour()`
**Lines**: 326-329 (before fix)

### The Problem

```php
} elseif (isset($booking['creationDate'])) {
    // Use creation date as fallback for tour date  ← WRONG!
    $date = date('Y-m-d', $booking['creationDate'] / 1000);
}
```

When the code couldn't find proper tour date fields (`startDateTime`, `startTime`, `startDate`), it fell back to using **`creationDate`** which is when the customer **made the booking**, NOT when the tour **happens**.

### Bokun API Data Structure

```json
{
  "creationDate": 1758972997000,  ← When booking was MADE (Sep 9)
  "productBookings": [
    {
      "startDate": 1759795200000,      ← Actual tour date (Oct 2) ✓
      "startDateTime": 1759827600000,   ← Tour date + time ✓
      "fields": {
        "startTimeStr": "09:00"         ← Tour time ✓
      }
    }
  ]
}
```

---

## Fix Applied

### Code Changes

**File**: `public_html/api/BokunAPI.php` (Lines 326-339)

**Before** (WRONG):
```php
} elseif (isset($booking['creationDate'])) {
    // Use creation date as fallback for tour date
    $date = date('Y-m-d', $booking['creationDate'] / 1000);
}
```

**After** (CORRECT):
```php
}

// CRITICAL: Do NOT use creationDate as tour date!
// creationDate is when the booking was MADE, not when the tour happens.
// If no tour date found, log error and skip this booking.
if (!$date) {
    error_log("BokunAPI WARNING: No tour date found for booking " . ($booking['confirmationCode'] ?? 'unknown'));
    error_log("BokunAPI: Available fields: " . json_encode(array_keys($booking)));
    if (isset($productBooking)) {
        error_log("BokunAPI: ProductBooking fields: " . json_encode(array_keys($productBooking)));
    }
    // Set a flag or throw exception - don't import bookings without tour dates
    throw new Exception("No tour date found for booking " . ($booking['confirmationCode'] ?? 'unknown'));
}
```

### What Changed:
1. ✅ Removed incorrect `creationDate` fallback
2. ✅ Added validation to ensure tour date exists
3. ✅ Added detailed error logging for debugging
4. ✅ Throws exception for bookings without tour dates
5. ✅ Prevents future incorrect date imports

---

## Deployment Steps

### Part 1: Deploy Code Fix

#### Step 1: Upload Fixed BokunAPI.php

```bash
# Connect to production server
ssh -p 65002 u803853690@82.25.82.111

# Navigate to API directory
cd /home/u803853690/domains/deetech.cc/public_html/withlocals/api

# Backup existing file
cp BokunAPI.php BokunAPI.php.backup-$(date +%Y%m%d)

# Upload the fixed BokunAPI.php from local:
# public_html/api/BokunAPI.php
# To: /home/u803853690/domains/deetech.cc/public_html/withlocals/api/BokunAPI.php
```

#### Step 2: Upload Date Fix Script

```bash
# Still on production server
# Upload fix_tour_dates.php from local:
# public_html/api/fix_tour_dates.php
# To: /home/u803853690/domains/deetech.cc/public_html/withlocals/api/fix_tour_dates.php
```

### Part 2: Fix Existing Wrong Dates in Production Database

#### Step 3: Run the Fix Script

```bash
# On production server
cd /home/u803853690/domains/deetech.cc/public_html/withlocals/api
php fix_tour_dates.php
```

**Expected Output:**
```
Starting tour date correction process...
=========================================

Found X tours with Bokun data to check

✓ Updated Tour ID 123 (Maria de Lourdes Wilken Bicudo)
  Old: 2025-09-09 09:00
  New: 2025-10-02 09:00

✓ Updated Tour ID 124 (John Doe)
  Old: 2025-09-15 10:00
  New: 2025-10-20 10:00

=========================================
Tour date correction completed!
Total tours checked: 150
Tours updated: 25
Already correct (skipped): 120
Errors: 5
=========================================
```

#### Step 4: Verify the Fix

1. **Check Priority Tickets Page:**
   - Go to: https://withlocals.deetech.cc/priority-tickets
   - Look for the Accademia Gallery booking for Maria de Lourdes Wilken Bicudo
   - Should now show under **October 2, 2025** ✅
   - Should NOT show under September 9, 2025 ❌

2. **Check Database Directly:**
```bash
# On production server
mysql -u u803853690_admin -p u803853690_florence_guides

# Run query
SELECT id, customer_name, date, time, external_id
FROM tours
WHERE customer_name LIKE '%Maria de Lourdes%'
ORDER BY date;
```

**Expected Result:**
```
date: 2025-10-02
time: 09:00:00
```

3. **Check Multiple Bookings:**
```sql
SELECT
    id,
    customer_name,
    date,
    time,
    booking_channel,
    DATE(STR_TO_DATE(JSON_EXTRACT(bokun_data, '$.creationDate')/1000, '%s')) as booked_on
FROM tours
WHERE bokun_data IS NOT NULL
ORDER BY date
LIMIT 20;
```

Verify that `date` column (tour date) is DIFFERENT from `booked_on` (booking creation date).

---

## Testing Checklist

After deployment, test these scenarios:

### Test 1: Existing Tours Display Correctly
- [ ] Priority Tickets page shows tours by actual tour date
- [ ] Tours page shows tours by actual tour date
- [ ] Dashboard upcoming tours are correctly sorted by tour date
- [ ] All GetYourGuide bookings have correct dates

### Test 2: New Sync Works Correctly
- [ ] Run manual Bokun sync from Bokun Integration page
- [ ] Verify new bookings import with correct tour dates
- [ ] Check that no bookings are using creation date

### Test 3: Edge Cases
- [ ] Same-day bookings (booked and toured on same day) work correctly
- [ ] Future bookings (e.g., booked today, tour next month) show correct tour date
- [ ] Rescheduled bookings maintain correct tour date

---

## Rollback Plan

If issues occur after deployment:

### Option 1: Restore Backup File

```bash
# On production server
cd /home/u803853690/domains/deetech.cc/public_html/withlocals/api
cp BokunAPI.php.backup-YYYYMMDD BokunAPI.php
```

### Option 2: Revert Database Changes

**NOTE**: Only use if the fix script caused data corruption

```bash
# On production server
# Create backup first
mysqldump -u u803853690_admin -p u803853690_florence_guides tours > tours_backup_before_rollback.sql

# Then manually restore affected tours using the backed up bokun_data
# This should only be needed in extreme cases
```

---

## Files Modified

### Production Files to Update:
1. `/home/u803853690/domains/deetech.cc/public_html/withlocals/api/BokunAPI.php`
2. `/home/u803853690/domains/deetech.cc/public_html/withlocals/api/fix_tour_dates.php` (NEW)

### Local Files Changed:
1. `public_html/api/BokunAPI.php` (Lines 326-339)
2. `public_html/api/fix_tour_dates.php` (NEW)
3. `docs/CHANGELOG.md` (to be updated)

---

## Documentation Updates

After successful deployment, update:

1. **CHANGELOG.md** - Add entry for tour date bug fix
2. **TROUBLESHOOTING.md** - Add section about date discrepancies
3. **PROJECT_STATUS.md** - Mark this fix as completed

---

## Expected Results

### Before Fix ❌
- **Priority Tickets page**: Tours sorted by booking creation date
- **Example**: October 2 tour shows under September 9
- **Guide assignment**: Confusion about which tours are on which days

### After Fix ✅
- **Priority Tickets page**: Tours sorted by actual tour date
- **Example**: October 2 tour shows under October 2
- **Guide assignment**: Clear view of tours by actual date
- **Better UX**: Guides know exactly which tours they have on which days

---

## Support

If issues arise:
1. Check error logs: `/home/u803853690/domains/deetech.cc/public_html/withlocals/api/error.log`
2. Review fix script output
3. Verify database schema matches expected structure
4. Contact: Review TROUBLESHOOTING.md

---

**Date Fixed**: October 26, 2025
**Fixed By**: Claude Code
**Issue**: Tour dates showing booking creation date instead of tour date
**Status**: ✅ Ready for deployment
**Priority**: 🔴 CRITICAL - Affects tour scheduling and guide assignments
