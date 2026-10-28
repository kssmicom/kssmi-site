@echo off
setlocal EnableExtensions
cd /d "%~dp0"
title KSSMI - Review, Commit and Push main
node "%~dp0scripts\push-main.mjs"
set "RESULT=%ERRORLEVEL%"
echo.
pause
exit /b %RESULT%
