@echo off
chcp 65001 >nul
cd /d "%~dp0"

:: Kill existing Astro processes
taskkill /F /IM node.exe >nul 2>&1
timeout /t 1 /nobreak >nul

:: Start Astro dev server
:: Use "npm run dev" (reads from node_modules/.bin/astro directly - no npx registry check)
start "Astro Dev Server" cmd /c "cd /d %~dp0 && npm run dev"

:: Poll port 4321 every 2 seconds until the server responds, then open browser
:wait_loop
timeout /t 2 /nobreak >nul
curl -s --max-time 1 http://localhost:4321 >nul 2>&1
if %errorlevel% neq 0 goto wait_loop
start http://localhost:4321

exit 0