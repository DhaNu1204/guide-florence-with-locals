# 🚀 DATABASE MIGRATION INSTRUCTIONS

**Date**: October 24, 2025
**Purpose**: Add missing columns to production database (language, rescheduling support)

---

## 📋 STEP-BY-STEP GUIDE

### Step 1: Open Migration Tool in Browser

**URL**: https://withlocals.deetech.cc/migrate_production_database.php

Copy and paste this URL into your browser.

---

### Step 2: Enter Secret Key

When the page loads, you'll see an authorization form.

**Secret Key**: `migrate2025`

1. Enter the secret key in the text box
2. Click "Unlock Migration" button

---

### Step 3: Review Changes

You'll see a detailed preview showing:

✅ **Current database status**
✅ **Columns to be added** (6 columns)
✅ **SQL commands** that will be executed
✅ **Estimated time**: Less than 10 seconds

**Columns Being Added**:
1. `language` (VARCHAR(50)) - For language detection
2. `rescheduled` (TINYINT(1)) - Rescheduling flag
3. `original_date` (DATE) - Original tour date
4. `original_time` (TIME) - Original tour time
5. `rescheduled_at` (TIMESTAMP) - When rescheduled
6. `payment_notes` (TEXT) - Payment notes

---

### Step 4: Run Migration

1. Review the SQL commands displayed
2. Click the green "✅ Confirm and Run Migration" button
3. Wait for the migration to complete (~10 seconds)

---

### Step 5: Verify Success

You'll see a success message with:
- ✅ Number of columns added
- ✅ Features now enabled
- ✅ Next steps

**Expected Result**:
```
✅ Migration Completed Successfully!
Added 6 new column(s) to the tours table.
```

---

### Step 6: Test Features

1. Go to **Tours page**: https://withlocals.deetech.cc/tours
2. Look for **language badges** (should now appear)
3. Check that everything still works

---

### Step 7: Clean Up (IMPORTANT)

**After successful migration**, delete the migration file for security:

**Option A - Via SSH**:
```bash
ssh -p 65002 u803853690@82.25.82.111
rm /home/u803853690/domains/deetech.cc/public_html/withlocals/migrate_production_database.php
```

**Option B - Via File Manager**:
1. Login to Hostinger control panel
2. Navigate to: `/public_html/withlocals/`
3. Delete file: `migrate_production_database.php`

---

## ⚠️ SAFETY FEATURES

The migration script includes:

✅ **Authorization** - Requires secret key to access
✅ **Preview Mode** - Shows changes before executing
✅ **Error Handling** - Catches and displays any errors
✅ **Rollback Support** - Can undo changes if needed
✅ **No Data Loss** - Only adds columns, doesn't modify existing data

---

## 🔄 ROLLBACK (If Needed)

If you want to undo the migration:

1. Go to: https://withlocals.deetech.cc/migrate_production_database.php?secret=migrate2025
2. Click "🔄 Rollback (Remove Columns)" button
3. Confirm the rollback
4. All added columns will be removed

---

## ❓ TROUBLESHOOTING

### Issue 1: "Authorization Required"
**Solution**: Make sure you entered the secret key exactly: `migrate2025`

### Issue 2: "Database Connection Failed"
**Solution**: Check that the database credentials in `api/config.php` are correct

### Issue 3: "Migration Completed with Errors"
**Solution**:
- Check the error messages displayed
- Some columns may already exist (this is okay)
- Contact support if errors persist

### Issue 4: Page shows "404 Not Found"
**Solution**:
- Make sure the file was uploaded correctly
- Check URL is exactly: https://withlocals.deetech.cc/migrate_production_database.php

---

## 📊 WHAT GETS ADDED

### Database Changes:
```sql
ALTER TABLE tours ADD COLUMN language VARCHAR(50) DEFAULT NULL;
ALTER TABLE tours ADD COLUMN rescheduled TINYINT(1) DEFAULT 0;
ALTER TABLE tours ADD COLUMN original_date DATE DEFAULT NULL;
ALTER TABLE tours ADD COLUMN original_time TIME DEFAULT NULL;
ALTER TABLE tours ADD COLUMN rescheduled_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE tours ADD COLUMN payment_notes TEXT DEFAULT NULL;

-- Plus indexes for performance:
ALTER TABLE tours ADD KEY idx_language (language);
ALTER TABLE tours ADD KEY idx_rescheduled (rescheduled);
```

### Features Enabled:
1. 🌍 **Language Detection** - Automatic extraction from Bokun API
2. 🔄 **Rescheduling Support** - Track and display rescheduled tours
3. 📝 **Payment Notes** - Additional payment tracking

---

## ✅ QUICK CHECKLIST

- [ ] Step 1: Open migration URL in browser
- [ ] Step 2: Enter secret key: `migrate2025`
- [ ] Step 3: Review changes on preview screen
- [ ] Step 4: Click "Confirm and Run Migration"
- [ ] Step 5: Wait for success message
- [ ] Step 6: Test features on production site
- [ ] Step 7: Delete migration file (security)

---

## 📞 SUPPORT

If you encounter any issues:

1. **Take a screenshot** of the error
2. **Note the exact error message**
3. **Check browser console** (F12) for JavaScript errors
4. **Contact support** with details

---

## 🎯 ESTIMATED TIME

- **Total Time**: 5-10 minutes
- **Migration Execution**: 5-10 seconds
- **Testing**: 2-3 minutes
- **Cleanup**: 1 minute

---

## 🎉 AFTER MIGRATION

Once complete, you'll have:

✅ **Full database parity** between local and production
✅ **Language detection** working on production
✅ **Rescheduling support** enabled
✅ **All features** from local environment available

---

*Instructions created: October 24, 2025*
*Migration Script Location: https://withlocals.deetech.cc/migrate_production_database.php*
