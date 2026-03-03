@echo off
echo Stopping Yeetian Dev Server...
taskkill /f /im node.exe 2>nul
if %errorlevel%==0 (
    echo Server stopped.
) else (
    echo No dev server running.
)
timeout /t 2 /nobreak >nul
