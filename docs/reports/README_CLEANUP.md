# WGIMS Test Data Cleanup Guide

This guide explains how to clean up test/sample data from the WGIMS system while preserving the database structure, users, and application code.

## Quick Start

### Option 1: Automated Cleanup (Recommended)
Run the automated cleanup script:
```bash
cleanup-test-data.bat
```
This will:
1. Create an automatic database backup
2. Display what will be deleted
3. Remove all test data
4. Show confirmation

### Option 2: Manual Commands

**Step 1:** Create a database backup
```bash
backup-database.bat
```
OR
```bash
C:\xampp\mysql\bin\mysqldump -u root wgims > backups\manual_backup.sql
```

**Step 2:** Preview what will be deleted (dry run)
```bash
php artisan data:cleanup --dry-run
```

**Step 3:** Perform the cleanup
```bash
php artisan data:cleanup
```
OR (skip confirmations)
```bash
php artisan data:cleanup --force
```

**Step 4:** Verify the cleanup
```bash
php verify-cleanup.php
```

---

## What Gets Deleted

### ✓ Transactional Data (Test/Sample Records)
- Stock card entries
- Delivery subsidies and their items
- Deliveries and delivery items
- Requisitions (RIS) and requisition items
- Stock transfers and transfer items
- Items and inventory records
- Item categories
- Suppliers
- System notifications
- Audit logs related to deleted records
- Report snapshots

### ✗ What is PRESERVED
- **All user accounts** (admin, managers, staff, etc.)
- **All employee records**
- **All warehouse configurations**
- **Database structure** (tables, columns, relationships)
- **All migrations**
- **Application code** (controllers, models, views)
- **Roles and permissions**
- **User-warehouse assignments**

---

## Available Commands

### 1. Cleanup Command
```bash
# See what would be deleted without deleting
php artisan data:cleanup --dry-run

# Interactive cleanup with confirmations
php artisan data:cleanup

# Force cleanup without prompts
php artisan data:cleanup --force
```

### 2. Verification Script
```bash
# Verify current database state
php verify-cleanup.php
```

### 3. Backup Scripts
```bash
# Create a database backup
backup-database.bat

# Manual backup
C:\xampp\mysql\bin\mysqldump -u root wgims > backups\backup_YYYYMMDD.sql
```

---

## Restoring from Backup

If you need to restore data from a backup:

```bash
# Stop any running applications first
# Then restore using:
mysql -u root wgims < backups\wgims_backup_before_cleanup.sql
```

**Warning:** This will overwrite your current database with the backup data.

---

## Safety Features

1. **Backup Reminder:** The cleanup command will ask if you've created a backup
2. **Dry Run Mode:** Preview deletions without actually deleting anything
3. **Confirmation Prompts:** Confirms before performing destructive operations
4. **Foreign Key Protection:** Handles relationships properly to avoid orphaned records
5. **Transaction Safety:** Operations are wrapped in safe execution blocks

---

## Troubleshooting

### Issue: "No test data found" but I see data
**Solution:** The command only removes operational data (items, deliveries, etc.). Users and warehouses are intentionally preserved.

### Issue: Backup file is empty or very small
**Solution:** Check that:
- XAMPP MySQL is running
- Database name is correct (`wgims`)
- You have proper permissions

### Issue: Cleanup failed mid-operation
**Solution:** The command will report errors and stop. Your data should remain intact. Check the error message and:
1. Verify database connection
2. Check MySQL is running
3. Verify you have proper permissions

### Issue: Need to clean up specific tables only
**Solution:** Manually use SQL queries:
```sql
-- Example: Clean only suppliers
DELETE FROM suppliers;

-- Example: Clean only items
DELETE FROM items;
```

---

## After Cleanup

Once cleanup is complete, your system will be ready for real data entry:

### Next Steps:
1. ✅ Verify users can log in
2. ✅ Add real suppliers
3. ✅ Create actual items/inventory
4. ✅ Process real deliveries
5. ✅ Create genuine requisitions
6. ✅ Perform actual stock transfers

### Pages to Test:
- `/` - Dashboard
- `/items` - Items management
- `/delivery-subsidies` - Deliveries
- `/requisitions` - RIS management
- `/stock-transfers` - Stock transfers
- `/suppliers` - Suppliers
- `/warehouses` - Warehouses

---

## Backup Schedule Recommendation

For production use, establish a regular backup schedule:

**Daily backups:**
```bash
# Create a scheduled task to run:
backup-database.bat
```

**Before major operations:**
- Before cleanup
- Before system updates
- Before importing large datasets
- Before schema changes

---

## Support

If you encounter any issues with the cleanup process:
1. Check the `CLEANUP_REPORT.md` file for details
2. Review the backup in `backups/` directory
3. Verify database connection in `.env` file
4. Check Laravel logs in `storage/logs/`

---

**Last Updated:** August 13, 2026  
**Version:** 1.0  
**Status:** Production Ready
