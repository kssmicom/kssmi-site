@echo off
chcp 65001 >nul

:: Kill all node.exe processes (Astro dev server)
taskkill /F /IM node.exe >nul 2>&1

:: Also try to kill any cmd windows running astro
taskkill /F /FI "WINDOWTITLE eq Astro*" >nul 2>&1

timeout /t 1 /nobreak >nul

exit 0
