@echo off
echo Creating database backup...
set TIMESTAMP=%date:~-4%%date:~4,2%%date:~7,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set TIMESTAMP=%TIMESTAMP: =0%
set BACKUP_FILE=database_backup_%TIMESTAMP%.sql

cd /d "%~dp0"
C:\xampp\mysql\bin\mysqldump -u root wgims > "backups\%BACKUP_FILE%"

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ✅ Database backup created successfully!
    echo File: backups\%BACKUP_FILE%
    echo.
) else (
    echo.
    echo ❌ Backup failed!
    echo.
)

pause
