@echo off
chcp 65001 >nul

echo Stopping Astro dev server...

:: Kill all node.exe processes (Astro dev server)
taskkill /F /IM node.exe >nul 2>&1

:: Also try to kill any cmd windows running astro
taskkill /F /FI "WINDOWTITLE eq Astro*" >nul 2>&1

timeout /t 1 /nobreak >nul

echo Astro server stopped.
pause
