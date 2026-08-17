@echo off
echo ========================================
echo   WGIMS Test Data Cleanup Script
echo ========================================
echo.
echo This will remove all test/sample data from:
echo   - Items / Inventory
echo   - Deliveries
echo   - Subsidies
echo   - Stock Transfers
echo   - Stock Cards
echo   - Suppliers
echo   - Requisitions
echo   - Notifications
echo.
echo PRESERVED (will NOT be deleted):
echo   - All user accounts
echo   - All warehouses
echo   - Database structure
echo   - Migrations
echo.
echo A backup will be created first.
echo.
pause
echo.

echo Creating database backup...
C:\xampp\mysql\bin\mysqldump -u root wgims > backups\wgims_backup_before_cleanup.sql

if %ERRORLEVEL% EQU 0 (
    echo ✅ Backup created: backups\wgims_backup_before_cleanup.sql
) else (
    echo ❌ Backup failed! Aborting cleanup.
    pause
    exit /b 1
)

echo.
echo Proceeding with cleanup...
echo.

php artisan data:cleanup --force

echo.
echo ========================================
echo   Cleanup Complete!
echo ========================================
echo.
pause
