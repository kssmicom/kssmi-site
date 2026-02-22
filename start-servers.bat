@echo off
echo ========================================
echo KSSMI Development Server Starter
echo ========================================
echo.
echo This script starts both:
echo  - Astro dev server (port 4324)
echo  - PHP server for email (port 4325)
echo.
echo ========================================
echo.

REM Check if PHP is available
php -v >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] PHP is not installed or not in PATH!
    echo.
    echo Please install PHP first:
    echo   Option 1: Install XAMPP from https://www.apachefriends.org/
    echo   Option 2: Install PHP from https://windows.php.net/download/
    echo.
    echo After installing, add PHP to your PATH environment variable.
    echo.
    pause
    exit /b 1
)

echo [OK] PHP found:
php -v | findstr /R "PHP"
echo.

REM Check if Node/npm is available
npm -v >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] npm is not installed!
    echo Please install Node.js first.
    pause
    exit /b 1
)

echo [OK] npm found:
npm -v
echo.

REM Check if PHPMailer is installed
if not exist "public\vendor\phpmailer\phpmailer\src\PHPMailer.php" (
    echo [WARNING] PHPMailer not installed!
    echo.
    echo Installing PHPMailer...
    echo.
    cd public
    composer install
    cd ..
    if %errorlevel% neq 0 (
        echo.
        echo [ERROR] Failed to install PHPMailer!
        echo Please run manually: cd public ^&^& composer install
        pause
        exit /b 1
    )
    echo [OK] PHPMailer installed!
    echo.
)

echo ========================================
echo Starting servers...
echo ========================================
echo.
echo Astro:     http://localhost:4324
echo PHP API:   http://localhost:4325/send-mail.php
echo Logs:      http://localhost:4325/email-logs.php
echo.
echo Press Ctrl+C to stop all servers
echo ========================================
echo.

REM Start PHP server in background
start "PHP Server" cmd /c "cd public && php -S localhost:4325"

REM Wait a moment for PHP to start
timeout /t 2 /nobreak >nul

REM Start Astro dev server
npm run dev
