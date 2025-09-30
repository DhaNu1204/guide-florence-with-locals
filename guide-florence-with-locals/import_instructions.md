# Fix Time Column Issue & Import Tickets

## Problem
Your tickets table is missing the `time` column, which is why you got the error:
```
#1054 - Unknown column 'time' in 'INSERT INTO'
```

## Solution - Choose ONE of these options:

### 🎯 **RECOMMENDED: Option 1 - Fixed PHP Web Script**

**Simply visit:** `https://guide.nextaudioguides.com/import_tickets_fixed.php`

This script will:
1. ✅ Automatically add the missing `time` column
2. ✅ Import all 47 tickets with proper error handling
3. ✅ Skip duplicates if you run it twice
4. ✅ Show you the updated table structure
5. ✅ Provide detailed progress feedback

**After running, delete the file for security!**

---

### 🔧 **Option 2 - Manual SQL (Advanced Users)**

**Step 1:** Run this SQL to add the time column:
```sql
ALTER TABLE tickets ADD COLUMN time TIME DEFAULT NULL AFTER date;
```

**Step 2:** Then run the import SQL from `import_tickets_fixed.sql`

---

### 📊 **Option 3 - CSV Import (Alternative)**

1. First add the time column using the SQL above
2. Use phpMyAdmin's Import feature with `tickets_import.csv`

---

## After Import

Once successful, you should see:
- **47 new tickets** for Accademia Gallery
- **Time column** added to your table
- **Tickets showing with time** in your app

Your table structure will be:
```
id | location | code | date | time | quantity | created_at | updated_at
```

## Files Created

- `add_time_column.sql` - Just adds the time column
- `import_tickets_fixed.sql` - Complete SQL import
- `import_tickets_fixed.php` - Web-based import (RECOMMENDED)
- `tickets_import.csv` - CSV format for manual import

**Note:** All ticket codes start with "181" so you can easily identify them. 