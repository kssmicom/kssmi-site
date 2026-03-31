@echo off
chcp 65001 >nul
cd /d "%~dp0"

:: Kill existing Astro processes
taskkill /F /IM node.exe >nul 2>&1
timeout /t 1 /nobreak >nul

:: Clear Astro cache for fresh build
echo Clearing Astro cache for fresh build...
if exist ".astro" (
    rmdir /s /q ".astro" 2>nul
    timeout /t 1 /nobreak >nul
    rmdir /s /q ".astro" 2>nul
)

:: Start Astro dev server with auto-open
start "Astro Dev Server" cmd /c "cd /d %~dp0 && npx astro dev --port 4321 --open"

exit 0
