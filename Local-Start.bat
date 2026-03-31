@echo off
chcp 65001 >nul
cd /d "%~dp0"

:: Kill existing node processes (Astro dev server)
taskkill /F /IM node.exe >nul 2>&1

:: Wait briefly for processes to close
timeout /t 1 /nobreak >nul

:: Start Astro dev server hidden using PowerShell
:: Remove '--open' if you don't want browser to open automatically
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command "Start-Process -FilePath 'node' -ArgumentList 'node_modules/astro/astro.js','dev','--port','4321','--open' -WorkingDirectory '%~dp0' -WindowStyle Hidden"

exit 0