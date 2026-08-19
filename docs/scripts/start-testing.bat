@echo off
echo ========================================
echo   WGIMS Testing Quick Start
echo ========================================
echo.
echo This script will:
echo 1. Clear all Laravel caches
echo 2. Check system requirements
echo 3. Verify database connection
echo 4. List available test routes
echo.
pause
echo.

echo [1/4] Clearing caches...
call php artisan config:clear
call php artisan route:clear
call php artisan view:clear
echo.

echo [2/4] Checking PHP version...
php -v | findstr /i "PHP"
echo.

echo [3/4] Testing database connection...
php artisan migrate:status
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo ❌ MySQL is not running!
    echo.
    echo Please start MySQL from XAMPP Control Panel:
    echo 1. Open XAMPP Control Panel
    echo 2. Click "Start" button next to MySQL
    echo 3. Wait for green "Running" status
    echo 4. Run this script again
    echo.
    pause
    exit /b 1
)
echo ✅ Database connection successful!
echo.

echo [4/4] Available test routes...
echo.
echo KEY ROUTES TO TEST:
echo ------------------
echo Items page (with merging):     http://localhost/wgims/public/items
echo Stock Transfers (with modal): http://localhost/wgims/public/transfers
echo Delivery/Subsidies:            http://localhost/wgims/public/delivery-subsidies
echo Inventory Balance Report:     http://localhost/wgims/public/reports/inventory-balance
echo.

echo ========================================
echo   Ready to Test!
echo ========================================
echo.
echo Next steps:
echo 1. Login to the application
echo 2. Test inventory merging (Items page)
echo 3. Test stock transfer modal
echo 4. Test permissions (if using warehouse manager account)
echo.
echo See docs\qa\TESTING_GUIDE.md for detailed test scenarios.
echo.
pause
