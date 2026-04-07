@echo off
chcp 65001 >nul
cd /d "%~dp0"

:: Kill existing Astro processes
taskkill /F /IM node.exe >nul 2>&1
timeout /t 1 /nobreak >nul

:: Start Astro dev server (same proven pattern as Local-Start-Fresh.bat)
:: /min = minimized window, does not appear in taskbar prominently
start /min "Astro Dev Server" cmd /c "cd /d %~dp0 && npx astro dev --port 4321 --open"

exit 0