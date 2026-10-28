@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title KSSMI - Review Local Cleanup
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\cleanup-local.ps1"
set "RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %RESULT%
