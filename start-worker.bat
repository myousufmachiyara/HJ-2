@echo off
title HJ-2 Import Worker
cd /d C:\xampp\htdocs\HJ-2

echo Starting product-import queue worker...
echo Leave this window OPEN while importing. Close it to stop.
echo.

:loop
C:\xampp\php\php.exe artisan queue:work database --queue=imports --tries=1 --timeout=600 --memory=512 --stop-when-empty
timeout /t 5 /nobreak >nul
goto loop