@echo off
setlocal EnableExtensions
cd /d "%~dp0"
node "%~dp0scripts\local-dev.mjs" start --fresh
set "RESULT=%ERRORLEVEL%"
if not "%RESULT%"=="0" pause
exit /b %RESULT%
