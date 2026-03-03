@echo off
chcp 65001 >nul
cd /d "%~dp0"

:: Clean cache
if exist "node_modules\.astro" rmdir /s /q "node_modules\.astro"

:: Start dev server completely hidden (no window)
powershell -WindowStyle Hidden -Command "Start-Process npm -ArgumentList 'run','dev' -WindowStyle Hidden -WorkingDirectory '%~dp0'"

:: Wait for server to start, then open browser
timeout /t 3 /nobreak >nul
start http://localhost:4321/
