@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title KSSMI - Local Production Build

echo Building this project. Standard output is kssmi-site\dist.
echo Only the build log and incidental temp files go to Kssmi\tep.
echo This does not commit, push or deploy the website.
echo.
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\build-local.ps1"
set "BUILD_RESULT=%ERRORLEVEL%"
echo.
echo Finished with exit code %BUILD_RESULT%.
pause
exit /b %BUILD_RESULT%
