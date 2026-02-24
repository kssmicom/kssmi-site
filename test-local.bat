@echo off
chcp 65001 >nul
cd /d "%~dp0"

echo ========================================
echo KSSMI Local Testing Tool (with Search)
echo ========================================
echo.

echo [1/2] Building site to generate Search Index...
call npm run build
if %errorlevel% neq 0 (
    echo.
    echo [ERROR] Build failed. Check the errors above.
    pause
    exit /b %errorlevel%
)

echo.
echo [2/2] Starting Preview Server...
echo ========================================
echo Open http://localhost:4321/ in your browser
echo Press CTRL+C to stop the server when done.
echo ========================================
call npx astro preview --port 4321

pause
