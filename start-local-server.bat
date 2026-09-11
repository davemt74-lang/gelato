@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>&1
if errorlevel 1 (
  echo PHP was not found. Install PHP 8.2+ with the PDO MySQL extension, or deploy this folder to a PHP web server.
  pause
  exit /b 1
)
start "" http://127.0.0.1:8080/setup-first-user.php
php -S 127.0.0.1:8080
endlocal
